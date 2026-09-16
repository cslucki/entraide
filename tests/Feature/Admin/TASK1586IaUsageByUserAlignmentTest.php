<?php

namespace Tests\Feature\Admin;

use App\Models\AdminAiInteraction;
use App\Models\AiInteraction;
use App\Models\AiProviderInvocation;
use App\Models\Organization;
use App\Models\User;
use App\Services\Ai\OrganizationAiEconomicUsage;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * TASK-1586 — `/admin/ia-usage-by-user` sur l'autorite economique canonique
 * (SENSITIVE : reporting economique plateforme).
 *
 * A acces ; B meme realite que le releve Organization Admin (meme methode,
 * memes chiffres, rerank inclus dans les inconnus) ; C aucun double comptage
 * (le registre legacy `admin_ai_interactions` ne compte plus) ; D un inconnu
 * n'est jamais $0 ; E tenant (filtre Organization, trace attribuee a un
 * utilisateur d'ailleurs = non attribuable) ; F fenetre.
 */
#[Group('sensitive')]
class TASK1586IaUsageByUserAlignmentTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $superAdmin;

    private User $maya;

    private User $bob;

    private User $etranger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create(['name' => 'Org A 1586', 'slug' => 'org-a-1586']);
        $this->orgB = Organization::factory()->create(['name' => 'Org B 1586', 'slug' => 'org-b-1586']);
        $this->superAdmin = User::factory()->create(['organization_id' => $this->orgA->id, 'is_admin' => true, 'name' => 'Super Admin']);
        $this->maya = User::factory()->create(['organization_id' => $this->orgA->id, 'name' => 'Maya Teste', 'email' => 'maya-1586@example.test']);
        $this->bob = User::factory()->create(['organization_id' => $this->orgA->id, 'name' => 'Bob Rerank', 'email' => 'bob-1586@example.test']);
        $this->etranger = User::factory()->create(['organization_id' => $this->orgB->id, 'name' => 'Etranger B', 'email' => 'etranger-1586@example.test']);
    }

    // ────────────────────────────── A. acces

    public function test_a1_seul_un_admin_plateforme_lit_l_ecran(): void
    {
        $this->assertContains($this->get(route('admin.ia-usage-by-user'))->getStatusCode(), [302, 401, 403]);
        $this->actingAs($this->maya)->get(route('admin.ia-usage-by-user'))->assertForbidden();
        $this->actingAs($this->superAdmin)->get(route('admin.ia-usage-by-user'))->assertOk()->assertSee('Autorité économique canonique');
    }

    // ────────────────────────────── B. meme realite que l'Organization Admin

    public function test_b1_la_ligne_superadmin_d_un_utilisateur_est_sa_ligne_organization_admin(): void
    {
        $this->generation($this->orgA, $this->maya, 0.50);
        $this->generation($this->orgA, $this->maya, null);           // inconnu
        $this->embeddingQuery($this->orgA, $this->maya, 0.000002);
        $this->embeddingIngestion($this->orgA);                       // user NULL par design -> non attribuable
        $this->rerank($this->orgA, $this->maya);                      // inconnu
        $this->rerank($this->orgA, $this->maya, AiProviderInvocation::STATUS_FAILED);

        $from = CarbonImmutable::now()->startOfMonth();
        $autorite = collect(app(OrganizationAiEconomicUsage::class)->byUser((string) $this->orgA->id, $from, $from->addMonth()))->firstWhere('user_id', (string) $this->maya->id);

        // L'autorite dit : cout connu 0.500002, 2 inconnus (1 generation + 1 rerank), 1 echec rerank.
        $this->assertEqualsWithDelta(0.500002, $autorite['total_known_cost_usd'], 1e-9);
        $this->assertSame(2, $autorite['total_unknown_count'], 'le rerank inconnu compte (arbitrage MASTER 16/09)');
        $this->assertSame(1, $autorite['rerank']['unknown_count']);
        $this->assertSame(1, $autorite['rerank']['failed_count']);
        $this->assertSame(3, $autorite['total_count'], 'generation ×2 + embedding query ; le rerank a son compteur a part');

        $page = $this->actingAs($this->superAdmin)->get(route('admin.ia-usage-by-user'))->assertOk();
        // La ligne de l'ecran EST la ligne de l'autorite, au chiffre pres.
        $page->assertSee('data-usage-row="'.$this->maya->id.'" data-usage-org="'.$this->orgA->id.'" data-usage-known-cost="'.$autorite['total_known_cost_usd'].'" data-usage-unknown="2"', false);
        $page->assertSee('$0.500002');
        $page->assertSee('2 non mesuré(s)');
        // Rerank VISIBLE a part : 2 invocations = 1 reussie (inconnue) + 1 echec, dans sa colonne ; les
        // echecs ne se deguisent pas en inconnus.
        $page->assertSee('data-usage-rerank="'.$this->maya->id.'"', false);
        $page->assertSeeInOrder(['data-usage-rerank="'.$this->maya->id.'"', '2 · 1 inconnu(s) · 1 échec(s)'], false);
        $page->assertSee('Rerank');
        // « Appels » = total_count de l'autorite (generation + embeddings), comme
        // l'Org Admin — le rerank reste dans SA colonne (review Opus F4).
        $page->assertSee('data-usage-total-count="3"', false);
        $page->assertDontSee('data-usage-total-count="5"', false);
        // L'ingestion (user NULL) est comptee, en « Non attribuable », jamais repartie.
        $page->assertSee('data-usage-row="unattributed" data-usage-org="'.$this->orgA->id.'"', false);
        $page->assertSee('Non attribuable');

        // Et le releve Organization Admin, meme fenetre, raconte la meme chose.
        $summary = app(OrganizationAiEconomicUsage::class)->summary((string) $this->orgA->id, $from, $from->addMonth());
        $this->assertSame(2, $summary['total_unknown_count']);
        $this->assertSame($summary['total_unknown_count'], array_sum(array_column(app(OrganizationAiEconomicUsage::class)->byUser((string) $this->orgA->id, $from, $from->addMonth()), 'total_unknown_count')), 'somme des lignes = summary (invariant T1228, rerank inclus)');
        // 0.500002 (Maya) + 0.00001 (ingestion non attribuable) : le rerank inconnu n'ajoute JAMAIS au cout connu.
        $this->assertEqualsWithDelta(0.500012, $summary['total_known_cost_usd'], 1e-9);
    }

    // ────────────────────────────── C. aucun double comptage

    public function test_c1_le_registre_legacy_admin_ai_interactions_ne_compte_plus(): void
    {
        $this->generation($this->orgA, $this->maya, 0.10);
        // Le meme appel historiquement journalise DEUX fois (registre admin).
        AdminAiInteraction::create([
            'organization_id' => $this->orgA->id, 'user_id' => $this->maya->id, 'correlation_id' => (string) Str::uuid(),
            'process' => 'help_request.clarify', 'scenario_id' => 'clarify', 'provider' => 'openrouter', 'model' => 'm', 'status' => 'success',
            'input_length' => 0, 'input_tokens' => 10, 'output_tokens' => 5, 'cost_usd' => 0.10, 'cost_unknown' => false,
        ]);

        $page = $this->actingAs($this->superAdmin)->get(route('admin.ia-usage-by-user'))->assertOk();
        $page->assertSee('$0.100000')->assertDontSee('$0.200000');
        $page->assertDontSee('Cumul brut');
    }

    // ────────────────────────────── D. un inconnu n'est jamais $0

    public function test_d1_un_utilisateur_au_seul_rerank_inconnu_lit_tiret_et_un_non_mesure(): void
    {
        $this->rerank($this->orgA, $this->bob);

        $page = $this->actingAs($this->superAdmin)->get(route('admin.ia-usage-by-user', ['search' => 'bob-1586']))->assertOk();
        $page->assertSee('Bob Rerank');
        $page->assertSee('data-usage-row="'.$this->bob->id.'" data-usage-org="'.$this->orgA->id.'" data-usage-known-cost="" data-usage-unknown="1"', false);
        $page->assertSee('1 non mesuré(s)');
        $page->assertDontSee('$0.000000');
        $page->assertDontSee('Maya Teste');
    }

    // ────────────────────────────── E. tenant

    public function test_e1_le_filtre_organization_borne_et_une_trace_d_un_utilisateur_d_ailleurs_est_non_attribuable(): void
    {
        $this->generation($this->orgA, $this->maya, 0.30);
        $this->generation($this->orgB, $this->etranger, 0.70);
        // Une ligne ledger de l'Org A attribuee a un utilisateur de l'Org B :
        // comptee dans A (argent de A), jamais nommee (defense tenant).
        $this->embeddingQuery($this->orgA, $this->etranger, 0.000005);

        $a = $this->actingAs($this->superAdmin)->get(route('admin.ia-usage-by-user', ['organization_id' => (string) $this->orgA->id]))->assertOk();
        $a->assertSee('Maya Teste')->assertDontSee('Etranger B')->assertDontSee('$0.700000');
        $a->assertSee('data-usage-row="unattributed" data-usage-org="'.$this->orgA->id.'"', false);
        $a->assertSee('$0.000005');

        $b = $this->actingAs($this->superAdmin)->get(route('admin.ia-usage-by-user', ['organization_id' => (string) $this->orgB->id]))->assertOk();
        $b->assertSee('Etranger B')->assertDontSee('Maya Teste')->assertDontSee('$0.300000');
        // Un organization_id non uuid : « Toutes », jamais une exception.
        $this->actingAs($this->superAdmin)->get(route('admin.ia-usage-by-user', ['organization_id' => 'x']))->assertOk()->assertSee('Maya Teste')->assertSee('Etranger B');
    }

    // ────────────────────────────── F. fenetre

    public function test_f1_la_fenetre_borne_les_lignes_sommees_et_se_replie_sur_le_mois_courant(): void
    {
        $this->generation($this->orgA, $this->maya, 0.50);
        $vieille = $this->generation($this->orgA, $this->maya, 9.99);
        $vieille->forceFill(['created_at' => now()->subMonths(2)])->saveQuietly();

        $this->actingAs($this->superAdmin);
        $this->get(route('admin.ia-usage-by-user', ['date_from' => now()->subDay()->toDateString(), 'date_to' => now()->toDateString()]))
            ->assertOk()->assertSee('$0.500000')->assertDontSee('$10.490000');
        // Bornes invalides -> TOUTE la periode se replie sur le mois courant
        // (review Opus F5 : Carbon relit 2026-13-45 en 2027-02-14 sans lever ;
        // l'aller-retour strict le refuse). Preuve : une trace d'il y a 2 mois
        // n'entre pas, et la fenetre affichee est celle du mois.
        $mois = now()->startOfMonth()->format('d/m/Y');
        $this->get(route('admin.ia-usage-by-user', ['date_from' => 'hier', 'date_to' => '2026-13-45']))->assertOk()->assertSee('$0.500000')->assertDontSee('$9.990000')->assertSee('Fenêtre '.$mois);
        $this->get(route('admin.ia-usage-by-user', ['date_from' => now()->subMonths(3)->toDateString(), 'date_to' => '2026-02-30']))->assertOk()->assertDontSee('$9.990000')->assertSee('Fenêtre '.$mois, 'une borne illisible invalide TOUTE la periode, pas seulement la sienne');
        $this->get(route('admin.ia-usage-by-user', ['date_from' => now()->toDateString(), 'date_to' => now()->subDays(3)->toDateString()]))->assertOk()->assertSee('$0.500000')->assertSee('Fenêtre '.$mois);
    }

    public function test_g1_l_ecran_n_ecrit_rien(): void
    {
        $this->generation($this->orgA, $this->maya, 0.50);
        $this->rerank($this->orgA, $this->maya);
        $avant = [AiInteraction::query()->count(), AiProviderInvocation::query()->count(), AdminAiInteraction::query()->count()];

        $this->actingAs($this->superAdmin)->get(route('admin.ia-usage-by-user'))->assertOk();

        $this->assertSame($avant, [AiInteraction::query()->count(), AiProviderInvocation::query()->count(), AdminAiInteraction::query()->count()]);
    }

    // ────────────────────────────── fixtures

    private function generation(Organization $organization, User $user, ?float $cost): AiInteraction
    {
        return AiInteraction::create([
            'user_id' => $user->id, 'organization_id' => $organization->id, 'correlation_id' => (string) Str::uuid(),
            'process' => 'help_request.clarify', 'feature' => 'clarify_help_request', 'model' => 'openai/gpt-4o-mini', 'prompt' => 'x',
            'input_tokens' => 10, 'output_tokens' => 5, 'cost_usd' => $cost, 'cost_unknown' => $cost === null,
        ]);
    }

    private function embeddingQuery(Organization $organization, User $user, float $cost): void
    {
        AiProviderInvocation::create([
            'organization_id' => $organization->id, 'user_id' => $user->id, 'capability' => 'loop_knowledge_answer', 'process' => 'dossier.embeddings_search',
            'operation' => AiProviderInvocation::OPERATION_EMBEDDING, 'embedding_operation' => AiProviderInvocation::EMBEDDING_OPERATION_QUERY,
            'provider' => 'openrouter', 'model' => 'openai/text-embedding-3-small', 'credential_source' => AiProviderInvocation::CREDENTIAL_ORGANIZATION,
            'provider_cost' => $cost, 'currency' => 'USD', 'cost_status' => AiProviderInvocation::COST_KNOWN, 'cost_source' => 'catalog_estimated', 'status' => AiProviderInvocation::STATUS_SUCCESS,
        ]);
    }

    private function embeddingIngestion(Organization $organization): void
    {
        AiProviderInvocation::create([
            'organization_id' => $organization->id, 'user_id' => null, 'capability' => null, 'process' => 'dossier.embeddings_index',
            'operation' => AiProviderInvocation::OPERATION_EMBEDDING, 'embedding_operation' => AiProviderInvocation::EMBEDDING_OPERATION_INGESTION,
            'provider' => 'openrouter', 'model' => 'openai/text-embedding-3-small', 'credential_source' => AiProviderInvocation::CREDENTIAL_ORGANIZATION,
            'provider_cost' => 0.00001, 'currency' => 'USD', 'cost_status' => AiProviderInvocation::COST_KNOWN, 'cost_source' => 'catalog_estimated', 'status' => AiProviderInvocation::STATUS_SUCCESS,
        ]);
    }

    private function rerank(Organization $organization, User $user, string $status = AiProviderInvocation::STATUS_SUCCESS): void
    {
        AiProviderInvocation::create([
            'organization_id' => $organization->id, 'user_id' => $user->id, 'capability' => 'loop_knowledge_answer', 'process' => 'dossier.retrieval',
            'operation' => AiProviderInvocation::OPERATION_RERANK, 'provider' => 'openrouter', 'model' => 'cohere/rerank-v3.5',
            'credential_source' => AiProviderInvocation::CREDENTIAL_ORGANIZATION, 'provider_cost' => null, 'currency' => null,
            'cost_status' => AiProviderInvocation::COST_UNKNOWN, 'cost_source' => AiProviderInvocation::COST_UNKNOWN, 'status' => $status,
        ]);
    }
}
