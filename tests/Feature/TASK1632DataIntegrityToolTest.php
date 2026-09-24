<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\DerivedKnowledgeNote;
use App\Models\Dossier;
use App\Models\DossierChunk;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use App\Services\Dossiers\DossierTreePurger;
use App\Services\Integrity\DataIntegrityService;
use App\Support\Integrity\IntegrityCheck;
use App\Support\Integrity\IntegrityStatus;
use App\Support\Integrity\SchemaReferenceInspector;
use App\Support\Integrity\UnprotectedReferenceRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-1632 — le cockpit « Integrite des donnees ».
 *
 * Deux exigences qui se contredisent en apparence, et que ces tests tiennent
 * ensemble :
 *
 *   · l'outil doit VOIR ce qui est reellement casse — sinon il rassure a
 *     tort ;
 *   · il ne doit JAMAIS appeler « orphelin » ce qui est normal : un NULL
 *     global, un ledger qui survit volontairement a son Organization, un
 *     extrait attache a un Dossier en corbeille.
 *
 * Un outil qui crie au feu sur la moitie des lignes ne sera plus lu, et un
 * outil qui ne dit jamais rien ne sert a rien.
 */
class TASK1632DataIntegrityToolTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $superAdmin;

    private User $membre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create(['is_active' => true, 'is_default' => true, 'slug' => 'org-a-1632']);
        $this->orgB = Organization::factory()->create(['is_active' => true, 'loops_enabled' => true, 'slug' => 'org-b-1632']);

        $this->superAdmin = User::factory()->create(['organization_id' => $this->orgA->id, 'is_admin' => true]);
        $this->membre = User::factory()->create(['organization_id' => $this->orgA->id, 'is_admin' => false]);
    }

    // ── A. Le cockpit repond, et il est en LECTURE SEULE ──────────────────

    public function test_the_cockpit_opens_and_shows_every_check(): void
    {
        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.outils.integrite'))
            ->assertOk()
            ->getContent();

        foreach (app(DataIntegrityService::class)->all() as $check) {
            $this->assertStringContainsString(__($check->labelKey()), $html);
        }
    }

    /**
     * « L'outil ne mute rien » est une promesse qui doit se prouver.
     *
     * On interroge le routeur, pas le code : toute route nommee
     * `admin.outils.integrite*` doit n'accepter que GET/HEAD. Une route de
     * purge ajoutee demain ferait rougir ce test.
     */
    public function test_no_integrity_route_accepts_anything_but_a_get(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with((string) $route->getName(), 'admin.outils.integrite'));

        $this->assertGreaterThan(0, $routes->count(), 'Les routes du cockpit devraient exister.');

        foreach ($routes as $route) {
            $this->assertSame(
                ['GET', 'HEAD'],
                $route->methods(),
                "La route {$route->getName()} doit rester en lecture seule."
            );
        }
    }

    public function test_opening_the_cockpit_writes_nothing(): void
    {
        $this->loopAvecDossier($this->orgB);

        $avant = [
            'dossiers' => DB::table('dossiers')->count(),
            'loops' => DB::table('loops')->count(),
            'users' => DB::table('users')->count(),
            'organizations' => DB::table('organizations')->count(),
        ];

        $this->actingAs($this->superAdmin)->get(route('admin.outils.integrite'))->assertOk();

        foreach ($avant as $table => $compte) {
            $this->assertSame($compte, DB::table($table)->count(), "Le cockpit a modifie {$table}.");
        }
    }

    // ── B. Organizations ──────────────────────────────────────────────────

    public function test_a_healthy_base_reports_no_broken_organization_reference(): void
    {
        $check = app(DataIntegrityService::class)->organizationReferences();

        $this->assertSame(IntegrityStatus::Ok, $check->status);
        $this->assertSame(0, $check->count);
    }

    /**
     * Depuis TASK-1633, plus AUCUNE table n'est classee `UNGUARDED` : la
     * derniere divergence (`referrals` / `referral_rewards`) a recu sa cle
     * etrangere, et `ai_provider_invocations` est une trace volontaire.
     *
     * C'est un progres — il n'y a plus rien a surveiller — mais la CAPACITE
     * de detection doit rester prouvee, sinon elle se degraderait sans que
     * personne ne le voie. On declare donc, le temps du test, une table
     * reellement sans contrainte (`ai_provider_invocations`, sans FK sur les
     * deux moteurs) comme `UNGUARDED`.
     *
     * Seule la CLASSIFICATION change : la table, la donnee et le chemin de
     * code sont les vrais. C'est d'ailleurs la seconde chose que ce test
     * prouve — que c'est bien la classification, et elle seule, qui decide
     * entre « action requise » et « trace historique ».
     */
    public function test_a_reference_to_a_vanished_organization_is_action_required(): void
    {
        $this->invocationPointantUneOrganisationAbsente();
        $this->declareUnguarded('ai_provider_invocations');

        $check = app(DataIntegrityService::class)->organizationReferences();

        $this->assertSame(IntegrityStatus::ActionRequired, $check->status);
        $this->assertSame(1, $check->count);
    }

    /**
     * Et sans cette declaration, la MEME ligne reste une trace historique.
     */
    public function test_the_same_row_is_only_history_when_it_is_classified_as_such(): void
    {
        $this->invocationPointantUneOrganisationAbsente();

        $service = app(DataIntegrityService::class);

        $this->assertSame(IntegrityStatus::Ok, $service->organizationReferences()->status);
        $this->assertSame(IntegrityStatus::Information, $service->providerLedgerHistory()->status);
    }

    /**
     * TASK-1633 : l'etat sain attendu du produit — aucune table sans cle
     * etrangere hors exception volontaire, donc rien a surveiller.
     */
    public function test_no_table_is_unguarded_anymore(): void
    {
        $registry = app(UnprotectedReferenceRegistry::class);

        $this->assertSame([], $registry->unguardedTables('organization_id'));
        $this->assertSame(['ai_provider_invocations'], $registry->protectedHistoryTables('organization_id'));
    }

    public function test_the_cockpit_surfaces_the_broken_reference_in_its_summary(): void
    {
        $this->invocationPointantUneOrganisationAbsente();
        $this->declareUnguarded('ai_provider_invocations');

        $service = app(DataIntegrityService::class);

        $this->assertSame(IntegrityStatus::ActionRequired, $service->worstStatus());
        $this->assertSame(1, $service->summary()[IntegrityStatus::ActionRequired->value]);
    }

    /**
     * Un `organization_id` NULL n'est PAS une reference cassee.
     *
     * C'est le coeur de la doctrine : supprimer une Organization detache ses
     * utilisateurs (`AdminOrganizationController::destroy()` les passe a
     * NULL). Ces lignes relevent d'assign-data, pas d'une alerte rouge.
     */
    public function test_a_null_organization_id_is_never_a_broken_reference(): void
    {
        $user = User::factory()->create(['organization_id' => null]);
        DB::table('users')->where('id', $user->id)->update(['organization_id' => null]);

        $service = app(DataIntegrityService::class);

        $this->assertSame(IntegrityStatus::Ok, $service->organizationReferences()->status);
        // Il ressort ailleurs, et sous un autre statut.
        $this->assertSame(IntegrityStatus::Watch, $service->unscopedData()->status);
    }

    /**
     * Un NULL legitime (referentiel global) ne compte pas comme « a
     * traiter » : il est compte a part.
     */
    public function test_a_global_null_is_counted_apart_from_what_must_be_assigned(): void
    {
        DB::table('categories')->insert([
            'id' => (string) Str::uuid(), 'name_b2c' => 'Globale', 'name_b2b' => 'Globale',
            'slug' => 'globale-1632', 'organization_id' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $avant = app(DataIntegrityService::class)->unscopedData();

        $this->assertGreaterThan(0, $avant->replacements['legitimate']);
    }

    /**
     * TASK-1633 : l'assertion est devenue STRUCTURELLE.
     *
     * Elle chiffrait « 110 tables, 3 sans contrainte, 107 protegees ». Ces
     * nombres bougent des qu'une table apparait — et ils ont bouge : la
     * migration de convergence en a retire deux de la colonne « sans
     * contrainte ». Ce qui doit rester vrai n'est pas un compte mais un
     * contrat : le nombre annonce est le total moins les tables sans
     * contrainte, et la couverture reste massive.
     */
    public function test_the_schema_coverage_check_reports_what_the_database_guarantees(): void
    {
        $check = app(DataIntegrityService::class)->organizationForeignKeyCoverage();
        $inspector = app(SchemaReferenceInspector::class);

        $total = count($inspector->tablesWithColumn('organization_id'));
        $sans = count($inspector->tablesWithoutForeignKey('organization_id'));

        $this->assertSame(IntegrityStatus::Information, $check->status);
        $this->assertSame($total, $check->replacements['total']);
        $this->assertSame($sans, $check->replacements['without']);
        $this->assertSame($total - $sans, $check->count);

        // Et la mesure garde un sens : la protection est massive, pas
        // anecdotique.
        $this->assertGreaterThan(100, $check->count);
    }

    // ── C. Le ledger : historique PROTEGE, jamais une action ──────────────

    /**
     * La garde absolue du mandat §8.
     *
     * `ai_provider_invocations` porte volontairement l'UUID d'Organizations
     * disparues : la cle etrangere a ete retiree par une migration dediee
     * pour que la facturation survive. Ces lignes ne doivent JAMAIS etre
     * comptees comme cassees, ni declencher une action.
     */
    public function test_the_ledger_history_is_information_and_never_action_required(): void
    {
        $this->invocationPointantUneOrganisationAbsente();

        $service = app(DataIntegrityService::class);

        $ledger = $service->providerLedgerHistory();
        $this->assertSame(IntegrityStatus::Information, $ledger->status);
        $this->assertSame(1, $ledger->count);
        $this->assertSame(1, $ledger->replacements['organizations']);

        // Et surtout : elle ne contamine PAS le controle des references
        // cassees, qui reste OK...
        $this->assertSame(IntegrityStatus::Ok, $service->organizationReferences()->status);

        // ...et elle ne declenche aucune action requise nulle part.
        // L'assertion porte sur CET invariant et non sur `worstStatus()`, qui
        // agrege aussi des controles sans rapport (du legacy sans
        // Organization suffit a le mettre en « a surveiller ») : elle serait
        // alors verte ou rouge pour des raisons etrangeres au ledger.
        $this->assertSame(
            0,
            $service->summary()[IntegrityStatus::ActionRequired->value],
            'Le ledger ne doit jamais faire apparaitre une action requise.'
        );
    }

    public function test_the_ledger_is_declared_as_protected_history_not_as_unguarded(): void
    {
        $registry = app(UnprotectedReferenceRegistry::class);

        $this->assertContains('ai_provider_invocations', $registry->protectedHistoryTables('organization_id'));
        $this->assertNotContains('ai_provider_invocations', $registry->unguardedTables('organization_id'));
    }

    public function test_the_ledger_rows_are_never_touched_by_the_cockpit(): void
    {
        $this->invocationPointantUneOrganisationAbsente();
        $avant = DB::table('ai_provider_invocations')->count();

        $this->actingAs($this->superAdmin)->get(route('admin.outils.integrite'))->assertOk();
        $this->actingAs($this->superAdmin)
            ->get(route('admin.outils.integrite.detail', ['check' => 'provider_ledger_history']))
            ->assertOk();

        $this->assertSame($avant, DB::table('ai_provider_invocations')->count());
    }

    // ── D. Boucles ────────────────────────────────────────────────────────

    public function test_loop_references_are_ok_and_say_what_the_schema_guarantees(): void
    {
        $this->loopAvecDossier($this->orgB);

        $check = app(DataIntegrityService::class)->loopReferences();

        $this->assertSame(IntegrityStatus::Ok, $check->status);
        $this->assertSame(0, $check->count);
        // 24 tables portent `loop_id`, aucune sans contrainte.
        $this->assertSame(24, $check->replacements['tables']);
        $this->assertSame(0, $check->replacements['unprotected']);
    }

    /**
     * Le chemin officiel de TASK-1630 ne laisse aucun residu signale.
     */
    public function test_deleting_a_loop_through_the_official_path_leaves_nothing_broken(): void
    {
        $loop = $this->loopAvecDossier($this->orgB);

        $this->actingAs($this->superAdmin)
            ->delete(route('admin.loops.destroy', ['loop' => $loop->getKey()]))
            ->assertRedirect();

        $service = app(DataIntegrityService::class);

        $this->assertSame(IntegrityStatus::Ok, $service->loopReferences()->status);
        $this->assertSame(IntegrityStatus::Ok, $service->dossierReferences()->status);
        $this->assertSame(IntegrityStatus::Ok, $service->dossierSoftDeletedRagResidues()->status);
    }

    // ── E. Dossiers ───────────────────────────────────────────────────────

    public function test_a_live_dossier_with_chunks_is_ok(): void
    {
        $dossier = $this->dossierPersonnel();
        $this->chunk($dossier);

        $check = app(DataIntegrityService::class)->dossierSoftDeletedRagResidues();

        $this->assertSame(IntegrityStatus::Ok, $check->status);
        $this->assertSame(0, $check->count);
    }

    /**
     * Le cas mesure du §11 : la suppression DOUCE laisse les extraits.
     *
     * `DossierController::destroy()` nettoie Series, Articles, fichiers et
     * membres, mais ni `dossier_chunks` ni `derived_knowledge_notes` — la
     * ligne `dossiers` restant en base, aucune cascade ne se declenche.
     * C'est « a surveiller », pas « casse » : aucune decision produit ne fixe
     * encore la duree de conservation.
     */
    public function test_a_trashed_dossier_still_carrying_excerpts_is_to_be_watched(): void
    {
        $dossier = $this->dossierPersonnel();
        $this->chunk($dossier);
        $this->note($dossier);

        $dossier->delete();

        $check = app(DataIntegrityService::class)->dossierSoftDeletedRagResidues();

        $this->assertSame(IntegrityStatus::Watch, $check->status);
        $this->assertSame(2, $check->count);
        $this->assertSame(1, $check->replacements['chunks']);
        $this->assertSame(1, $check->replacements['notes']);
        $this->assertSame(1, $check->replacements['dossiers']);
        // Jamais « action requise » : la reference est valide.
        $this->assertNotSame(IntegrityStatus::ActionRequired, $check->status);
    }

    public function test_the_trashed_dossier_detail_names_the_tenant_and_the_counts(): void
    {
        $dossier = $this->dossierPersonnel();
        $this->chunk($dossier);
        $dossier->delete();

        $reponse = $this->actingAs($this->superAdmin)
            ->get(route('admin.outils.integrite.detail', ['check' => 'dossier_soft_deleted_rag_residues']))
            ->assertOk();

        $rows = $reponse->viewData('rows');

        $this->assertSame(1, $rows->total());
        $ligne = $rows->items()[0];
        $this->assertSame($dossier->getKey(), $ligne->id);
        $this->assertSame($this->orgA->id, $ligne->organization_id);
        $this->assertSame(1, (int) $ligne->chunks);
    }

    /**
     * Le chemin SuperAdmin de TASK-1630, lui, emporte les extraits.
     */
    public function test_a_purged_dossier_leaves_no_residue_at_all(): void
    {
        $loop = $this->loopAvecDossier($this->orgB);
        $racine = Dossier::where('loop_id', $loop->getKey())->firstOrFail();
        $enfant = Dossier::create([
            'organization_id' => $this->orgB->id,
            'parent_id' => $racine->getKey(),
            'name' => 'Branche legacy',
            'visibility' => Dossier::VISIBILITY_LOOP,
        ]);
        $this->chunk($enfant, $this->orgB);

        app(DossierTreePurger::class)->purge([$enfant->fresh()]);

        $check = app(DataIntegrityService::class)->dossierSoftDeletedRagResidues();

        $this->assertSame(IntegrityStatus::Ok, $check->status);
        $this->assertSame(0, DB::table('dossier_chunks')->where('dossier_id', $enfant->getKey())->count());
    }

    public function test_legacy_subfolders_are_counted_and_point_at_the_cleanup_tool(): void
    {
        $loop = $this->loopAvecDossier($this->orgB);
        $racine = Dossier::where('loop_id', $loop->getKey())->firstOrFail();
        Dossier::create([
            'organization_id' => $this->orgB->id,
            'parent_id' => $racine->getKey(),
            'name' => 'Sous-dossier legacy',
            'visibility' => Dossier::VISIBILITY_LOOP,
        ]);

        $check = app(DataIntegrityService::class)->legacySubfolders();
        $this->assertSame(1, $check->count);
        $this->assertSame(IntegrityStatus::Watch, $check->status);

        // Le cockpit renvoie vers TASK-1630 plutot que de reimplementer la purge.
        $this->assertStringContainsString(
            route('admin.outils.dossiers', ['type' => 'legacy_child']),
            $this->actingAs($this->superAdmin)->get(route('admin.outils.integrite'))->getContent()
        );
    }

    // ── F. Multi-tenant ───────────────────────────────────────────────────

    public function test_the_detail_identifies_the_right_tenant_and_never_mixes_them(): void
    {
        $dossierA = $this->dossierPersonnel();
        $this->chunk($dossierA);
        $dossierA->delete();

        $userB = User::factory()->create(['organization_id' => $this->orgB->id]);
        $dossierB = Dossier::create([
            'organization_id' => $this->orgB->id,
            'owner_id' => $userB->id,
            'name' => 'Documents Org B',
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);
        $this->chunk($dossierB, $this->orgB);
        $dossierB->delete();

        $rows = $this->actingAs($this->superAdmin)
            ->get(route('admin.outils.integrite.detail', ['check' => 'dossier_soft_deleted_rag_residues']))
            ->assertOk()
            ->viewData('rows');

        $parOrganisation = collect($rows->items())->pluck('organization_id', 'id');

        $this->assertSame($this->orgA->id, $parOrganisation[$dossierA->getKey()]);
        $this->assertSame($this->orgB->id, $parOrganisation[$dossierB->getKey()]);
    }

    // ── G. Details et autorisation ────────────────────────────────────────

    public function test_an_unknown_detail_is_a_clean_404(): void
    {
        $this->actingAs($this->superAdmin)
            ->get(route('admin.outils.integrite.detail', ['check' => 'nexiste-pas']))
            ->assertNotFound();
    }

    /**
     * Un detail sans ligne n'est pas offert : un lien qui ouvre une page vide
     * est pire que pas de lien.
     */
    public function test_a_check_with_no_row_offers_no_detail_link(): void
    {
        $check = app(DataIntegrityService::class)->all()
            ->first(fn (IntegrityCheck $c) => $c->key === 'organization_broken_references');

        $this->assertSame(0, $check->count);
        $this->assertFalse($check->hasDetail());
    }

    public function test_a_guest_is_refused(): void
    {
        $this->get(route('admin.outils.integrite'))->assertRedirect(route('login'));
    }

    public function test_a_plain_member_is_refused(): void
    {
        $this->actingAs($this->membre)->get(route('admin.outils.integrite'))->assertForbidden();
        $this->actingAs($this->membre)
            ->get(route('admin.outils.integrite.detail', ['check' => 'provider_ledger_history']))
            ->assertForbidden();
    }

    /**
     * Le cockpit voit TOUTES les Organizations : il est derriere le
     * middleware plateforme (`users.is_admin`), pas derriere OrgAdmin
     * (`organizations.admin_id`).
     */
    public function test_an_org_admin_who_is_not_platform_admin_is_refused(): void
    {
        $orgAdmin = User::factory()->create(['organization_id' => $this->orgA->id, 'is_admin' => false]);
        $this->orgA->update(['admin_id' => $orgAdmin->id]);

        $this->actingAs($orgAdmin)->get(route('admin.outils.integrite'))->assertForbidden();
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function loopAvecDossier(Organization $org): Loop
    {
        $loop = Loop::factory()->create([
            'organization_id' => $org->id, 'status' => 'active', 'type' => 'general',
            'created_by' => $this->superAdmin->id,
        ]);
        LoopMember::factory()->owner()->create([
            'loop_id' => $loop->id, 'user_id' => $this->superAdmin->id, 'joined_at' => now(),
        ]);
        Dossier::create([
            'organization_id' => $org->id, 'owner_id' => null, 'loop_id' => $loop->id,
            'name' => 'Documents de la Boucle', 'visibility' => Dossier::VISIBILITY_LOOP,
        ]);

        return $loop;
    }

    private function dossierPersonnel(): Dossier
    {
        return Dossier::create([
            'organization_id' => $this->orgA->id,
            'owner_id' => $this->membre->id,
            'name' => 'Mes documents',
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);
    }

    /**
     * Un extrait RAG, ecrit avec l'idiome du depot : `DossierChunk::create()`
     * et un vecteur de la dimension du moteur (1536 en pgvector, 8 en
     * SQLite). Un `DB::table()->insert()` a la main omettrait `content_hash`,
     * `embedding`, `indexed_at`... toutes NOT NULL.
     */
    private function chunk(Dossier $dossier, ?Organization $org = null): void
    {
        $organization = $org ?? $this->orgA;

        $article = BlogPost::create([
            'user_id' => $this->membre->id,
            'organization_id' => $organization->id,
            'title' => 'Source '.Str::random(6),
            'slug' => 'source-'.Str::random(8),
            'content' => '<p>Contenu.</p>',
            'status' => 'draft',
        ]);

        DossierChunk::create([
            'organization_id' => $organization->id,
            'dossier_id' => $dossier->getKey(),
            'blog_post_id' => $article->id,
            'chunk_index' => 0,
            'content' => 'Un extrait.',
            'content_hash' => hash('sha256', (string) Str::uuid()),
            'token_count' => 3,
            'embedding' => array_fill(0, DB::connection()->getDriverName() === 'pgsql' ? 1536 : 8, 0.1),
            'embedding_provider' => 'openrouter',
            'embedding_model' => 'text-embedding-3-small',
            'indexed_at' => now(),
        ]);
    }

    private function note(Dossier $dossier): void
    {
        DerivedKnowledgeNote::create([
            'organization_id' => $this->orgA->id,
            'source_type' => DerivedKnowledgeNote::SOURCE_LOOP_CONVERSATION,
            'kind' => DerivedKnowledgeNote::KIND_CLAIM,
            'dossier_id' => $dossier->getKey(),
            'subject_key' => 'sujet-'.Str::random(6),
            'content' => 'Une connaissance derivee.',
            'source_fingerprint' => hash('sha256', (string) Str::uuid()),
            'provenance' => ['source_loop_message_ids' => [], 'derived_by' => 'test'],
            'observed_at' => now(),
            'derived_at' => now(),
            'version' => 1,
            'status' => 'active',
        ]);
    }

    /**
     * Declare une table comme `UNGUARDED` le temps d'un test.
     *
     * La doublure ne remplace QUE la liste d'entree : le service garde sa
     * vraie requete, sur la vraie table, avec la vraie donnee. C'est la
     * difference entre eprouver un chemin de code et simuler son resultat.
     */
    private function declareUnguarded(string $table): void
    {
        app()->instance(UnprotectedReferenceRegistry::class, new class($table) extends UnprotectedReferenceRegistry
        {
            public function __construct(private readonly string $table) {}

            public function all(): array
            {
                return [
                    'organization_id' => [$this->table => self::UNGUARDED],
                    'loop_id' => [],
                    'dossier_id' => [],
                ];
            }
        });
    }

    private function invocationPointantUneOrganisationAbsente(): void
    {
        DB::table('ai_provider_invocations')->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => (string) Str::uuid(),
            'provider' => 'anthropic',
            'model' => 'claude-opus-5',
            'operation' => 'chat',
            'status' => 'success',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
