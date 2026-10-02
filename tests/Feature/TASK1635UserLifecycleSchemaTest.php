<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Services\UserDataLifecycleRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * TASK-1635 — le schema tient enfin la promesse du registre.
 *
 * `UserDataLifecycleRegistry` declarait depuis longtemps ce que chaque donnee
 * doit devenir quand un User disparait. Le schema, lui, disait CASCADE partout :
 * une donnee declaree durable etait detruite, un BLOCK n'empechait rien.
 *
 * Ces tests mesurent le COMPORTEMENT reel de la base, jamais le seul catalogue :
 * on cree la ligne, on supprime le User, et on regarde ce qui reste.
 *
 * Aucun executeur de suppression n'existe encore (TASK-1636) : les tests
 * suppriment donc le User au niveau DB, ce qui est precisement le cas que le
 * schema doit tenir tout seul.
 */
class TASK1635UserLifecycleSchemaTest extends TestCase
{
    private Organization $organization;

    /** Le User dont on mesure la disparition. */
    private User $user;

    /** Un tiers : porte les dependances parentes, pour qu'elles ne bloquent pas. */
    private User $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->user = User::factory()->for($this->organization)->create();
        $this->other = User::factory()->for($this->organization)->create();
    }

    // =====================================================================
    // GROUPE A — 17 colonnes ANONYMIZE / RETAIN : la ligne SURVIT, detachee
    // =====================================================================

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function safeSetNullColumns(): array
    {
        return [
            'blog_annotation_replies.user_id' => ['blog_annotation_replies', 'user_id'],
            'blog_comments.user_id' => ['blog_comments', 'user_id'],
            'blog_post_annotations.user_id' => ['blog_post_annotations', 'user_id'],
            'blog_todo_threads.user_id' => ['blog_todo_threads', 'user_id'],
            'blog_todos.user_id' => ['blog_todos', 'user_id'],
            'feed_post_comments.user_id' => ['feed_post_comments', 'user_id'],
            'loop_roadmap_item_messages.user_id' => ['loop_roadmap_item_messages', 'user_id'],
            'loop_roadmap_items.created_by' => ['loop_roadmap_items', 'created_by'],
            'member_ai_profile_interactions.profile_owner_user_id' => ['member_ai_profile_interactions', 'profile_owner_user_id'],
            'profile_agent_conversations.profile_owner_user_id' => ['profile_agent_conversations', 'profile_owner_user_id'],
            'reviews.reviewed_id' => ['reviews', 'reviewed_id'],
            'reviews.reviewer_id' => ['reviews', 'reviewer_id'],
            'login_logs.user_id' => ['login_logs', 'user_id'],
            'referral_rewards.user_id' => ['referral_rewards', 'user_id'],
            'reports.reporter_id' => ['reports', 'reporter_id'],
            'referrals.referrer_user_id' => ['referrals', 'referrer_user_id'],
            'referrals.referred_user_id' => ['referrals', 'referred_user_id'],
        ];
    }

    #[DataProvider('safeSetNullColumns')]
    public function test_la_ligne_survit_au_user_et_son_attribution_devient_null(string $table, string $column): void
    {
        $rowId = $this->insertDependentRow($table, $column);

        $this->deleteUserAtDatabaseLevel($this->user);

        $row = DB::table($table)->where('id', $rowId)->first();

        $this->assertNotNull(
            $row,
            "{$table}.{$column} : la ligne a ete DETRUITE alors que le registre la declare durable."
        );
        $this->assertNull(
            $row->{$column},
            "{$table}.{$column} : la ligne survit mais reste attribuee a un User supprime."
        );
    }

    // =====================================================================
    // GROUPE B — 4 proprietes TRANSFER : REPORTE a TASK-1636
    // =====================================================================
    //
    // `blog_posts`, `feed_posts`, `services` et `service_requests` gardent leur
    // policy TRANSFER au registre (verifiee plus bas) et restent `ON DELETE
    // CASCADE` dans le schema : la migration M3 qui devait les passer en
    // RESTRICT est REPORTEE a TASK-1636, sur arbitrage du 24/09.
    //
    // La raison est mesuree, pas theorique : `LoopRootDocumentService` cree un
    // article racine par Boucle, attribue au membre qui la cree et jamais
    // declare au registre des ScenarioPacks. Sous CASCADE il disparaissait avec
    // son auteur ; sous RESTRICT il interdit toute suppression — y compris
    // celle, legitime, du retrait d'un pack.
    //
    // Un RESTRICT sur une colonne TRANSFER ne protege vraiment que si un
    // executeur sait transferer. Tant que TASK-1636 ne l'a pas ecrit, il
    // n'empeche pas une perte de donnees : il empeche toute suppression.
    // TASK-1636 posera donc ce RESTRICT en meme temps qu'elle apprendra a
    // transferer, et traitera ce provisioning automatique.

    // =====================================================================
    // GROUPE C — 8 relations BLOCK : la suppression est REFUSEE
    // =====================================================================

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function blockRestrictColumns(): array
    {
        return [
            // Addendum 24/09 21h53 : `organizations.admin_id` rejoint le groupe
            // (elle venait de SET NULL, pas de CASCADE) ; `member_ai_profiles`
            // en sort — sa policy devient DELETE et sa FK reste CASCADE.
            'organizations.admin_id' => ['organizations', 'admin_id'],
            'dossiers.owner_id' => ['dossiers', 'owner_id'],
            'point_ledger.user_id' => ['point_ledger', 'user_id'],
            'loop_poll_votes.user_id' => ['loop_poll_votes', 'user_id'],
            'course_submissions.user_id' => ['course_submissions', 'user_id'],
            'course_quiz_attempts.user_id' => ['course_quiz_attempts', 'user_id'],
            'transactions.buyer_id' => ['transactions', 'buyer_id'],
            'transactions.seller_id' => ['transactions', 'seller_id'],
        ];
    }

    #[DataProvider('blockRestrictColumns')]
    public function test_une_relation_bloquante_empeche_la_suppression(string $table, string $column): void
    {
        $rowId = $this->insertDependentRow($table, $column);

        $this->assertDeletionIsRefused($table, $column);

        $row = DB::table($table)->where('id', $rowId)->first();
        $this->assertNotNull($row, "{$table} : la donnee bloquante a disparu malgre le refus.");
        $this->assertSame($this->user->id, $row->{$column}, "{$table}.{$column} : l'attribution a change.");
        $this->assertDatabaseHas('users', ['id' => $this->user->id]);
    }

    // =====================================================================
    // §6 et §7 — les contraintes UNIQUE tolerent-elles plusieurs NULL ?
    // =====================================================================

    public function test_plusieurs_avis_de_la_meme_transaction_survivent_avec_un_auteur_null(): void
    {
        // unique(transaction_id, reviewer_id) : si NULL comptait comme une
        // valeur ordinaire, le SET NULL du second avis heurterait le premier
        // et la suppression du User echouerait — ou pire, detruirait la ligne.
        $transactionId = $this->insertTransaction($this->other->id, $this->other->id);

        $secondReviewer = User::factory()->for($this->organization)->create();

        $first = $this->insertReview($transactionId, $this->user->id, $this->other->id);
        $second = $this->insertReview($transactionId, $secondReviewer->id, $this->other->id);

        $this->deleteUserAtDatabaseLevel($this->user);
        $this->deleteUserAtDatabaseLevel($secondReviewer);

        $this->assertDatabaseHas('reviews', ['id' => $first, 'reviewer_id' => null]);
        $this->assertDatabaseHas('reviews', ['id' => $second, 'reviewer_id' => null]);

        $this->assertSame(
            2,
            DB::table('reviews')->where('transaction_id', $transactionId)->whereNull('reviewer_id')->count(),
            'Deux avis de la meme transaction doivent pouvoir coexister avec reviewer_id NULL.'
        );
    }

    public function test_plusieurs_parrainages_survivent_avec_des_acteurs_null(): void
    {
        // unique(organization_id, referrer_user_id, referred_user_id).
        $referrerA = User::factory()->for($this->organization)->create();
        $referrerB = User::factory()->for($this->organization)->create();
        $referredA = User::factory()->for($this->organization)->create();
        $referredB = User::factory()->for($this->organization)->create();

        $first = $this->insertReferral($referrerA->id, $referredA->id);
        $second = $this->insertReferral($referrerB->id, $referredB->id);

        foreach ([$referrerA, $referrerB, $referredA, $referredB] as $user) {
            $this->deleteUserAtDatabaseLevel($user);
        }

        $this->assertDatabaseHas('referrals', ['id' => $first, 'referrer_user_id' => null, 'referred_user_id' => null]);
        $this->assertDatabaseHas('referrals', ['id' => $second, 'referrer_user_id' => null, 'referred_user_id' => null]);

        $this->assertSame(
            2,
            DB::table('referrals')
                ->where('organization_id', $this->organization->id)
                ->whereNull('referrer_user_id')
                ->whereNull('referred_user_id')
                ->count(),
            'Deux historiques de parrainage doivent pouvoir coexister entierement detaches.'
        );
    }

    public function test_referrals_garde_son_organization_id_intact(): void
    {
        // TASK-1633 est close : le tenant d'un parrainage ne bouge pas.
        $referrer = User::factory()->for($this->organization)->create();
        $referred = User::factory()->for($this->organization)->create();

        $id = $this->insertReferral($referrer->id, $referred->id);

        $this->deleteUserAtDatabaseLevel($referrer);
        $this->deleteUserAtDatabaseLevel($referred);

        $this->assertDatabaseHas('referrals', ['id' => $id, 'organization_id' => $this->organization->id]);
    }

    // =====================================================================
    // §19 — le registre dit exactement ce qu'il doit dire
    // =====================================================================

    public function test_les_policies_corrigees_sont_exactes(): void
    {
        $entries = collect(UserDataLifecycleRegistry::entries())->keyBy('key');

        $this->assertSame(UserDataLifecycleRegistry::POLICY_DELETE, $entries['loop_memberships']['policy']);
        $this->assertSame(UserDataLifecycleRegistry::POLICY_BLOCK, $entries['transactions_as_buyer']['policy']);
        $this->assertSame(UserDataLifecycleRegistry::POLICY_BLOCK, $entries['transactions_as_seller']['policy']);

        foreach (['blog_posts', 'feed_posts', 'services', 'service_requests'] as $key) {
            $this->assertSame(
                UserDataLifecycleRegistry::POLICY_TRANSFER,
                $entries[$key]['policy'],
                "{$key} doit rester une vraie propriete transferable."
            );
        }

        foreach (['dossiers', 'point_ledger', 'loop_poll_votes_user_id', 'course_submissions_user_id', 'course_quiz_attempts_user_id', 'orgs_as_admin'] as $key) {
            $this->assertSame(
                UserDataLifecycleRegistry::POLICY_BLOCK,
                $entries[$key]['policy'],
                "{$key} doit rester bloquant."
            );
        }

        // Addendum 24/09 21h53 : un profil IA structure est une donnee
        // personnelle du membre, elle part avec lui.
        $this->assertSame(UserDataLifecycleRegistry::POLICY_DELETE, $entries['member_ai_profile']['policy']);
    }

    public function test_la_justification_des_transactions_n_est_plus_circulaire(): void
    {
        // L'ancienne justification decrivait le comportement existant
        // (« l'ancien dry-run les groupait ainsi ») au lieu de le fonder.
        $entries = collect(UserDataLifecycleRegistry::entries())->keyBy('key');

        foreach (['transactions_as_buyer', 'transactions_as_seller'] as $key) {
            $this->assertStringNotContainsStringIgnoringCase(
                'dry-run',
                $entries[$key]['justification'],
                "{$key} : la justification ne doit pas se fonder sur le comportement existant."
            );
        }
    }

    public function test_loop_members_reste_cascade_dans_le_schema(): void
    {
        // La policy passe a DELETE ; le schema disait DEJA la meme chose et ne
        // change pas. Garde de non-regression : personne n'a transforme une
        // correction de registre en changement de schema.
        $loopId = $this->insertLoop();

        DB::table('loop_members')->insert([
            'id' => (string) Str::uuid(),
            'loop_id' => $loopId,
            'user_id' => $this->user->id,
            'role' => 'member',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->deleteUserAtDatabaseLevel($this->user);

        $this->assertSame(
            0,
            DB::table('loop_members')->where('user_id', $this->user->id)->count(),
            'Une adhesion doit partir avec son membre.'
        );
    }

    // =====================================================================
    // Outils
    // =====================================================================

    /**
     * Supprime le User par la base elle-meme : c'est le schema, et lui seul,
     * qui doit tenir le contrat.
     */
    private function deleteUserAtDatabaseLevel(User $user): void
    {
        DB::table('users')->where('id', $user->id)->delete();
    }

    /**
     * Asserte que la base REFUSE la suppression, quel que soit le moteur.
     *
     * La tentative est bornee par une transaction imbriquee — donc un
     * SAVEPOINT, puisque `RefreshDatabase` en tient deja une ouverte.
     *
     * Sans ce savepoint, le test serait vert en SQLite et faux en PostgreSQL :
     * PostgreSQL AVORTE la transaction entiere des la premiere erreur, et
     * toutes les assertions suivantes echoueraient en `25P02`
     * (« current transaction is aborted ») sans rien mesurer. SQLite, lui,
     * laisse la connexion utilisable — le vert y serait un vert de complaisance.
     *
     * Le rollback au savepoint rend la connexion saine et restaure l'etat, ce
     * qui rend les assertions d'integrite qui suivent reellement lisibles.
     */
    private function assertDeletionIsRefused(string $table, string $column): void
    {
        try {
            DB::transaction(function (): void {
                $this->deleteUserAtDatabaseLevel($this->user);
            });

            $this->fail("{$table}.{$column} : la suppression du User a ete ACCEPTEE alors que la relation doit la bloquer.");
        } catch (QueryException) {
            // Attendu : la contrainte RESTRICT refuse, le savepoint est annule.
        }
    }

    /**
     * Cree une ligne qui depend du User par `$column`, et rend son id.
     *
     * Toutes les dependances PARENTES appartiennent a `$this->other` : sinon
     * elles bloqueraient elles-memes la suppression, et le test mesurerait la
     * mauvaise contrainte.
     */
    private function insertDependentRow(string $table, string $column): string
    {
        // `organizations.admin_id` ne s'insere pas : on nomme le User
        // responsable d'une Organization qui existe deja.
        if ($table === 'organizations') {
            return Organization::factory()->create(['admin_id' => $this->user->id])->id;
        }

        $id = (string) Str::uuid();
        $now = now();

        $row = match ($table) {
            'blog_annotation_replies' => [
                'annotation_id' => $this->insertBlogPostAnnotation(),
                'content' => 'Reponse a une annotation',
            ],
            'blog_comments' => [
                'organization_id' => $this->organization->id,
                'blog_post_id' => $this->insertBlogPost(),
                'content' => 'Commentaire',
                'is_approved' => true,
            ],
            'blog_post_annotations' => [
                'blog_post_id' => $this->insertBlogPost(),
                'organization_id' => $this->organization->id,
                'selected_text' => 'extrait',
                'content' => 'Annotation',
            ],
            'blog_todo_threads' => [
                'todo_id' => $this->insertBlogTodo(),
                'body' => 'Message de fil',
            ],
            'blog_todos' => [
                'blog_post_id' => $this->insertBlogPost(),
                'organization_id' => $this->organization->id,
                'title' => 'A faire',
            ],
            'feed_post_comments' => [
                'feed_post_id' => $this->insertFeedPost(),
                'organization_id' => $this->organization->id,
                'content' => 'Commentaire de fil',
                'is_approved' => true,
            ],
            'loop_roadmap_item_messages' => [
                'organization_id' => $this->organization->id,
                'loop_id' => $loopId = $this->insertLoop(),
                'loop_roadmap_item_id' => $this->insertRoadmapItem($loopId),
                'body' => 'Message de feuille de route',
            ],
            'loop_roadmap_items' => [
                'organization_id' => $this->organization->id,
                'loop_id' => $this->insertLoop(),
                'title' => 'Jalon',
            ],
            'member_ai_profile_interactions' => [
                'organization_id' => $this->organization->id,
                'member_ai_profile_id' => $this->insertMemberAiProfile(),
                'question' => 'Une question',
            ],
            'profile_agent_conversations' => [
                'organization_id' => $this->organization->id,
                'member_ai_profile_id' => $this->insertMemberAiProfile(),
            ],
            'reviews' => [
                'organization_id' => $this->organization->id,
                'transaction_id' => $this->insertTransaction($this->other->id, $this->other->id),
                'rating' => 5,
            ] + ($column === 'reviewer_id'
                ? ['reviewed_id' => $this->other->id]
                : ['reviewer_id' => $this->other->id]),
            'login_logs' => [
                'organization_id' => $this->organization->id,
            ],
            'referral_rewards' => [
                'referral_id' => $this->insertReferral($this->other->id, $this->other->id),
                'organization_id' => $this->organization->id,
                'event_type' => 'signup',
                'points' => 10,
            ],
            'reports' => [
                'organization_id' => $this->organization->id,
                'reportable_type' => 'App\\Models\\User',
                'reportable_id' => $this->other->id,
                'reason' => 'spam',
            ],
            'referrals' => [
                'organization_id' => $this->organization->id,
            ] + ($column === 'referrer_user_id'
                ? ['referred_user_id' => $this->other->id]
                : ['referrer_user_id' => $this->other->id]),
            'blog_posts' => [
                'organization_id' => $this->organization->id,
                'category_id' => $this->insertCategory(),
                'title' => 'Article',
                'slug' => 'article-'.Str::random(8),
                'content' => 'Contenu',
                'status' => 'published',
                'views_count' => 0,
            ],
            'feed_posts' => [
                'organization_id' => $this->organization->id,
                'type' => 'announcement',
                'content' => 'Publication',
                'status' => 'published',
            ],
            'services' => [
                'organization_id' => $this->organization->id,
                'category_id' => $this->insertCategory(),
                'title' => 'Service',
                'description' => 'Description',
                'delivery_mode' => 'remote',
                'points_cost' => 10,
                'status' => 'active',
            ],
            'service_requests' => [
                'organization_id' => $this->organization->id,
                'category_id' => $this->insertCategory(),
                'title' => 'Demande',
                'description' => 'Description',
                'delivery_mode' => 'remote',
                'budget_min' => 10,
                'status' => 'open',
            ],
            'dossiers' => [
                'organization_id' => $this->organization->id,
                'name' => 'Dossier personnel',
            ],
            'point_ledger' => [
                'organization_id' => $this->organization->id,
                'delta' => 10,
                'reason' => 'adjustment',
            ],
            'loop_poll_votes' => [
                'organization_id' => $this->organization->id,
                'poll_id' => $this->insertPoll(),
            ],
            'course_submissions' => [
                'organization_id' => $this->organization->id,
                'course_assignment_id' => $this->insertCourseAssignment(),
            ],
            'course_quiz_attempts' => [
                'organization_id' => $this->organization->id,
                'course_quiz_id' => $this->insertCourseQuiz(),
                'attempt_number' => 1,
                'score' => 10,
                'passed' => true,
                'answers' => json_encode([]),
                'submitted_at' => $now,
            ],
            'transactions' => [
                'organization_id' => $this->organization->id,
                'points_proposed' => 10,
                'status' => 'pending',
            ] + ($column === 'buyer_id'
                ? ['seller_id' => $this->other->id]
                : ['buyer_id' => $this->other->id]),
        };

        DB::table($table)->insert($this->withTimestamps($table, [
            'id' => $id,
            $column => $this->user->id,
        ] + $row, $now));

        return $id;
    }

    /**
     * Toutes ces tables n'ont pas `updated_at` — on n'ajoute que ce qui existe.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function withTimestamps(string $table, array $row, mixed $now): array
    {
        foreach (['created_at', 'updated_at'] as $column) {
            if (Schema::hasColumn($table, $column) && ! array_key_exists($column, $row)) {
                $row[$column] = $now;
            }
        }

        return $row;
    }

    private function insertCategory(): string
    {
        $id = (string) Str::uuid();

        DB::table('categories')->insert([
            'id' => $id,
            'organization_id' => $this->organization->id,
            'name_b2c' => 'Categorie',
            'name_b2b' => 'Categorie',
            'slug' => 'categorie-'.Str::random(8),
            'color' => '#6366f1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function insertBlogPost(): string
    {
        $id = (string) Str::uuid();

        DB::table('blog_posts')->insert([
            'id' => $id,
            'organization_id' => $this->organization->id,
            'user_id' => $this->other->id,
            'category_id' => $this->insertCategory(),
            'title' => 'Article parent',
            'slug' => 'article-parent-'.Str::random(8),
            'content' => 'Contenu',
            'status' => 'published',
            'views_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function insertBlogPostAnnotation(): string
    {
        $id = (string) Str::uuid();

        DB::table('blog_post_annotations')->insert([
            'id' => $id,
            'blog_post_id' => $this->insertBlogPost(),
            'organization_id' => $this->organization->id,
            'user_id' => $this->other->id,
            'selected_text' => 'extrait',
            'content' => 'Annotation parente',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function insertBlogTodo(): string
    {
        $id = (string) Str::uuid();

        DB::table('blog_todos')->insert([
            'id' => $id,
            'blog_post_id' => $this->insertBlogPost(),
            'organization_id' => $this->organization->id,
            'user_id' => $this->other->id,
            'title' => 'A faire parent',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function insertFeedPost(): string
    {
        $id = (string) Str::uuid();

        DB::table('feed_posts')->insert([
            'id' => $id,
            'organization_id' => $this->organization->id,
            'user_id' => $this->other->id,
            'type' => 'announcement',
            'content' => 'Publication parente',
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function insertLoop(): string
    {
        $id = (string) Str::uuid();

        DB::table('loops')->insert([
            'id' => $id,
            'organization_id' => $this->organization->id,
            'name' => 'Boucle',
            'slug' => 'boucle-'.Str::random(8),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function insertRoadmapItem(string $loopId): string
    {
        $id = (string) Str::uuid();

        DB::table('loop_roadmap_items')->insert([
            'id' => $id,
            'organization_id' => $this->organization->id,
            'loop_id' => $loopId,
            'title' => 'Jalon parent',
            'created_by' => $this->other->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function insertMemberAiProfile(): string
    {
        $id = (string) Str::uuid();

        DB::table('member_ai_profiles')->insert([
            'id' => $id,
            'organization_id' => $this->organization->id,
            'user_id' => $this->other->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function insertTransaction(string $buyerId, string $sellerId): string
    {
        $id = (string) Str::uuid();

        DB::table('transactions')->insert([
            'id' => $id,
            'organization_id' => $this->organization->id,
            'buyer_id' => $buyerId,
            'seller_id' => $sellerId,
            'points_proposed' => 10,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function insertReview(string $transactionId, string $reviewerId, string $reviewedId): string
    {
        $id = (string) Str::uuid();

        DB::table('reviews')->insert([
            'id' => $id,
            'organization_id' => $this->organization->id,
            'transaction_id' => $transactionId,
            'reviewer_id' => $reviewerId,
            'reviewed_id' => $reviewedId,
            'rating' => 5,
            'created_at' => now(),
        ]);

        return $id;
    }

    private function insertReferral(string $referrerId, string $referredId): string
    {
        $id = (string) Str::uuid();

        DB::table('referrals')->insert([
            'id' => $id,
            'organization_id' => $this->organization->id,
            'referrer_user_id' => $referrerId,
            'referred_user_id' => $referredId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function insertPoll(): string
    {
        $id = (string) Str::uuid();

        DB::table('loop_polls')->insert([
            'id' => $id,
            'organization_id' => $this->organization->id,
            'loop_id' => $this->insertLoop(),
            'question' => 'Une question ?',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function insertCourseAssignment(): string
    {
        $id = (string) Str::uuid();

        DB::table('course_assignments')->insert([
            'id' => $id,
            'organization_id' => $this->organization->id,
            'loop_id' => $this->insertLoop(),
            'title' => 'Devoir',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function insertCourseQuiz(): string
    {
        $id = (string) Str::uuid();

        DB::table('course_quizzes')->insert([
            'id' => $id,
            'organization_id' => $this->organization->id,
            'loop_id' => $this->insertLoop(),
            'title' => 'Quiz',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
