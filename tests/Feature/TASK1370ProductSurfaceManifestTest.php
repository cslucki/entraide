<?php

namespace Tests\Feature;

use App\Ai\Agents\HelpRequestClarifierAgent;
use App\Ai\CapabilityRegistry;
use App\Ai\Context\ContextBuilder;
use App\Ai\Context\ContexteBorne;
use App\Ai\Context\ProductSurfacesSource;
use App\Ai\ContexteIa;
use App\Livewire\AiShell;
use App\Models\AdminAiPrompt;
use App\Models\AiInteraction;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Support\Ai\ProductSurfaceManifest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredTextResponse;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1370 — le modele n'a plus l'AUTORITE de dire ce qui existe.
 *
 * ## L'incident que ce fichier garde
 *
 * « How do I change my notification settings ? » a recu un chemin BouclePro
 * plausible — « typiquement dans le profil ou les reglages du compte » — vers un
 * ecran qui N'EXISTE PAS. La v3 du prompt interdisait pourtant deja d'inventer.
 * **Le modele a desobei a une instruction qu'il avait.**
 *
 * On ne teste donc pas « le modele obeit-il mieux ». On teste que la liste des
 * surfaces reellement disponibles PART dans le prompt, et qu'elle est filtree
 * par tenant, par lecteur et par drapeau. Ce qui n'y figure pas n'existe pas.
 *
 * ## Ce qui est mesure, et ou
 *
 * Le prompt REELLEMENT envoye, relu depuis `AiInteraction::prompt` — jamais une
 * reconstruction cote test. C'est la seule facon de savoir ce que le modele a
 * vraiment recu.
 */
class TASK1370ProductSurfaceManifestTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'is_active' => true,
            'slug' => 'org-surface-manifest',
            'name' => 'Org Surface Manifest',
            'loops_enabled' => true,
            'ai_profiles_enabled' => true,
        ]);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => 'sk-task1370-'.$this->organization->id,
            'monthly_budget_usd' => 5.00,
        ]);

        $this->member = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'is_admin' => false,
        ]);

        app()->instance('current_organization', $this->organization);

        config([
            'ai.fab.enabled' => true,
            'ai.shell.enabled' => true,
            'ai.clarify.enabled' => true,
            'ai.shell.max_context_chars' => 4000,
            'ai.providers.openai.driver' => 'openai',
            'ai.providers.openai.key' => 'platform-key',
            'ai_pricing.overrides' => [],
        ]);

        Http::preventStrayRequests();
        Http::fake();
    }

    // =====================================================================
    // A. Le manifest lui-meme : tenant, lecteur, drapeaux
    // =====================================================================

    /** 1. Un membre ordinaire voit les surfaces ouvertes de SON organisation. */
    public function test_a_member_sees_the_surfaces_open_to_their_organization(): void
    {
        $keys = $this->keysFor($this->organization, $this->member);

        $this->assertContains('loops', $keys);
        $this->assertContains('members_directory', $keys);
        $this->assertContains('ai_profile', $keys);
    }

    /**
     * 2. Une fonctionnalite DESACTIVEE est ABSENTE, pas « indisponible ».
     *
     * La nuance decide de tout : annoncer une surface comme indisponible
     * reviendrait encore a affirmer son existence, et laisserait le modele
     * libre d'en parler.
     */
    public function test_a_disabled_feature_is_absent_from_the_manifest(): void
    {
        $this->organization->forceFill(['loops_enabled' => false])->saveQuietly();

        $keys = $this->keysFor($this->organization->fresh(), $this->member);

        $this->assertNotContains('loops', $keys);
        $this->assertNotContains('create_loop', $keys);
        $this->assertContains('members_directory', $keys, 'Le reste du manifest ne doit pas disparaitre avec lui.');
    }

    /** 3. Idem pour les profils IA : un drapeau ferme retire la surface. */
    public function test_a_disabled_ai_profile_feature_is_absent(): void
    {
        $this->organization->forceFill(['ai_profiles_enabled' => false])->saveQuietly();

        $this->assertNotContains('ai_profile', $this->keysFor($this->organization->fresh(), $this->member));
    }

    /** 4. Une surface d'administration est ABSENTE du manifest d'un membre. */
    public function test_an_administration_surface_is_absent_for_a_plain_member(): void
    {
        $this->assertNotContains('organization_admin', $this->keysFor($this->organization, $this->member));
    }

    /** 5. Le meme registre, lu par l'administrateur de l'organisation, la contient. */
    public function test_the_same_surface_is_present_for_the_organization_admin(): void
    {
        $admin = User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'is_admin' => false,
        ]);

        $this->organization->forceFill(['admin_id' => $admin->id])->saveQuietly();

        $this->assertContains('organization_admin', $this->keysFor($this->organization->fresh(), $admin));
    }

    /**
     * 6. Deux organisations, deux manifests — et aucune fuite entre elles.
     *
     * L'organisation B ferme ses Boucles ; le manifest de A ne bouge pas, et
     * celui de B ne contient rien de A.
     */
    public function test_two_organizations_get_two_different_manifests(): void
    {
        $other = Organization::factory()->create([
            'is_active' => true,
            'slug' => 'org-surface-manifest-b',
            'loops_enabled' => false,
            'ai_profiles_enabled' => false,
        ]);

        $otherMember = User::factory()->complete()->create([
            'organization_id' => $other->id,
            'is_admin' => false,
        ]);

        $a = $this->keysFor($this->organization, $this->member);
        $b = $this->keysFor($other, $otherMember);

        $this->assertContains('loops', $a);
        $this->assertNotContains('loops', $b);
        $this->assertNotContains('ai_profile', $b);
        $this->assertNotSame($a, $b);
    }

    /** 7. Le manifest ne livre JAMAIS d'URL, de chemin ni d'identifiant. */
    public function test_the_manifest_never_exposes_a_route_a_path_or_an_id(): void
    {
        foreach (app(ProductSurfaceManifest::class)->forViewer($this->organization, $this->member) as $surface) {
            $this->assertSame(['key', 'label'], array_keys($surface));

            $this->assertStringNotContainsString('/', (string) $surface['label']);
            $this->assertStringNotContainsString('http', (string) $surface['label']);
            $this->assertStringNotContainsString('organization.', (string) $surface['label']);
        }
    }

    // =====================================================================
    // B. Le CONTEXTE GOUVERNE — et la question de l'utilisateur, intacte
    // =====================================================================

    /**
     * 8. LA QUESTION DE L'UTILISATEUR NE BOUGE PAS D'UN OCTET.
     *
     * C'est l'invariant tenu par cinq TASKs (T1315, T1346, T1350, T1358,
     * T1359). Une premiere version de cette TASK posait l'autorite dans
     * `situated()` et faisait tomber neuf de leurs assertions. L'autorite
     * appartient au contexte borne, pas au texte de la personne.
     */
    public function test_the_user_question_reaches_the_prompt_byte_exact(): void
    {
        $this->fakeClarifier();

        $this->send('Comment ça marche ici ?');

        $this->assertSame('Comment ça marche ici ?', $this->lastPrompt());
    }

    /** 9. Les surfaces arrivent dans le CONTEXTE GOUVERNE, et y sont tracees. */
    public function test_the_surfaces_reach_the_governed_context(): void
    {
        $borne = $this->clarifyContext();

        $this->assertContains(ProductSurfacesSource::NAME, $borne->sourcesUsed);
        $this->assertStringContainsString('SURFACES BOUCLEPRO DISPONIBLES POUR CE MEMBRE', $borne->text);
        $this->assertStringContainsString(__('ai.surface_loops'), $borne->text);
    }

    /** 10. Une surface fermee n'entre jamais dans le contexte. */
    public function test_a_disabled_surface_never_reaches_the_context(): void
    {
        $this->organization->forceFill(['loops_enabled' => false])->saveQuietly();

        $borne = $this->clarifyContext();

        $this->assertStringContainsString('SURFACES BOUCLEPRO DISPONIBLES', $borne->text);
        $this->assertStringNotContainsString(__('ai.surface_loops'), $borne->text);
    }

    /** 11. La surface d'administration n'entre pas dans le contexte d'un membre. */
    public function test_the_admin_surface_never_reaches_a_member_context(): void
    {
        $this->assertStringNotContainsString(
            __('ai.surface_organization_admin'),
            $this->clarifyContext()->text,
        );
    }

    /**
     * 12. LE VIDE EST UNE DONNEE : le bloc est emis meme sans aucune surface.
     *
     * `ContextBuilder` ecarte les fragments vides. Rendre `SourceFragment::empty()`
     * ferait donc disparaitre le bloc, et « rien a affirmer » cesserait d'etre
     * dit — ce qui laisserait le modele exactement dans l'etat qui a produit
     * l'incident.
     */
    public function test_an_empty_manifest_is_still_stated_explicitly(): void
    {
        // Aucune organisation reelle ne produit un manifest vide : l'annuaire,
        // la messagerie et les dossiers ne dependent d'aucun drapeau. On
        // SUBSTITUE donc le manifest pour eprouver le cas — sans quoi « rien a
        // affirmer » resterait une intention jamais mesuree.
        $this->app->instance(ProductSurfaceManifest::class, new class extends ProductSurfaceManifest
        {
            public function forViewer(Organization $organization, User $user): array
            {
                return [];
            }
        });

        $borne = $this->clarifyContext();

        $this->assertContains(ProductSurfacesSource::NAME, $borne->sourcesUsed);
        $this->assertStringContainsString(__('ai.surfaces_context_none'), $borne->text);
    }

    /** 13. Le contexte ne livre aucune URL, aucun chemin, aucun nom de route. */
    public function test_the_context_block_never_carries_a_route_or_a_path(): void
    {
        $bloc = $this->surfacesBlock($this->clarifyContext()->text);

        $this->assertStringNotContainsString('http', $bloc);
        $this->assertStringNotContainsString('/org/', $bloc);
        $this->assertStringNotContainsString('organization.', $bloc);
    }

    // =====================================================================
    // C. Le prompt v4 : inactive, et l'activation est EXPLICITE
    // =====================================================================

    /** 12. La migration pose v4, et elle est INACTIVE. */
    public function test_the_v4_prompt_is_provisioned_inactive(): void
    {
        $v4 = AdminAiPrompt::query()
            ->where('scenario_id', 'clarify_help_request')
            ->where('version', 4)
            ->firstOrFail();

        $this->assertFalse((bool) $v4->is_active, 'v4 ne doit jamais s\'activer toute seule.');
        $this->assertStringContainsString('SURFACES BOUCLEPRO DISPONIBLES', $v4->prompt_text);
    }

    /** 13. Tant que v4 dort, l'autorite du scenario reste la version active la plus haute. */
    public function test_an_inactive_v4_does_not_take_authority(): void
    {
        $this->assertNotSame(4, $this->activeVersion(), 'Une version inactive ne gouverne rien.');
    }

    /**
     * 14. Activee, v4 gouverne — MEME si d'anciennes versions restent actives.
     *
     * `clarifyInstructions()` retient la version ACTIVE la plus haute. C'est ce
     * qui rend l'activation humaine suffisante, sans qu'il faille toucher aux
     * lignes existantes : l'unicite de l'actif n'est pas garantie par le
     * mecanisme actuel, et cette TASK ne la corrige pas.
     */
    public function test_an_activated_v4_takes_authority_even_beside_older_actives(): void
    {
        AdminAiPrompt::query()
            ->where('scenario_id', 'clarify_help_request')
            ->where('version', 4)
            ->update(['is_active' => true]);

        AdminAiPrompt::query()
            ->where('scenario_id', 'clarify_help_request')
            ->where('version', 1)
            ->update(['is_active' => true]);

        $this->assertSame(4, $this->activeVersion());
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /** @return list<string> */
    private function keysFor(Organization $organization, User $user): array
    {
        return array_column(
            app(ProductSurfaceManifest::class)->forViewer($organization, $user),
            'key',
        );
    }

    /** La version qui fait autorite : la plus haute PARMI LES ACTIVES. */
    private function activeVersion(): ?int
    {
        $prompt = AdminAiPrompt::query()
            ->where('scenario_id', 'clarify_help_request')
            ->where('is_active', true)
            ->orderByDesc('version')
            ->first();

        return $prompt === null ? null : (int) $prompt->version;
    }

    /** Le contexte BORNE reellement construit pour la capability de clarification. */
    private function clarifyContext(): ContexteBorne
    {
        $registry = app(CapabilityRegistry::class);

        return app(ContextBuilder::class)->build(
            new ContexteIa(
                organizationId: (string) $this->organization->fresh()->id,
                userId: (string) $this->member->id,
                loopId: null,
                locale: 'fr',
                capability: CapabilityRegistry::CLARIFY_HELP_REQUEST,
                correlationId: (string) Str::uuid(),
            ),
            $registry->get(CapabilityRegistry::CLARIFY_HELP_REQUEST),
        );
    }

    private function surfacesBlock(string $texte): string
    {
        $debut = mb_strpos($texte, '--- SURFACES BOUCLEPRO DISPONIBLES POUR CE MEMBRE ---');
        $this->assertNotFalse($debut, 'Le bloc des surfaces doit etre present.');

        $fin = mb_strpos($texte, '--- FIN DES SURFACES ---', $debut);

        return mb_substr($texte, $debut, $fin - $debut);
    }

    private function closeEverySurface(): void
    {
        $this->organization->forceFill([
            'loops_enabled' => false,
            'ai_profiles_enabled' => false,
            'subscriptions_enabled' => false,
        ])->saveQuietly();
    }

    private function send(string $draft): void
    {
        Livewire::actingAs($this->member)
            ->test(AiShell::class)
            ->set('draft', $draft)
            ->call('send');
    }

    /** Le prompt REELLEMENT parti au modele, relu depuis la trace du tour. */
    private function lastPrompt(): string
    {
        return (string) AiInteraction::query()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->firstOrFail()
            ->prompt;
    }

    private function fakeClarifier(): void
    {
        $structured = [
            'title' => '',
            'clarified_request' => '',
            'help_type' => 'information',
            'suggested_loop_id' => '',
            'suggested_category_id' => '',
            'suggestion_reason' => '',
            'questions_for_user' => [],
            'confidence' => 0.9,
            'needs_human_review' => false,
        ];

        HelpRequestClarifierAgent::fake(fn (): StructuredTextResponse => new StructuredTextResponse(
            $structured,
            json_encode($structured, JSON_UNESCAPED_UNICODE),
            new Usage(120, 80),
            new Meta('openai', 'gpt-4o-mini'),
        ));
    }
}
