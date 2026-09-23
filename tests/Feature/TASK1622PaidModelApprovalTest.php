<?php

namespace Tests\Feature;

use App\Ai\Agents\LoopMultiAiAgent;
use App\Models\AdminAiPrompt;
use App\Models\AiInteraction;
use App\Models\AiProviderInvocation;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\LoopPluginAiModel;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Ai\LoopMultiAiOrchestrator;
use App\Services\Ai\LoopPluginAiModels;
use App\Services\Ai\LoopPluginModelGuard;
use App\Services\Ai\OpenRouterModelCatalog;
use App\Services\Loops\LoopPluginActivation;
use App\Services\Loops\LoopPluginAvailabilityService;
use App\Support\Ai\AiPricingCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Tests\TestCase;

/**
 * TASK-1622 — le mode « Payant approuve » a cote du « Gratuit verifie ».
 *
 * Ce que ce banc pinne, et qui n'existait pas :
 *
 *  - un modele PAYANT ne s'utilise que s'il est APPROUVE (auteur + date),
 *    dans la SHORTLIST, TARIFE au releve statique — chaque marche manquante
 *    refuse, avant l'appel, sans repli vers le gratuit ni vers un autre
 *    payant ;
 *  - le mecanisme FREE est INTOUCHE : meme preuve, meme peremption, meme
 *    fail-closed (son banc, TASK-1617, reste l'autorite — ici on ne verifie
 *    que la cohabitation) ;
 *  - un payant n'est JAMAIS chiffre zero : la source dynamique (preuves de
 *    gratuite) ignore les lignes payantes, et le ledger porte un cout CONNU
 *    calcule du tarif statique ;
 *  - le ledger ecrit `success` — la constante — et plus `completed`, qui
 *    rendait les lignes de ce moteur invisibles a tout filtre
 *    `status = success` (releves SuperAdmin, quota des couts inconnus).
 *    Tolerable a cout 0 ; avec un payant, c'etait de la depense hors releve.
 *
 * Le SDK et l'API OpenRouter sont TOUJOURS doubles.
 */
class TASK1622PaidModelApprovalTest extends TestCase
{
    use RefreshDatabase;

    private const PLUGIN = 'multi_ai_assistants';

    /** Les slugs du banc — synthetiques : un test ne fige pas la shortlist produit. */
    private const LIBRE = 'vendor/libre-a';

    private const PAYE = 'vendor/paye-a';

    private const PAYE_SANS_TARIF = 'vendor/paye-b';

    private Organization $organization;

    private User $superAdmin;

    private User $membre;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget(OpenRouterModelCatalog::CACHE_KEY);

        $this->organization = Organization::factory()->create([
            'name' => 'Alpha 1622', 'is_active' => true, 'loops_enabled' => true, 'locale' => 'fr',
        ]);

        OrganizationAiSetting::factory()->create([
            'organization_id' => $this->organization->id,
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'api_key' => 'sk-or-task1622',
            'monthly_budget_usd' => null,
        ]);

        $this->superAdmin = User::factory()->create([
            'is_admin' => true, 'organization_id' => $this->organization->id, 'preferred_locale' => 'fr',
        ]);
        $this->membre = User::factory()->create(['organization_id' => $this->organization->id, 'preferred_locale' => 'fr']);

        $this->loop = Loop::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by' => $this->superAdmin->id,
            'status' => 'active',
            'type' => 'general',
        ]);

        LoopMember::create([
            'loop_id' => $this->loop->id, 'user_id' => $this->membre->id,
            'role' => 'member', 'status' => 'active', 'joined_at' => now(),
        ]);

        AdminAiPrompt::create([
            'scenario_id' => 'loop_multi_ai',
            'name' => 'Socle multi-assistants (banc 1622)',
            'description' => 'Banc TASK-1622',
            'version' => 1,
            'is_active' => true,
            'prompt_text' => 'SOCLE PLATEFORME : tu ne decides jamais a la place du groupe.',
        ]);

        app(LoopPluginAvailabilityService::class)
            ->setAvailability(self::PLUGIN, $this->organization, true, $this->superAdmin);
        app(LoopPluginActivation::class)
            ->setEnabled(self::PLUGIN, $this->loop, true, $this->superAdmin);

        // La shortlist et le tarif statique du BANC : `paye-a` est tarife,
        // `paye-b` est volontairement SANS tarif — c'est la marche a mesurer.
        config()->set('ai.multi_ai.paid_model_shortlist', [
            self::PAYE => 'Paye A (banc)',
            self::PAYE_SANS_TARIF => 'Paye B (banc)',
        ]);
        config()->set('ai_pricing.models.openrouter.'.self::PAYE, [
            'input_per_1m' => 0.10, 'output_per_1m' => 0.40,
        ]);

        $this->fakeCatalogue();
    }

    // ── 1. LE GESTE D'APPROBATION ───────────────────────────────────────────

    public function test_approuver_un_payant_ecrit_le_type_et_l_audit_trail(): void
    {
        $ligne = app(LoopPluginAiModels::class)
            ->assignPaid('aperio', self::PAYE, $this->superAdmin);

        $this->assertSame(LoopPluginAiModel::TYPE_PAID_APPROVED, $ligne->model_type);
        $this->assertNotNull($ligne->approved_at, 'une approbation est datee');
        $this->assertSame($this->superAdmin->id, $ligne->approved_by, 'une approbation a un auteur');
        $this->assertNull($ligne->verified_free_at, 'un payant ne porte aucune preuve de gratuite');
    }

    public function test_un_slug_hors_shortlist_n_est_pas_approuvable(): void
    {
        // `vendor/inconnu` recoit ICI un tarif statique ET il est au
        // catalogue : la SEULE marche qui lui manque est la shortlist. Sans
        // ce tarif, le sabotage de la marche shortlist restait VERT — le
        // test mesurait la marche tarif a sa place (mesure le 22/09).
        config()->set('ai_pricing.models.openrouter.vendor/inconnu', [
            'input_per_1m' => 0.10, 'output_per_1m' => 0.10,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(LoopPluginAiModels::REASON_PAID_REJECTED);

        app(LoopPluginAiModels::class)->assignPaid('aperio', 'vendor/inconnu', $this->superAdmin);
    }

    public function test_un_slug_sans_tarif_statique_n_est_pas_approuvable(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(LoopPluginAiModels::REASON_PAID_REJECTED);

        // Dans la shortlist, au catalogue, mais SANS entree au releve
        // statique : jamais un payant a cout inconnu — c'est le mandat.
        app(LoopPluginAiModels::class)->assignPaid('aperio', self::PAYE_SANS_TARIF, $this->superAdmin);
    }

    public function test_un_catalogue_illisible_refuse_l_approbation(): void
    {
        Cache::forget(OpenRouterModelCatalog::CACHE_KEY);
        Http::swap(new Factory);
        Http::fake(['*/models' => Http::response('boom', 500)]);

        $this->expectException(\InvalidArgumentException::class);

        app(LoopPluginAiModels::class)->assignPaid('aperio', self::PAYE, $this->superAdmin);
    }

    public function test_revenir_au_gratuit_efface_l_approbation(): void
    {
        $service = app(LoopPluginAiModels::class);
        $service->assignPaid('aperio', self::PAYE, $this->superAdmin);

        $ligne = $service->assign('aperio', self::LIBRE, $this->superAdmin);

        $this->assertSame(LoopPluginAiModel::TYPE_FREE_VERIFIED, $ligne->model_type);
        $this->assertNull($ligne->approved_at, 'une ligne ne porte qu\'un contrat a la fois');
        $this->assertNull($ligne->approved_by);
        $this->assertNotNull($ligne->verified_free_at);
    }

    // ── 2. LA GARDE D'EXECUTION ─────────────────────────────────────────────

    public function test_la_garde_rend_le_slug_paye_approuve_et_tarife(): void
    {
        app(LoopPluginAiModels::class)->assignPaid('aperio', self::PAYE, $this->superAdmin);

        $raison = null;
        $slug = app(LoopPluginModelGuard::class)->eligibleSlug('aperio', $raison);

        $this->assertSame(self::PAYE, $slug);
        $this->assertNull($raison);
    }

    public function test_une_ligne_payante_forgee_sans_approbation_est_refusee(): void
    {
        app(LoopPluginAiModels::class)->assignPaid('aperio', self::PAYE, $this->superAdmin);
        // Une ecriture directe qui contournerait le service : la garde ne la
        // croit pas sur parole.
        LoopPluginAiModel::query()->where('assistant_key', 'aperio')
            ->first()->forceFill(['approved_at' => null])->save();

        $raison = null;
        $slug = app(LoopPluginModelGuard::class)->eligibleSlug('aperio', $raison);

        $this->assertNull($slug);
        $this->assertSame(LoopPluginAiModels::REASON_PAID_REJECTED, $raison);
    }

    public function test_un_tarif_disparu_du_releve_refuse_avant_l_appel(): void
    {
        app(LoopPluginAiModels::class)->assignPaid('aperio', self::PAYE, $this->superAdmin);
        config()->set('ai_pricing.models.openrouter.'.self::PAYE, null);

        $raison = null;
        $slug = app(LoopPluginModelGuard::class)->eligibleSlug('aperio', $raison);

        $this->assertNull($slug, 'un payant sans tarif ne part JAMAIS');
        $this->assertSame(LoopPluginAiModels::REASON_PAID_REJECTED, $raison);
    }

    public function test_un_payant_invalide_ne_replie_jamais_vers_le_gratuit(): void
    {
        // Le catalogue PORTE des modeles gratuits verifies : si un repli
        // existait, il aurait ou se servir. Il ne doit pas exister.
        app(LoopPluginAiModels::class)->assignPaid('aperio', self::PAYE, $this->superAdmin);
        config()->set('ai_pricing.models.openrouter.'.self::PAYE, null);

        $slug = app(LoopPluginModelGuard::class)->eligibleSlug('aperio');

        $this->assertNull($slug);
        $this->assertTrue(app(OpenRouterModelCatalog::class)->isSlugVerifiedFree(self::LIBRE),
            'premisse : un gratuit etait disponible, et il n\'a PAS ete pris');
    }

    // ── 3. L'ECONOMIE : JAMAIS ZERO, JAMAIS INCONNU ─────────────────────────

    public function test_un_payant_n_est_jamais_chiffre_zero_par_la_source_dynamique(): void
    {
        app(LoopPluginAiModels::class)->assignPaid('aperio', self::PAYE, $this->superAdmin);

        // La source dynamique — les preuves de gratuite — n'a RIEN a dire
        // sur une ligne payante. Y compris FORGEE avec une fausse preuve
        // fraiche : c'est le filtre de TYPE qui protege, pas l'absence
        // naturelle de `verified_free_at` (assignPaid le met a null — un
        // sabotage du filtre resterait vert sans cette ligne forgee).
        LoopPluginAiModel::query()->where('assistant_key', 'aperio')
            ->first()->forceFill(['verified_free_at' => now()])->save();

        $this->assertNull(app(LoopPluginAiModels::class)->entryFor('openrouter', self::PAYE));

        // Et le catalogue complet rend le tarif STATIQUE, connu, non nul.
        $rate = AiPricingCatalog::rateFor('openrouter', self::PAYE);
        $this->assertNotNull($rate);
        $this->assertFalse($rate['free']);
        $this->assertSame(0.10, $rate['input_per_1m']);
        $this->assertSame(0.40, $rate['output_per_1m']);
    }

    public function test_la_shortlist_livree_est_entierement_tarifee(): void
    {
        // La garde de coherence du PRODUIT : chaque slug de la shortlist
        // livree (config/ai.php) doit avoir son entree EXACTE et non-free au
        // releve statique livre (config/ai_pricing.php). Un slug ajoute sans
        // tarif serait refuse a l'execution — autant rougir ICI, au commit.
        $shortlist = require base_path('config/ai.php');
        $shortlist = $shortlist['multi_ai']['paid_model_shortlist'] ?? [];

        $this->assertNotEmpty($shortlist, 'la shortlist livree ne doit pas etre vide');

        $pricing = require base_path('config/ai_pricing.php');

        foreach (array_keys($shortlist) as $slug) {
            $entree = $pricing['models']['openrouter'][strtolower($slug)] ?? null;

            $this->assertIsArray($entree, "{$slug} : aucune entree au releve statique");
            $this->assertGreaterThan(0, (float) ($entree['input_per_1m'] ?? 0) + (float) ($entree['output_per_1m'] ?? 0),
                "{$slug} : un payant a tarif nul serait une coquille");
        }
    }

    public function test_un_tour_paye_ecrit_un_cout_connu_au_ledger(): void
    {
        app(LoopPluginAiModels::class)->assignPaid('aperio', self::PAYE, $this->superAdmin);

        // 1 000 000 tokens d'entree, 500 000 de sortie : cout attendu
        // 1,0 x 0,10 + 0,5 x 0,40 = 0,30 USD. Des volumes ronds pour que le
        // calcul se verifie de tete.
        LoopMultiAiAgent::fake(fn (string $prompt, $attachments, $provider, string $model) => new TextResponse(
            'Reponse payante de '.$model, new Usage(1_000_000, 500_000), new Meta('openrouter', $model),
        ));

        $run = app(LoopMultiAiOrchestrator::class)->runOne(
            $this->loop, $this->membre, 'Faut-il tout automatiser ?', LoopMultiAiOrchestrator::ROLE_POUR,
        );

        $this->assertTrue($run->outcomes[0]->succeeded(), 'le tour paye aboutit');

        $ligne = AiProviderInvocation::query()->latest('created_at')->firstOrFail();
        $this->assertSame(self::PAYE, $ligne->model);
        $this->assertSame(AiProviderInvocation::STATUS_SUCCESS, $ligne->status,
            'la constante du ledger, pas `completed` : une ligne payante doit entrer dans les releves');
        $this->assertSame(AiProviderInvocation::COST_KNOWN, $ligne->cost_status,
            'jamais cost_status=unknown pour un payant autorise');
        $this->assertEqualsWithDelta(0.30, (float) $ligne->provider_cost, 0.000001);
    }

    public function test_un_refus_payant_ne_depense_rien(): void
    {
        app(LoopPluginAiModels::class)->assignPaid('aperio', self::PAYE, $this->superAdmin);
        config()->set('ai_pricing.models.openrouter.'.self::PAYE, null);

        LoopMultiAiAgent::fake(fn (string $prompt, $attachments, $provider, string $model) => new TextResponse(
            'ne doit jamais partir', new Usage(10, 10), new Meta('openrouter', $model),
        ));

        $run = app(LoopMultiAiOrchestrator::class)->runOne(
            $this->loop, $this->membre, 'Faut-il tout automatiser ?', LoopMultiAiOrchestrator::ROLE_POUR,
        );

        $this->assertSame('refused', $run->outcomes[0]->status);
        $this->assertSame(0, AiProviderInvocation::query()->count(),
            'un refus avant provider ne facture rien');
    }

    // ── 3bis. LE STATUT DU LEDGER — audit MASTER ────────────────────────────

    public function test_le_ledger_ecrit_le_domaine_canonique_jamais_completed(): void
    {
        // Le domaine est declare par la migration CREATRICE du ledger
        // (`// success | failed`, TASK-1220) — un mois avant ce moteur. La
        // colonne est un varchar(10) sans enum : rien n'empeche d'y ecrire
        // autre chose, et c'est precisement ce qui est arrive.
        app(LoopPluginAiModels::class)->assignPaid('aperio', self::PAYE, $this->superAdmin);

        LoopMultiAiAgent::fake(fn (string $prompt, $attachments, $provider, string $model) => new TextResponse(
            'Reponse de '.$model, new Usage(1_000, 500), new Meta('openrouter', $model),
        ));

        app(LoopMultiAiOrchestrator::class)->runOne(
            $this->loop, $this->membre, 'Faut-il tout automatiser ?', LoopMultiAiOrchestrator::ROLE_POUR,
        );

        $statuts = AiProviderInvocation::query()->pluck('status')->unique()->values()->all();

        $this->assertSame([AiProviderInvocation::STATUS_SUCCESS], $statuts);
        $this->assertNotContains('completed', $statuts,
            'un statut hors domaine echappe a TOUT filtre `status = success` : quota des couts inconnus, releves');
    }

    public function test_la_table_des_traces_garde_son_propre_vocabulaire(): void
    {
        // Garde de NON-CONTAMINATION : `ai_interactions.metadata.status` est
        // un AUTRE champ, dans une AUTRE table, avec son propre vocabulaire.
        // Le correctif du ledger ne doit pas s'y propager en collateral — un
        // seul des trois chemins de sortie avait ete change, ce qui laissait
        // deux vocabulaires dans la meme methode (mesure du 23/09).
        app(LoopPluginAiModels::class)->assignPaid('aperio', self::PAYE, $this->superAdmin);

        LoopMultiAiAgent::fake(fn (string $prompt, $attachments, $provider, string $model) => new TextResponse(
            'Reponse de '.$model, new Usage(1_000, 500), new Meta('openrouter', $model),
        ));

        app(LoopMultiAiOrchestrator::class)->runOne(
            $this->loop, $this->membre, 'Faut-il tout automatiser ?', LoopMultiAiOrchestrator::ROLE_POUR,
        );

        // Et le chemin HORS SUJET — c'est LUI qui avait ete contamine, et un
        // test qui ne visait que le succes normal restait vert sous sabotage
        // (mesure du 23/09). Les TROIS sorties de la methode doivent parler
        // la meme langue.
        LoopMultiAiAgent::fake(fn (string $prompt, $attachments, $provider, string $model) => new TextResponse(
            LoopMultiAiOrchestrator::MARQUEUR_HORS_SUJET, new Usage(1_000, 5), new Meta('openrouter', $model),
        ));

        app(LoopMultiAiOrchestrator::class)->runOne(
            $this->loop, $this->membre, 'Quel CMS choisir ?', LoopMultiAiOrchestrator::ROLE_POUR,
        );

        $vocabulaires = AiInteraction::query()->get()
            ->map(fn (AiInteraction $t): ?string => $t->metadata['status'] ?? null)
            ->unique()->values()->all();

        $this->assertSame(['completed'], $vocabulaires,
            'la table des traces n\'est pas le ledger : son vocabulaire ne change pas ici, sur AUCUNE sortie');
    }

    public function test_un_tour_hors_sujet_est_paye_et_compte_comme_tel(): void
    {
        // NOT_APPLICABLE au LEDGER : l'appel est REELLEMENT parti et il est
        // facture — `success` est donc juste du point de vue du PROVIDER.
        app(LoopPluginAiModels::class)->assignPaid('aperio', self::PAYE, $this->superAdmin);

        LoopMultiAiAgent::fake(fn (string $prompt, $attachments, $provider, string $model) => new TextResponse(
            LoopMultiAiOrchestrator::MARQUEUR_HORS_SUJET, new Usage(1_000_000, 0), new Meta('openrouter', $model),
        ));

        $run = app(LoopMultiAiOrchestrator::class)->runOne(
            $this->loop, $this->membre, 'Quel CMS choisir ?', LoopMultiAiOrchestrator::ROLE_POUR,
        );

        $this->assertTrue($run->outcomes[0]->isNotApplicable());

        $ligne = AiProviderInvocation::query()->latest('created_at')->firstOrFail();
        $this->assertSame(AiProviderInvocation::STATUS_SUCCESS, $ligne->status);
        $this->assertNull($ligne->failure_reason, 'ce n\'est pas une panne : la question n\'avait pas de camps');
        $this->assertEqualsWithDelta(0.10, (float) $ligne->provider_cost, 0.000001,
            'un tour hors sujet coute ce qu\'il a coute — l\'appel est parti');
    }

    public function test_pour_contre_n_est_pas_un_process_creditable(): void
    {
        // GARDE POUR TASK-1624 (mandat MASTER) : au ledger, un tour
        // NOT_APPLICABLE est indistinguable d'un debat reussi — meme statut,
        // aucun marqueur, `failure_reason` nul. La SEULE chose qui empeche
        // aujourd'hui une abstention d'etre comptee comme une utilisation
        // creditable, c'est que `loop_multi_ai` n'est pas un process
        // creditable. Ajouter ce process a la liste SANS discriminer
        // l'abstention ferait payer au membre une question a laquelle
        // personne n'a repondu. Ce test rougira ce jour-la.
        $this->assertNotContains('loop_multi_ai',
            \App\Services\Ai\OrganizationAiEconomicUsage::CREDITABLE_PROCESSES,
            'si ce process devient creditable, il faut D\'ABORD distinguer NOT_APPLICABLE au ledger');
    }

    public function test_aucune_sortie_du_moteur_n_ecrit_hors_du_domaine(): void
    {
        // PIN DE SEMANTIQUE (arbitrage MASTER, 23/09). Le domaine du ledger
        // est FERME : `success | failed`, declare par la migration creatrice
        // (TASK-1220). La colonne etant un `varchar(10)` sans enum, rien ne
        // l'impose structurellement — ce test est la seule barriere.
        //
        // Il exerce les TROIS sorties qui ecrivent une ligne, car pinner la
        // seule sortie « succes normal » laissait passer la regression
        // (mesure du 23/09, deux fois).
        app(LoopPluginAiModels::class)->assignPaid('aperio', self::PAYE, $this->superAdmin);

        $sorties = [
            // succes plein
            fn () => new TextResponse('Reponse complete', new Usage(1_000, 500), new Meta('openrouter', self::PAYE)),
            // abstention : l'appel est parti et il est paye
            fn () => new TextResponse(LoopMultiAiOrchestrator::MARQUEUR_HORS_SUJET, new Usage(1_000, 5), new Meta('openrouter', self::PAYE)),
            // reponse vide : le seul cas `failed` qui passe par ce chemin
            fn () => new TextResponse('', new Usage(1_000, 0), new Meta('openrouter', self::PAYE)),
        ];

        foreach ($sorties as $reponse) {
            LoopMultiAiAgent::fake(fn (string $prompt, $attachments, $provider, string $model) => $reponse());

            app(LoopMultiAiOrchestrator::class)->runOne(
                $this->loop, $this->membre, 'Faut-il tout automatiser ?', LoopMultiAiOrchestrator::ROLE_POUR,
            );
        }

        $ecrits = AiProviderInvocation::query()->pluck('status')->unique()->sort()->values()->all();

        $this->assertSame(3, AiProviderInvocation::query()->count(), 'les trois sorties ont bien ecrit');
        $this->assertSame(
            [AiProviderInvocation::STATUS_FAILED, AiProviderInvocation::STATUS_SUCCESS],
            $ecrits,
            'le domaine du ledger est ferme : success | failed, jamais un troisieme mot',
        );
    }

    // ── 4. LA ROUTE ET L'ECRAN ──────────────────────────────────────────────

    public function test_le_superadmin_approuve_un_payant_par_la_route(): void
    {
        $reponse = $this->actingAs($this->superAdmin)->put(
            route('admin.loop-plugins.models.update', self::PLUGIN),
            ['assistant_key' => 'aperio', 'model_slug' => self::PAYE, 'model_type' => 'paid_approved'],
        );

        $reponse->assertRedirect()->assertSessionHas('success');
        $this->assertDatabaseHas('loop_plugin_ai_models', [
            'assistant_key' => 'aperio',
            'model_slug' => self::PAYE,
            'model_type' => LoopPluginAiModel::TYPE_PAID_APPROVED,
            'approved_by' => $this->superAdmin->id,
        ]);
    }

    public function test_un_non_superadmin_n_approuve_rien(): void
    {
        $this->actingAs($this->membre)->put(
            route('admin.loop-plugins.models.update', self::PLUGIN),
            ['assistant_key' => 'aperio', 'model_slug' => self::PAYE, 'model_type' => 'paid_approved'],
        )->assertForbidden();

        $this->assertDatabaseCount('loop_plugin_ai_models', 0);
    }

    public function test_un_post_sans_type_reste_un_geste_gratuit(): void
    {
        // Retro-compatibilite : le formulaire d'avant TASK-1622 ne postait
        // pas `model_type` — son sens ne change pas.
        $this->actingAs($this->superAdmin)->put(
            route('admin.loop-plugins.models.update', self::PLUGIN),
            ['assistant_key' => 'aperio', 'model_slug' => self::LIBRE],
        )->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseHas('loop_plugin_ai_models', [
            'assistant_key' => 'aperio',
            'model_type' => LoopPluginAiModel::TYPE_FREE_VERIFIED,
        ]);
    }

    public function test_un_post_paye_hors_shortlist_est_refuse_avec_message(): void
    {
        $this->actingAs($this->superAdmin)->put(
            route('admin.loop-plugins.models.update', self::PLUGIN),
            ['assistant_key' => 'aperio', 'model_slug' => 'vendor/inconnu', 'model_type' => 'paid_approved'],
        )->assertRedirect()->assertSessionHas('error');

        $this->assertDatabaseCount('loop_plugin_ai_models', 0);
    }

    public function test_l_ecran_montre_le_choix_le_tarif_et_le_badge_paye(): void
    {
        app(LoopPluginAiModels::class)->assignPaid('aperio', self::PAYE, $this->superAdmin);

        $reponse = $this->actingAs($this->superAdmin)->get(route('admin.loop-plugins'));

        $reponse->assertOk()
            // Le choix de type, par role.
            ->assertSee('data-model-type-choice="aperio"', false)
            ->assertSee('data-paid-model-select="aperio"', false)
            ->assertSee(__('loops.plugins_models_type_free'))
            ->assertSee(__('loops.plugins_models_type_paid'))
            // Le badge du contrat payant eligible.
            ->assertSee('data-model-status="paid"', false)
            ->assertSee(__('loops.plugins_models_status_paid'))
            // L'audit se lit : auteur et tarif.
            ->assertSee($this->superAdmin->name)
            ->assertSee('0,100', false)
            ->assertSee('0,400', false);
    }

    public function test_une_approbation_au_tarif_disparu_s_affiche_refusee(): void
    {
        app(LoopPluginAiModels::class)->assignPaid('aperio', self::PAYE, $this->superAdmin);
        config()->set('ai_pricing.models.openrouter.'.self::PAYE, null);

        $this->actingAs($this->superAdmin)->get(route('admin.loop-plugins'))
            ->assertOk()
            ->assertSee('data-model-status="paid_invalid"', false)
            ->assertSee(__('loops.plugins_models_status_paid_invalid'));
    }

    // ── Outils ──────────────────────────────────────────────────────────────

    /**
     * Le catalogue OpenRouter double : un modele GRATUIT verifie, deux
     * modeles PAYANTS connus (pricing non nul), et un payant hors shortlist.
     */
    private function fakeCatalogue(): void
    {
        Http::swap(new Factory);
        Http::fake(['*/models' => Http::response(['data' => [
            $this->entreeCatalogue(self::LIBRE, '0', '0'),
            $this->entreeCatalogue(self::PAYE, '0.0000001', '0.0000004'),
            $this->entreeCatalogue(self::PAYE_SANS_TARIF, '0.0000002', '0.0000002'),
            $this->entreeCatalogue('vendor/inconnu', '0.0000003', '0.0000003'),
        ]], 200)]);
    }

    /** @return array<string, mixed> */
    private function entreeCatalogue(string $slug, string $prompt, string $completion): array
    {
        return [
            'id' => $slug,
            'name' => 'Modele '.$slug,
            'context_length' => 32768,
            'pricing' => ['prompt' => $prompt, 'completion' => $completion, 'request' => '0'],
            'architecture' => ['input_modalities' => ['text'], 'output_modalities' => ['text']],
        ];
    }
}
