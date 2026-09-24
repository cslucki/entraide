<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Services\Users\Exceptions\UserDeletionBlockedException;
use App\Services\Users\UserDeletionExecutor;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * TASK-1636 — la premiere suppression REELLE d'un compte.
 *
 * Ces tests mesurent ce que la base contient APRES, jamais ce que le service
 * dit avoir fait. Un rapport d'execution peut mentir ; une ligne presente ou
 * absente, non.
 */
class TASK1636UserDeletionExecutorTest extends TestCase
{
    private Organization $organization;

    private User $user;

    /** Le repreneur : meme Organization, non banni. */
    private User $heir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->user = User::factory()->for($this->organization)->create();
        $this->heir = User::factory()->for($this->organization)->create();
    }

    private function executor(): UserDeletionExecutor
    {
        return app(UserDeletionExecutor::class);
    }

    // =====================================================================
    // A — M3 : les 4 FK TRANSFER refusent reellement
    // =====================================================================

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function transferColumns(): array
    {
        return [
            'blog_posts.user_id' => ['blog_posts', 'user_id'],
            'feed_posts.user_id' => ['feed_posts', 'user_id'],
            'services.user_id' => ['services', 'user_id'],
            'service_requests.user_id' => ['service_requests', 'user_id'],
        ];
    }

    #[DataProvider('transferColumns')]
    public function test_m3_une_propriete_non_transferee_interdit_le_delete_brut(string $table, string $column): void
    {
        $this->insertOwned($table, $column, $this->user->id);

        // Le dernier filet : meme en contournant l'executeur, la base refuse.
        try {
            DB::transaction(function (): void {
                DB::table('users')->where('id', $this->user->id)->delete();
            });

            $this->fail("{$table}.{$column} : le DELETE brut a ete accepte alors que M3 doit le refuser.");
        } catch (QueryException) {
            // Attendu.
        }

        $this->assertDatabaseHas('users', ['id' => $this->user->id]);
    }

    // =====================================================================
    // B / C — le chemin nominal
    // =====================================================================

    public function test_un_compte_sans_rien_se_supprime(): void
    {
        $report = $this->executor()->execute($this->user);

        $this->assertDatabaseMissing('users', ['id' => $this->user->id]);
        $this->assertSame([], $report['transferred']);
    }

    public function test_les_quatre_proprietes_changent_de_proprietaire_et_le_compte_part(): void
    {
        $ids = [];
        foreach (self::transferColumns() as [$table, $column]) {
            $ids[$table] = $this->insertOwned($table, $column, $this->user->id);
        }

        $report = $this->executor()->execute($this->user, $this->heir->id);

        $this->assertDatabaseMissing('users', ['id' => $this->user->id]);

        foreach (self::transferColumns() as [$table, $column]) {
            $this->assertDatabaseHas($table, ['id' => $ids[$table], $column => $this->heir->id]);
            // Les cles du registre portent le nom de la table.
            $this->assertSame(1, $report['transferred'][$table]);
        }
    }

    // =====================================================================
    // D a G — la cible du transfert
    // =====================================================================

    public function test_sans_repreneur_une_propriete_empeche_la_suppression(): void
    {
        $this->insertOwned('services', 'user_id', $this->user->id);

        $blocks = $this->assertRefused(fn () => $this->executor()->execute($this->user));

        $this->assertSame('transfer_required', $blocks[0]['key']);
        $this->assertDatabaseHas('users', ['id' => $this->user->id]);
    }

    public function test_un_repreneur_d_une_autre_organization_est_refuse(): void
    {
        $this->insertOwned('services', 'user_id', $this->user->id);
        $ailleurs = User::factory()->for(Organization::factory()->create())->create();

        $blocks = $this->assertRefused(fn () => $this->executor()->execute($this->user, $ailleurs->id));

        $this->assertSame('transfer_target_cross_tenant', $blocks[0]['key']);
        $this->assertDatabaseHas('users', ['id' => $this->user->id]);
    }

    public function test_on_ne_peut_pas_se_transferer_a_soi_meme(): void
    {
        $this->insertOwned('services', 'user_id', $this->user->id);

        $blocks = $this->assertRefused(fn () => $this->executor()->execute($this->user, $this->user->id));

        $this->assertSame('transfer_target_self', $blocks[0]['key']);
    }

    public function test_un_repreneur_banni_est_refuse(): void
    {
        $this->insertOwned('services', 'user_id', $this->user->id);
        $this->heir->update(['banned_at' => now()]);

        $blocks = $this->assertRefused(fn () => $this->executor()->execute($this->user, $this->heir->id));

        $this->assertSame('transfer_target_banned', $blocks[0]['key']);
    }

    // =====================================================================
    // H a O — les refus francs
    // =====================================================================

    public function test_un_responsable_d_organization_ne_peut_pas_etre_supprime(): void
    {
        $this->organization->update(['admin_id' => $this->user->id]);

        $blocks = $this->assertRefused(fn () => $this->executor()->execute($this->user));

        $this->assertSame('orgs_as_admin', $blocks[0]['key']);
        $this->assertDatabaseHas('organizations', ['id' => $this->organization->id, 'admin_id' => $this->user->id]);
    }

    public function test_le_dernier_responsable_actif_d_une_boucle_bloque(): void
    {
        $loop = $this->insertLoop();
        $this->insertMembership($loop, $this->user->id, 'owner');

        $blocks = $this->assertRefused(fn () => $this->executor()->execute($this->user));

        $this->assertSame('loop_last_owner', $blocks[0]['key']);
    }

    public function test_un_facilitator_seul_ne_bloque_pas(): void
    {
        // Le piege symetrique : refuser un facilitator rendrait toute
        // suppression impossible des qu'un membre a une responsabilite.
        $loop = $this->insertLoop();
        $this->insertMembership($loop, $this->heir->id, 'owner');
        $this->insertMembership($loop, $this->user->id, 'facilitator');

        $this->executor()->execute($this->user);

        $this->assertDatabaseMissing('users', ['id' => $this->user->id]);
        $this->assertDatabaseHas('loop_members', ['loop_id' => $loop, 'user_id' => $this->heir->id]);
    }

    public function test_un_owner_qui_n_est_pas_le_dernier_ne_bloque_pas(): void
    {
        $loop = $this->insertLoop();
        $this->insertMembership($loop, $this->user->id, 'owner');
        $this->insertMembership($loop, $this->heir->id, 'owner');

        $this->executor()->execute($this->user);

        $this->assertDatabaseMissing('users', ['id' => $this->user->id]);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function hardBlocks(): array
    {
        return [
            'point_ledger' => ['point_ledger', 'user_id', 'point_ledger'],
            'loop_poll_votes' => ['loop_poll_votes', 'user_id', 'loop_poll_votes_user_id'],
            'course_submissions' => ['course_submissions', 'user_id', 'course_submissions_user_id'],
            'course_quiz_attempts' => ['course_quiz_attempts', 'user_id', 'course_quiz_attempts_user_id'],
            'transactions buyer' => ['transactions', 'buyer_id', 'transactions_as_buyer'],
            'transactions seller' => ['transactions', 'seller_id', 'transactions_as_seller'],
        ];
    }

    #[DataProvider('hardBlocks')]
    public function test_une_donnee_bloquante_refuse_la_suppression(string $table, string $column, string $expectedKey): void
    {
        $rowId = $this->insertOwned($table, $column, $this->user->id);

        $blocks = $this->assertRefused(fn () => $this->executor()->execute($this->user));

        $this->assertContains($expectedKey, array_column($blocks, 'key'));
        $this->assertDatabaseHas('users', ['id' => $this->user->id]);
        $this->assertDatabaseHas($table, ['id' => $rowId, $column => $this->user->id]);
    }

    public function test_un_refus_porte_un_message_lisible_et_non_un_nom_sql(): void
    {
        $this->insertOwned('point_ledger', 'user_id', $this->user->id);

        $blocks = $this->assertRefused(fn () => $this->executor()->execute($this->user));

        $this->assertStringNotContainsString('point_ledger', $blocks[0]['message']);
        $this->assertStringNotContainsString('foreign', strtolower($blocks[0]['message']));
        $this->assertStringContainsString('1', $blocks[0]['message']);
    }

    // =====================================================================
    // P — member_ai_profiles : supprime, jamais bloquant
    // =====================================================================

    public function test_un_profil_ia_part_avec_le_membre_sans_bloquer(): void
    {
        $profileId = (string) Str::uuid();
        DB::table('member_ai_profiles')->insert([
            'id' => $profileId,
            'organization_id' => $this->organization->id,
            'user_id' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $report = $this->executor()->execute($this->user);

        $this->assertDatabaseMissing('users', ['id' => $this->user->id]);
        $this->assertDatabaseMissing('member_ai_profiles', ['id' => $profileId]);
        $this->assertSame(1, $report['deleted']['member_ai_profile'] ?? 0);
    }

    // =====================================================================
    // Q — le Dossier personnel
    // =====================================================================

    public function test_la_branche_personnelle_est_purgee_et_l_article_lie_survit(): void
    {
        $racine = $this->insertDossierRoot($this->user->id);
        $enfant = $this->insertDossierChild($racine);

        // Un Article LIE au dossier : la liaison part, l'Article reste.
        $articleId = $this->insertOwned('blog_posts', 'user_id', $this->heir->id);
        DB::table('dossier_blog_posts')->insert([
            'id' => (string) Str::uuid(),
            'dossier_id' => $enfant,
            'blog_post_id' => $articleId,
            'organization_id' => $this->organization->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->executor()->execute($this->user);

        $this->assertDatabaseMissing('users', ['id' => $this->user->id]);
        $this->assertDatabaseMissing('dossiers', ['id' => $racine]);
        $this->assertDatabaseMissing('dossiers', ['id' => $enfant]);
        $this->assertDatabaseMissing('dossier_blog_posts', ['blog_post_id' => $articleId]);

        // L'Article, lui, n'a jamais appartenu a la branche.
        $this->assertDatabaseHas('blog_posts', ['id' => $articleId, 'user_id' => $this->heir->id]);
    }

    public function test_une_racine_legacy_sans_system_role_est_purgee_aussi(): void
    {
        // Ne pas se limiter a `system_role = personal_documents` : une racine
        // legacy appartient tout autant au membre et bloquerait pareil.
        $racine = $this->insertDossierRoot($this->user->id, systemRole: null);

        $this->executor()->execute($this->user);

        $this->assertDatabaseMissing('dossiers', ['id' => $racine]);
        $this->assertDatabaseMissing('users', ['id' => $this->user->id]);
    }

    // =====================================================================
    // R — le provisioning automatique n'echappe pas au transfert
    // =====================================================================

    public function test_l_article_racine_d_une_boucle_suit_le_transfert(): void
    {
        // TASK-1635 avait montre que `LoopRootDocumentService` cree un article
        // racine attribue au membre, JAMAIS declare a un registre. Rien ici ne
        // le traite specialement : il doit etre emporte par le transfert
        // ordinaire de `blog_posts.user_id`, sans logique parallele.
        $loop = \App\Models\Loop::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by' => $this->user->id,
        ]);

        $post = app(\App\Services\Loops\LoopRootDocumentService::class)
            ->ensureRootDocument($loop, $this->user);

        $this->assertSame($this->user->id, $post->user_id, 'Premisse : l article racine appartient bien au membre.');

        $this->executor()->execute($this->user, $this->heir->id);

        $this->assertDatabaseMissing('users', ['id' => $this->user->id]);
        $this->assertDatabaseHas('blog_posts', ['id' => $post->id, 'user_id' => $this->heir->id]);
    }

    // =====================================================================
    // S — ce qui doit SURVIVRE, detache
    // =====================================================================

    public function test_le_contenu_collaboratif_survit_sans_auteur(): void
    {
        $postId = $this->insertOwned('blog_posts', 'user_id', $this->heir->id);

        $commentId = (string) Str::uuid();
        DB::table('blog_comments')->insert([
            'id' => $commentId,
            'organization_id' => $this->organization->id,
            'blog_post_id' => $postId,
            'user_id' => $this->user->id,
            'content' => 'Un commentaire qui doit rester lisible',
            'is_approved' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $logId = (string) Str::uuid();
        // `login_logs` n'a pas d'`updated_at`.
        DB::table('login_logs')->insert([
            'id' => $logId,
            'organization_id' => $this->organization->id,
            'user_id' => $this->user->id,
            'created_at' => now(),
        ]);

        $this->executor()->execute($this->user);

        // Le contenu reste, l'attribution disparait : c'est le contrat des
        // policies ANONYMIZE / RETAIN, tenu par le schema seul.
        $this->assertDatabaseHas('blog_comments', ['id' => $commentId, 'user_id' => null]);
        $this->assertDatabaseHas('login_logs', ['id' => $logId, 'user_id' => null]);
    }

    // =====================================================================
    // T — atomicite
    // =====================================================================

    public function test_une_panne_pendant_la_suppression_ne_laisse_aucune_trace(): void
    {
        $serviceId = $this->insertOwned('services', 'user_id', $this->user->id);
        $racine = $this->insertDossierRoot($this->user->id);

        $favoriteId = (string) Str::uuid();
        DB::table('favorites')->insert([
            'id' => $favoriteId,
            'organization_id' => $this->organization->id,
            'user_id' => $this->user->id,
            'service_id' => $serviceId,
            'created_at' => now(),
        ]);

        // `deleting` est emis a l'etape 7, apres le transfert, la purge et les
        // suppressions : la fenetre exacte ou un rollback doit tout rendre.
        User::deleting(function (): void {
            throw new RuntimeException('TASK-1636 panne simulee au DELETE final');
        });

        try {
            $this->executor()->execute($this->user, $this->heir->id);
            $this->fail('La panne simulee aurait du interrompre la suppression.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('TASK-1636', $e->getMessage());
        }

        $this->assertDatabaseHas('users', ['id' => $this->user->id]);
        $this->assertDatabaseHas('services', ['id' => $serviceId, 'user_id' => $this->user->id]);
        $this->assertDatabaseHas('dossiers', ['id' => $racine]);
        $this->assertDatabaseHas('favorites', ['id' => $favoriteId]);
    }

    // =====================================================================
    // X — multi-tenant
    // =====================================================================

    public function test_aucune_ligne_d_un_autre_tenant_n_est_touchee(): void
    {
        $voisine = Organization::factory()->create();
        $voisin = User::factory()->for($voisine)->create();
        $serviceVoisin = $this->insertOwned('services', 'user_id', $voisin->id, $voisine->id);
        $racineVoisine = $this->insertDossierRoot($voisin->id, organizationId: $voisine->id);

        $this->insertOwned('services', 'user_id', $this->user->id);
        $this->insertDossierRoot($this->user->id);

        $this->executor()->execute($this->user, $this->heir->id);

        $this->assertDatabaseHas('users', ['id' => $voisin->id]);
        $this->assertDatabaseHas('services', ['id' => $serviceVoisin, 'user_id' => $voisin->id]);
        $this->assertDatabaseHas('dossiers', ['id' => $racineVoisine]);
    }

    // =====================================================================
    // Outils
    // =====================================================================

    /**
     * @param  callable(): mixed  $action
     * @return list<array{key: string, count: int, message: string}>
     */
    private function assertRefused(callable $action): array
    {
        try {
            $action();

            $this->fail('La suppression a ete acceptee alors qu elle devait etre refusee.');
        } catch (UserDeletionBlockedException $blocked) {
            return $blocked->blocks;
        }
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

    private function insertMembership(string $loopId, string $userId, string $role): void
    {
        DB::table('loop_members')->insert([
            'id' => (string) Str::uuid(),
            'loop_id' => $loopId,
            'user_id' => $userId,
            'role' => $role,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertDossierRoot(string $ownerId, ?string $systemRole = 'personal_documents', ?string $organizationId = null): string
    {
        $id = (string) Str::uuid();

        DB::table('dossiers')->insert([
            'id' => $id,
            'organization_id' => $organizationId ?? $this->organization->id,
            'name' => 'Espace personnel',
            'owner_id' => $ownerId,
            'parent_id' => null,
            'loop_id' => null,
            'system_role' => $systemRole,
            'visibility' => 'private',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * Un enfant ne porte NI `owner_id` NI `loop_id` : c'est ce qu'impose
     * `dossiers_holder_xor`.
     */
    private function insertDossierChild(string $parentId): string
    {
        $id = (string) Str::uuid();

        DB::table('dossiers')->insert([
            'id' => $id,
            'organization_id' => $this->organization->id,
            'name' => 'Sous-dossier',
            'owner_id' => null,
            'loop_id' => null,
            'parent_id' => $parentId,
            'visibility' => 'private',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function insertCategory(?string $organizationId = null): string
    {
        $id = (string) Str::uuid();

        DB::table('categories')->insert([
            'id' => $id,
            'organization_id' => $organizationId ?? $this->organization->id,
            'name_b2c' => 'Categorie',
            'name_b2b' => 'Categorie',
            'slug' => 'cat-'.Str::random(8),
            'color' => '#6366f1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * Une ligne de `$table` attribuee a `$userId` par `$column`.
     */
    private function insertOwned(string $table, string $column, string $userId, ?string $organizationId = null): string
    {
        $id = (string) Str::uuid();
        $organizationId ??= $this->organization->id;
        $now = now();

        $row = match ($table) {
            'blog_posts' => [
                'organization_id' => $organizationId,
                'category_id' => $this->insertCategory($organizationId),
                'title' => 'Article',
                'slug' => 'article-'.Str::random(8),
                'content' => 'Contenu',
                'status' => 'published',
                'views_count' => 0,
            ],
            'feed_posts' => [
                'organization_id' => $organizationId,
                'type' => 'announcement',
                'content' => 'Publication',
                'status' => 'published',
            ],
            'services' => [
                'organization_id' => $organizationId,
                'category_id' => $this->insertCategory($organizationId),
                'title' => 'Service',
                'description' => 'Description',
                'delivery_mode' => 'remote',
                'points_cost' => 10,
                'status' => 'active',
            ],
            'service_requests' => [
                'organization_id' => $organizationId,
                'category_id' => $this->insertCategory($organizationId),
                'title' => 'Demande',
                'description' => 'Description',
                'delivery_mode' => 'remote',
                'budget_min' => 10,
                'status' => 'open',
            ],
            'point_ledger' => [
                'organization_id' => $organizationId,
                'delta' => 10,
                'reason' => 'adjustment',
            ],
            'loop_poll_votes' => [
                'organization_id' => $organizationId,
                'poll_id' => $this->insertPoll(),
            ],
            'course_submissions' => [
                'organization_id' => $organizationId,
                'course_assignment_id' => $this->insertCourseAssignment(),
            ],
            'course_quiz_attempts' => [
                'organization_id' => $organizationId,
                'course_quiz_id' => $this->insertCourseQuiz(),
                'attempt_number' => 1,
                'score' => 10,
                'passed' => true,
                'answers' => json_encode([]),
                'submitted_at' => $now,
            ],
            'transactions' => [
                'organization_id' => $organizationId,
                'points_proposed' => 10,
                'status' => 'pending',
            ] + ($column === 'buyer_id'
                ? ['seller_id' => $this->heir->id]
                : ['buyer_id' => $this->heir->id]),
        };

        $row = ['id' => $id, $column => $userId] + $row;

        foreach (['created_at', 'updated_at'] as $stamp) {
            if (Schema::hasColumn($table, $stamp)) {
                $row[$stamp] ??= $now;
            }
        }

        DB::table($table)->insert($row);

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
