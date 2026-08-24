<?php

namespace Tests\Feature;

use App\Livewire\MemberAiProfileConversationalSetup;
use App\Models\AiProviderInvocation;
use App\Models\Category;
use App\Models\MemberAiProfile;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\AiPromptSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * TASK-1291 — convergence du tenant economique sur les DEUX depenses IA
 * restees en surface courte apres TASK-1286 :
 *
 *   (1) GET /agent-ia/setup + MemberAiProfileConversationalSetup
 *       (process member_profile.agent_setup) ;
 *   (2) POST /services/ai-formulate (process service_offer.master).
 *
 * Sur la surface courte, `ResolveUrlOrganization` lie l'Organization PAR
 * DEFAUT a la requete, quel que soit l'utilisateur connecte. Les deux chemins
 * prenaient `currentOrganization()` SANS test d'appartenance : un membre
 * d'une AUTRE Organization depensait le budget IA de l'Organization par
 * defaut (surface courte) ou de n'importe quelle Organization (surface
 * prefixee /org/{slug}, ou `ResolveOrganization` lie l'Organization de l'URL
 * sans verifier l'appartenance non plus).
 *
 * Doctrine appliquee (meme regle que RequestController::organizationFor(),
 * T1288, T1289) : le tenant economique vient de l'ACTEUR
 * (`users.organization_id`) ou de l'objet metier deja persiste, AVANT toute
 * autorisation de provider ; Organization de l'URL differente de celle de
 * l'acteur => refus fail-closed (404) AVANT toute ecriture et AVANT tout
 * appel provider ; acteur sans Organization deterministe => meme refus,
 * jamais un repli sur l'Organization par defaut.
 *
 * Point Livewire (le plus facile a manquer) : l'endpoint d'update est
 * `/livewire-{hash}/update` — un segment que `ResolveUrlOrganization` traite
 * comme une route feature, donc Organization PAR DEFAUT liee a CHAQUE update.
 * Le composant doit conserver le tenant de l'ACTEUR entre le rendu initial
 * et l'update, sans jamais relire `currentOrganization()` en cours de route.
 */
#[Group('ai')]
#[Group('sensitive')]
class TASK1291EconomicTenantConvergenceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $defaultOrganization;

    private Organization $organizationA;

    private Organization $organizationB;

    private User $memberDefault;

    private User $memberA;

    private User $stranger;

    private User $userWithoutOrganization;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai_pricing.version' => 'task1291',
            'ai_pricing.overrides' => [],
            'ai_pricing.models.openrouter.router/catalogued' => ['input_per_1m' => 2.0, 'output_per_1m' => 4.0],
            'ai.supervision_resolver.economic_guard.monthly_budget_usd' => 2.0,
            'ai.supervision_resolver.economic_guard.monthly_unknown_limit' => 10,
            'ai.default_provider' => 'openrouter',
            'ai.openrouter.enabled' => true,
            'ai.openrouter.api_key' => 'platform-key-1291',
            'ai.openrouter.base_url' => 'https://openrouter.task1291/api/v1',
            'ai.openrouter.model' => 'router/catalogued',
            'ai.openrouter.models' => ['router/catalogued' => 'Catalogued'],
            'ai.openrouter.timeout' => 15,
            'ai.openrouter.max_output_tokens' => 900,
            'ai.openai.supervision_enabled' => false,
            'ai.ollama.enabled' => false,
        ]);

        // L'Organization PAR DEFAUT : celle que `ResolveUrlOrganization` lie a
        // toute requete de la surface courte (is_default = true).
        $this->defaultOrganization = Organization::factory()->create([
            'name' => 'BouclePro 1291',
            'slug' => 'bouclepro-1291',
            'is_active' => true,
            'is_default' => true,
            'ai_profiles_enabled' => true,
            'service_points_min' => 20,
            'service_points_max' => 200,
        ]);
        $this->organizationA = Organization::factory()->create([
            'name' => 'Organization A 1291',
            'slug' => 'org-a-1291',
            'is_active' => true,
            'is_default' => false,
            'ai_profiles_enabled' => true,
            'service_points_min' => 20,
            'service_points_max' => 200,
        ]);
        $this->organizationB = Organization::factory()->create([
            'name' => 'Organization B 1291',
            'slug' => 'org-b-1291',
            'is_active' => true,
            'is_default' => false,
            'ai_profiles_enabled' => true,
        ]);

        // Chaque Organization visee a au moins une categorie : `formulate`
        // repond 422 sans categorie, ce qui masquerait la garde testee.
        Category::factory()->create([
            'organization_id' => $this->defaultOrganization->id,
            'name_b2c' => 'Categorie par defaut 1291',
            'slug' => 'cat-defaut-1291',
        ]);
        Category::factory()->create([
            'organization_id' => $this->organizationA->id,
            'name_b2c' => 'Categorie A 1291',
            'slug' => 'cat-a-1291',
        ]);

        // Profils COMPLETS (middleware `profile.complete` sur la route
        // services) et verifies : les middlewares des routes sont franchis,
        // la garde d'appartenance est la seule barriere mesuree.
        $this->memberDefault = $this->completeUserOf($this->defaultOrganization->id);
        $this->memberA = $this->completeUserOf($this->organizationA->id);
        $this->stranger = $this->completeUserOf($this->organizationB->id);
        $this->userWithoutOrganization = $this->completeUserOf(null);

        $this->seed(AiPromptSeeder::class);
    }

    private function completeUserOf(?string $organizationId): User
    {
        return User::factory()->complete()->create([
            'organization_id' => $organizationId,
            'is_admin' => false,
            'preferred_locale' => 'fr',
        ]);
    }

    private function fakeProviderSuccess(): void
    {
        Http::fake([
            'openrouter.task1291/*' => Http::response([
                'id' => 'chatcmpl-task1291',
                'object' => 'chat.completion',
                'model' => 'router/catalogued',
                'choices' => [[
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => 'Presentez votre activite.'],
                    'finish_reason' => 'stop',
                ]],
                'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 30, 'total_tokens' => 150],
            ]),
        ]);
    }

    /**
     * La formulation de service attend une reponse provider au format JSON
     * structure (titre, description, categorie...) — une reponse texte est
     * rejetee en 422 APRES l'appel provider, ce qui masquerait la garde.
     */
    private function fakeServiceFormulationSuccess(): void
    {
        Http::fake([
            'openrouter.task1291/*' => Http::response([
                'id' => 'chatcmpl-task1291-service',
                'object' => 'chat.completion',
                'model' => 'router/catalogued',
                'choices' => [[
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => json_encode([
                            'title' => 'Cours de guitare pour debutants',
                            'description_markdown' => str_repeat('Des cours de guitare progressifs et adaptes au niveau de chacun. ', 3),
                            'category_id' => '',
                            'delivery_mode' => 'remote',
                            'points_cost' => 50,
                        ]),
                    ],
                    'finish_reason' => 'stop',
                ]],
                'usage' => ['prompt_tokens' => 200, 'completion_tokens' => 100, 'total_tokens' => 300],
            ]),
        ]);
    }

    private function validPreviewData(): array
    {
        return [
            'summary' => 'Resume du profil',
            'service_scope' => 'Conseil',
            'skills' => ['PHP'],
        ];
    }

    private function assertNoLedgerLineAtAll(string $message): void
    {
        $this->assertSame(0, AiProviderInvocation::query()->count(), $message);
    }

    // =====================================================================
    // A. LE REFUS FAIL-CLOSED — (1) le setup de l'agent de profil
    // =====================================================================

    /**
     * Brief 3.a — un membre de l'Organization B, sur l'URL COURTE
     * /agent-ia/setup, obtenait la page montee sur l'Organization PAR DEFAUT
     * (profil cherche et cree chez elle, scope economique sur elle).
     * Attendu : refus fail-closed, la forme de tenant canonique (404).
     */
    public function test_a_stranger_is_refused_on_the_short_setup_page(): void
    {
        $this->actingAs($this->stranger)
            ->get('/agent-ia/setup')
            ->assertNotFound();
    }

    /**
     * Brief 3.c — meme scenario sur la surface PREFIXEE : /org/{org-A} avec
     * un utilisateur de B. `ResolveOrganization` lie l'Organization A sans
     * verifier l'appartenance ; le setup montait chez A.
     */
    public function test_a_stranger_is_refused_on_the_prefixed_setup_page_of_another_organization(): void
    {
        $this->actingAs($this->stranger)
            ->get('/org/'.$this->organizationA->slug.'/agent-ia/setup')
            ->assertNotFound();
    }

    /**
     * Brief 4.4 — un utilisateur SANS Organization deterministe
     * (organization_id NULL) doit etre refuse, jamais rabattu sur
     * l'Organization par defaut que la surface courte lui offre.
     */
    public function test_a_user_without_organization_is_refused_on_the_short_setup_page(): void
    {
        $this->actingAs($this->userWithoutOrganization)
            ->get('/agent-ia/setup')
            ->assertNotFound();
    }

    /**
     * Brief 3.a, cote depense — le scope economique du composant, dans le
     * contexte exact de la surface courte (Organization par defaut liee au
     * conteneur, ce que fait `ResolveUrlOrganization`), imputait l'appel
     * provider d'un membre de B au budget de l'Organization PAR DEFAUT.
     * Attendu : refus AVANT tout appel provider — AUCUNE ligne ledger
     * (ni succes ni echec), aucun profil, aucun trafic HTTP.
     */
    public function test_the_setup_spend_of_a_stranger_on_the_short_surface_is_refused_before_any_provider_call(): void
    {
        app()->instance('current_organization', $this->defaultOrganization);
        $this->actingAs($this->stranger);

        $refused = null;

        try {
            // Pas de Http::fake ici, a dessein : si le composant tente
            // l'appel provider, `Http::preventStrayRequests()` (TestCase)
            // le transforme en echec — et l'autorite economique ecrit alors
            // une ligne ledger FAILED chez l'Organization par defaut. C'est
            // exactement la depense fantome que la garde doit precéder.
            Livewire::test(MemberAiProfileConversationalSetup::class)
                ->call('start');
        } catch (HttpException $exception) {
            $refused = $exception;
        }

        $this->assertNoLedgerLineAtAll(
            'DEPENSE FANTOME : le setup d\'un membre d\'une autre Organization a produit une ligne '
            .'ai_provider_invocations (succes ou echec) imputee a l\'Organization par defaut. '
            .'Le refus fail-closed doit preceder tout appel provider.'
        );
        $this->assertSame(0, MemberAiProfile::query()->count(), 'Un refus ne cree aucun profil.');
        $this->assertNotNull(
            $refused,
            'Le membre d\'une autre Organization doit etre refuse fail-closed au montage du setup (404), '
            .'au lieu d\'obtenir un scope economique sur l\'Organization par defaut.'
        );
        $this->assertSame(404, $refused->getStatusCode());
        Http::assertNothingSent();
    }

    /**
     * Brief 4.3 — LE POINT LIVEWIRE. L'endpoint d'update
     * (`/livewire-{hash}/update`) ne porte jamais de segment d'Organization :
     * `ResolveUrlOrganization` y lie l'Organization PAR DEFAUT. Le composant
     * doit conserver le tenant de l'ACTEUR entre le rendu initial (page
     * prefixee /org/{org-A}) et l'update — profil ET ligne ledger chez A,
     * jamais chez l'Organization par defaut ambiante de l'update.
     *
     * Le test reproduit le contexte reel de l'update en re-liant
     * l'Organization par defaut au conteneur entre le montage et les actions,
     * exactement ce que fait le middleware sur le POST d'update avant toute
     * replay de middleware persistant.
     */
    public function test_the_conversational_setup_keeps_the_actor_tenant_between_render_and_livewire_update(): void
    {
        app()->instance('current_organization', $this->organizationA);
        $this->actingAs($this->memberA);

        $component = Livewire::test(MemberAiProfileConversationalSetup::class);

        // Contexte de l'update Livewire : Organization par defaut ambiante.
        app()->instance('current_organization', $this->defaultOrganization);

        $this->fakeProviderSuccess();

        $component->call('start')
            ->set('previewData', $this->validPreviewData())
            ->set('showPreview', true)
            ->call('validateAndSave');

        $profile = MemberAiProfile::query()->where('user_id', $this->memberA->id)->first();
        $this->assertNotNull($profile, 'Le membre legitime cree bien son profil.');
        $this->assertSame(
            $this->organizationA->id,
            $profile->organization_id,
            'Le profil cree pendant l\'update Livewire doit appartenir a l\'Organization de l\'ACTEUR (A), '
            .'jamais a l\'Organization par defaut que ResolveUrlOrganization lie a l\'endpoint /livewire-{hash}/update.'
        );
        $this->assertSame(1, MemberAiProfile::query()->count(), 'Un seul profil, chez A — aucun doublon chez l\'Organization par defaut.');

        $invocation = AiProviderInvocation::query()->firstOrFail();
        $this->assertSame(
            $this->organizationA->id,
            $invocation->organization_id,
            'La depense IA du setup doit rester imputee a l\'Organization de l\'acteur entre le rendu initial et l\'update.'
        );
        $this->assertSame(1, AiProviderInvocation::query()->count());
    }

    // =====================================================================
    // B. LE REFUS FAIL-CLOSED — (2) POST /services/ai-formulate
    // =====================================================================

    /**
     * Brief 3.b — un membre de l'Organization B, sur l'URL COURTE
     * POST /services/ai-formulate, obtenait un scope economique sur
     * l'Organization PAR DEFAUT : l'appel provider partait et la ligne
     * ledger etait imputee a son budget. Attendu : 404 fail-closed (la forme
     * de tenant deja en place dans ServiceController), AUCUNE ligne ledger.
     */
    public function test_a_stranger_is_refused_on_the_short_services_ai_formulate(): void
    {
        $this->fakeServiceFormulationSuccess();

        $this->actingAs($this->stranger)
            ->postJson('/services/ai-formulate', [
                'title' => 'Cours de guitare',
                'description' => 'Des cours de guitare pour debutants.',
            ])
            ->assertNotFound();

        $this->assertNoLedgerLineAtAll(
            'Le refus doit preceder tout appel provider : aucune ligne ai_provider_invocations ne doit etre '
            .'imputee a l\'Organization par defaut pour la formulation d\'un membre d\'une autre Organization.'
        );
        Http::assertNothingSent();
    }

    /**
     * Brief 3.c — meme scenario sur la surface PREFIXEE /org/{org-A} avec un
     * utilisateur de B : la depense partait sur le budget de A.
     */
    public function test_a_stranger_is_refused_on_the_prefixed_services_ai_formulate_of_another_organization(): void
    {
        $this->fakeServiceFormulationSuccess();

        $this->actingAs($this->stranger)
            ->postJson('/org/'.$this->organizationA->slug.'/services/ai-formulate', [
                'title' => 'Cours de guitare',
                'description' => 'Des cours de guitare pour debutants.',
            ])
            ->assertNotFound();

        $this->assertNoLedgerLineAtAll(
            'Le refus doit preceder tout appel provider : aucune ligne ai_provider_invocations ne doit etre '
            .'imputee a l\'Organization A pour la formulation d\'un membre de B sur la surface prefixee.'
        );
        Http::assertNothingSent();
    }

    /**
     * Brief 4.4 — l'utilisateur sans Organization deterministe est refuse sur
     * la formulation aussi, jamais rabattu sur l'Organization par defaut.
     */
    public function test_a_user_without_organization_is_refused_on_the_short_services_ai_formulate(): void
    {
        $this->fakeServiceFormulationSuccess();

        $this->actingAs($this->userWithoutOrganization)
            ->postJson('/services/ai-formulate', [
                'title' => 'Cours de guitare',
                'description' => 'Des cours de guitare pour debutants.',
            ])
            ->assertNotFound();

        $this->assertNoLedgerLineAtAll(
            'Aucun repli silencieux : un utilisateur sans Organization ne declenche aucune depense IA '
            .'sur l\'Organization par defaut.'
        );
        Http::assertNothingSent();
    }

    // =====================================================================
    // C. LE MEMBRE LEGITIME PASSE TOUJOURS — comportement historique intact
    // =====================================================================

    public function test_a_member_of_the_default_organization_still_opens_the_short_setup_page(): void
    {
        $this->actingAs($this->memberDefault)
            ->get('/agent-ia/setup')
            ->assertOk();
    }

    public function test_a_member_still_opens_the_prefixed_setup_page_of_his_organization(): void
    {
        $this->actingAs($this->memberA)
            ->get('/org/'.$this->organizationA->slug.'/agent-ia/setup')
            ->assertOk();
    }

    /**
     * Le flux complet du membre de l'Organization par defaut sur la surface
     * courte : depense imputee a SON Organization (qui est aussi celle de
     * l'URL), profil cree chez elle. Strictement le comportement historique.
     */
    public function test_a_member_of_the_default_organization_still_runs_the_short_setup_flow(): void
    {
        app()->instance('current_organization', $this->defaultOrganization);
        $this->actingAs($this->memberDefault);
        $this->fakeServiceFormulationSuccess();

        Livewire::test(MemberAiProfileConversationalSetup::class)
            ->call('start')
            ->set('previewData', $this->validPreviewData())
            ->set('showPreview', true)
            ->call('validateAndSave');

        $profile = MemberAiProfile::query()->where('user_id', $this->memberDefault->id)->firstOrFail();
        $this->assertSame($this->defaultOrganization->id, $profile->organization_id);

        $invocation = AiProviderInvocation::query()->firstOrFail();
        $this->assertSame($this->defaultOrganization->id, $invocation->organization_id);
        $this->assertSame($this->memberDefault->id, $invocation->user_id);
    }

    public function test_a_member_of_the_default_organization_still_formulates_on_the_short_surface(): void
    {
        $this->fakeServiceFormulationSuccess();

        $this->actingAs($this->memberDefault)
            ->postJson('/services/ai-formulate', [
                'title' => 'Cours de guitare',
                'description' => 'Des cours de guitare pour debutants.',
            ])
            ->assertOk()
            ->assertJsonStructure(['suggestion']);

        Http::assertSentCount(1);
        $invocation = AiProviderInvocation::query()->firstOrFail();
        $this->assertSame($this->defaultOrganization->id, $invocation->organization_id);
        $this->assertSame($this->memberDefault->id, $invocation->user_id);
    }

    public function test_a_member_still_formulates_on_the_prefixed_surface_of_his_organization(): void
    {
        $this->fakeServiceFormulationSuccess();

        $this->actingAs($this->memberA)
            ->postJson('/org/'.$this->organizationA->slug.'/services/ai-formulate', [
                'title' => 'Cours de guitare',
                'description' => 'Des cours de guitare pour debutants.',
            ])
            ->assertOk()
            ->assertJsonStructure(['suggestion']);

        Http::assertSentCount(1);
        $invocation = AiProviderInvocation::query()->firstOrFail();
        $this->assertSame(
            $this->organizationA->id,
            $invocation->organization_id,
            'La depense du membre legitime sur SA surface prefixee reste imputee a SON Organization.'
        );
    }
}
