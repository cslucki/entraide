<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Dossier;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TASK-1693, bug AF — les surfaces IA suivent l'interrupteur.
 *
 * ## Le defaut
 *
 * Une Organization dont le SuperAdmin a decoche « Activer les fonctions IA »
 * continuait d'offrir : l'etape « Creer mon agent IA » du tableau de bord,
 * l'aide a reformuler sur Proposer et Demander de l'aide, la generation
 * d'article, la carte « Questionner un passage » et « Generer la synthese »
 * d'un Dossier.
 *
 * Le serveur REFUSAIT deja — `ProviderResolver` leve avant tout appel. Aucune
 * capacite ne fuyait : c'etaient des poignees posees sur une porte muree. Mais
 * une poignee qui ne mene nulle part est un defaut a part entiere, le meme que
 * le bug K ou « Rejoindre » menait a un 403.
 *
 * ## L'autorite
 *
 * `ProviderResolver::isAvailableFor()` repond « `resolve()` aboutirait-il ? »
 * en lisant la MEME liste de conditions que la garde qui refuse — pas une
 * copie. Lire `is_enabled` a la main laisserait visibles les surfaces d'une
 * Organization activee mais SANS credential : le defaut deplace d'un cran.
 * C'est le raisonnement deja tenu par `AiFabContext` (TASK-1685) pour le FAB
 * et le Shell ; `AiSurfaceGate` l'etend aux ecrans que cette passe n'avait pas
 * couverts.
 *
 * ## La forme de ces tests
 *
 * Chaque surface est mesuree DANS LES DEUX SENS : absente quand l'IA est
 * eteinte, presente quand elle est allumee. Un test qui ne verifierait que
 * l'absence resterait vert si la surface disparaissait pour tout le monde.
 */
class TASK1693AiSurfacesFollowTheSwitchTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $membre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1693af',
            'is_active' => true,
            'is_public' => true,
            'ai_profiles_enabled' => true,
        ]);

        $this->membre = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
            'points_balance' => 100,
        ]);

        app()->instance('current_organization', $this->organization);

        Http::preventStrayRequests();
        Http::fake();
    }

    /** L'IA devient utilisable : active, avec fournisseur, modele et credential. */
    private function allumerLIA(): void
    {
        OrganizationAiSetting::query()->updateOrCreate(
            ['organization_id' => $this->organization->id],
            ['is_enabled' => true, 'provider' => 'openai', 'model' => 'gpt-4o-mini', 'api_key' => 'sk-test'],
        );

        // Le service memorise sa reponse par Organization : une instance
        // fraiche, sinon le test mesurerait la reponse d'avant.
        app()->forgetInstance(\App\Support\Ai\AiSurfaceGate::class);
    }

    private function page(string $url): string
    {
        return $this->actingAs($this->membre)->get($url)->assertOk()->getContent();
    }

    private function urlOrg(string $route, array $params = []): string
    {
        return route($route, array_merge(['organization' => $this->organization->slug], $params));
    }

    /**
     * @return array<string, array{0: string, 1: string}> marqueur => [route, description]
     */
    public static function surfaces(): array
    {
        return [
            'reformuler une proposition d\'aide' => ['data-ai-formulate-block', 'organization.services.create'],
            'reformuler une demande d\'aide' => ['data-ai-formulate-block', 'organization.requests.create'],
            'generer un article' => ['data-ai-generate-button', 'organization.blog.create'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('surfaces')]
    public function test_a_surface_is_absent_when_the_switch_is_off(string $marqueur, string $route): void
    {
        $this->assertStringNotContainsString($marqueur, $this->page($this->urlOrg($route)),
            'le serveur refuserait ce geste : il ne doit pas etre offert');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('surfaces')]
    public function test_the_same_surface_is_present_when_the_switch_is_on(string $marqueur, string $route): void
    {
        $this->allumerLIA();

        $this->assertStringContainsString($marqueur, $this->page($this->urlOrg($route)),
            'sans ce sens, le test resterait vert si la surface disparaissait pour tout le monde');
    }

    // ── L'etape d'accueil ────────────────────────────────────────────────────

    /**
     * LE cas signale : « Creer mon agent IA » ne lisait que `ai_profiles_enabled`.
     *
     * Elle ignorait « Activer les fonctions IA pour cette organisation ».
     */
    public function test_the_onboarding_step_falls_with_the_general_switch(): void
    {
        $html = $this->page($this->urlOrg('organization.dashboard'));

        $this->assertStringContainsString(e(__('dashboard.ai_profile_disabled_admin')), $html,
            'l\'etape doit se dire indisponible, pas inviter a un geste refuse');
    }

    /** Et elle revient quand l'IA est allumee. */
    public function test_the_onboarding_step_comes_back_with_the_switch(): void
    {
        $this->allumerLIA();

        $this->assertStringNotContainsString(
            e(__('dashboard.ai_profile_disabled_admin')),
            $this->page($this->urlOrg('organization.dashboard')),
        );
    }

    /**
     * Les deux conditions sont NECESSAIRES, et le reglage produit garde son mot.
     *
     * IA allumee mais « Agents profil IA » decoche : l'etape reste
     * indisponible. Sans ce test, on pourrait croire que le correctif a
     * remplace une condition par l'autre au lieu de les additionner.
     */
    public function test_the_product_setting_still_has_its_say(): void
    {
        $this->allumerLIA();
        $this->organization->forceFill(['ai_profiles_enabled' => false])->save();
        app()->instance('current_organization', $this->organization->fresh());

        $this->assertStringContainsString(
            e(__('dashboard.ai_profile_disabled_admin')),
            $this->page($this->urlOrg('organization.dashboard')),
        );
    }

    // ── Le Dossier ───────────────────────────────────────────────────────────

    public function test_the_dossier_synthesis_follows_the_switch(): void
    {
        $dossier = $this->unDossier();

        $eteinte = $this->page($this->urlOrg('organization.dossiers.show', ['dossier' => $dossier]));
        $this->assertStringNotContainsString('data-ai-insights-block', $eteinte);

        $this->allumerLIA();

        $allumee = $this->page($this->urlOrg('organization.dossiers.show', ['dossier' => $dossier]));
        $this->assertStringContainsString('data-ai-insights-block', $allumee);
    }

    /**
     * Ce que la garde ne doit PAS emporter.
     *
     * Le depot de documents et la liste ne dependent pas de l'IA : les masquer
     * priverait le Dossier de sa raison d'etre.
     */
    public function test_the_rest_of_the_dossier_is_untouched(): void
    {
        $dossier = $this->unDossier();

        $html = $this->page($this->urlOrg('organization.dossiers.show', ['dossier' => $dossier]));

        // Viser la forme ECHAPPEE (lecon TASK-1672) : Blade rend l'apostrophe
        // en `&#039;`, et les titres tires par la fabrique en portent.
        $this->assertStringContainsString(e($dossier->title), $html);
        $this->assertStringNotContainsString('data-ai-insights-block', $html);
    }

    private function unDossier(): Dossier
    {
        $loop = \App\Models\Loop::factory()->create([
            'organization_id' => $this->organization->id,
            'visibility' => 'public',
            'status' => 'active',
        ]);

        \App\Models\LoopMember::query()->create([
            'loop_id' => $loop->id,
            'user_id' => $this->membre->id,
            'organization_id' => $this->organization->id,
            'role' => 'owner',
            'status' => 'active',
        ]);

        return Dossier::factory()->create([
            'organization_id' => $this->organization->id,
            'loop_id' => $loop->id,
        ]);
    }

    /**
     * La garde ne touche AUCUNE surface non-IA.
     *
     * « Ecrire moi-meme » et « Annuler » restent sur la page de redaction :
     * sans eux elle perdrait son objet.
     */
    public function test_the_non_ai_actions_remain(): void
    {
        $html = $this->page($this->urlOrg('organization.blog.create'));

        $this->assertStringContainsString(e(__('blog.btn_write_myself')), $html);
        $this->assertStringContainsString(e(__('blog.btn_cancel')), $html);
        $this->assertStringNotContainsString('data-ai-generate-button', $html);
    }

    /** Et le formulaire lui-meme reste utilisable sans l'IA. */
    public function test_the_service_form_still_works_without_ai(): void
    {
        $html = $this->page($this->urlOrg('organization.services.create'));

        $this->assertStringNotContainsString('data-ai-formulate-block', $html);
        $this->assertStringContainsString('name="title"', $html);
        $this->assertStringContainsString('name="description"', $html);
    }

    /**
     * Le drapeau SEUL ne suffit pas : une Organization activee mais SANS
     * credential ne doit rien offrir.
     *
     * C'est la raison pour laquelle la garde passe par `isAvailableFor()` et
     * non par `is_enabled` : sinon le premier geste echouerait, soit le meme
     * defaut deplace d'un cran.
     */
    public function test_being_enabled_without_a_credential_is_not_enough(): void
    {
        OrganizationAiSetting::query()->updateOrCreate(
            ['organization_id' => $this->organization->id],
            ['is_enabled' => true, 'provider' => 'openai', 'model' => 'gpt-4o-mini', 'api_key' => null],
        );
        app()->forgetInstance(\App\Support\Ai\AiSurfaceGate::class);

        $this->assertStringNotContainsString(
            'data-ai-formulate-block',
            $this->page($this->urlOrg('organization.services.create')),
        );
    }
}
