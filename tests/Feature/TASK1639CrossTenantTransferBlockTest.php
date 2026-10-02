<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Services\Users\Exceptions\UserDeletionBlockedException;
use App\Services\Users\UserDeletionExecutor;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * TASK-1639 — une propriete rattachee a une AUTRE Organization bloque proprement.
 *
 * ## Le defaut que ces tests ferment
 *
 * `transferableCounts()` comptait les proprietes SANS filtre d'Organization et
 * `transfer()` les deplacait AVEC. Une propriete hors-tenant etait donc comptee
 * comme « a transferer », jamais deplacee, puis `forceDelete()` heurtait la FK
 * RESTRICT : l'admin recevait une **erreur SQL** au lieu d'un refus lisible.
 * Mesure du 25/09/2026 sur un clone de la PROD reelle : 1 ligne `services` sur 17
 * etait dans ce cas, et cette seule ligne suffisait a casser le chemin heureux.
 *
 * ## Pourquoi un BLOCAGE et pas un transfert
 *
 * Le repreneur est un AUTEUR visible dans le produit. Lui attribuer un contenu
 * d'une autre organisation fabriquerait une fausse paternite — ce qui est pire
 * qu'un refus.
 *
 * ## Ce que ces tests refusent
 *
 * `assertThrows(UserDeletionBlockedException)` seul ne suffirait pas : il serait
 * satisfait par un refus leve pour une TOUTE AUTRE raison. Chaque test nomme donc
 * la cle du refus attendu, et verifie EN PLUS qu'aucune `QueryException` ne
 * remonte — c'est precisement ce qui remontait avant.
 */
class TASK1639CrossTenantTransferBlockTest extends TestCase
{
    private Organization $organization;

    private Organization $autreOrganization;

    private User $user;

    private User $repreneur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->autreOrganization = Organization::factory()->create();
        $this->user = User::factory()->for($this->organization)->create();
        $this->repreneur = User::factory()->for($this->organization)->create();
    }

    // =====================================================================
    // 1. Le comportement EXISTANT est conserve
    // =====================================================================

    public function test_une_propriete_du_meme_tenant_se_transfere_comme_avant(): void
    {
        $service = $this->insertService($this->user->id, $this->organization->id);
        $article = $this->insertBlogPost($this->user->id, $this->organization->id);

        $precheck = $this->executor()->precheck($this->user);
        $this->assertSame([], array_column($precheck['blocks'], 'key'), 'Aucun blocage attendu en same-tenant.');
        $this->assertTrue($precheck['requires_transfer']);

        $report = $this->executor()->execute($this->user, $this->repreneur->id);

        $this->assertSame(1, $report['transferred']['services'] ?? 0);
        $this->assertSame(1, $report['transferred']['blog_posts'] ?? 0);
        $this->assertDatabaseHas('services', ['id' => $service, 'user_id' => $this->repreneur->id]);
        $this->assertDatabaseHas('blog_posts', ['id' => $article, 'user_id' => $this->repreneur->id]);
        $this->assertDatabaseMissing('users', ['id' => $this->user->id]);
    }

    // =====================================================================
    // 2/3. Une propriete hors-tenant bloque — sur PLUSIEURS tables
    // =====================================================================

    /**
     * Les quatre tables TRANSFER, pour prouver que la regle n'est pas taillee
     * pour `services` : c'est le contrat qui bloque, pas une table nommee.
     */
    public static function tablesTransferables(): array
    {
        return [
            'services' => ['services'],
            'blog_posts' => ['blog_posts'],
            'feed_posts' => ['feed_posts'],
            'service_requests' => ['service_requests'],
        ];
    }

    #[DataProvider('tablesTransferables')]
    public function test_une_propriete_hors_tenant_bloque_proprement(string $table): void
    {
        // La propriete appartient a une AUTRE Organization que son proprietaire.
        $id = $this->insertTransferable($table, $this->user->id, $this->autreOrganization->id);

        // L'EXECUTION d'abord, avec un repreneur designe : c'est le chemin qui
        // produisait une `QueryException` avant cette TASK. Cette assertion est
        // volontairement la premiere — si elle passait apres celle de l'ecran, un
        // defaut de l'execution serait masque par un echec plus tot, et la garde
        // « aucune erreur SQL » ne serait jamais reellement exercee.
        try {
            $this->executor()->execute($this->user, $this->repreneur->id);
            $this->fail("La suppression a ete acceptee alors que $table est hors-tenant.");
        } catch (QueryException $sql) {
            $this->fail('Une erreur SQL est remontee au lieu du refus metier : '.$sql->getMessage());
        } catch (UserDeletionBlockedException $blocked) {
            $this->assertContains('cross_tenant_transfer', array_column($blocked->blocks, 'key'));
        }

        // L'ecran annonce la MEME chose que l'execution.
        $precheck = $this->executor()->precheck($this->user);
        $this->assertContains('cross_tenant_transfer', array_column($precheck['blocks'], 'key'));

        // Aucune mutation, meme partielle.
        $this->assertDatabaseHas('users', ['id' => $this->user->id]);
        $this->assertDatabaseHas($table, ['id' => $id, 'user_id' => $this->user->id]);
    }

    public function test_le_refus_vaut_aussi_sans_repreneur_designe(): void
    {
        // Sans `transfer_to`, l'ancien code refusait deja — mais pour la mauvaise
        // raison (« transfert requis »). Le refus doit nommer le vrai obstacle,
        // sinon l'admin choisit un repreneur et retombe sur l'erreur SQL.
        $this->insertService($this->user->id, $this->autreOrganization->id);

        $blocks = $this->assertRefused(fn () => $this->executor()->execute($this->user));
        $keys = array_column($blocks, 'key');

        $this->assertContains('cross_tenant_transfer', $keys);
        $this->assertNotContains('transfer_required', $keys, "Le refus doit nommer l'obstacle reel, pas demander un repreneur.");
    }

    public function test_le_message_rendu_ne_parle_jamais_technique(): void
    {
        $this->insertService($this->user->id, $this->autreOrganization->id);

        $blocks = $this->assertRefused(fn () => $this->executor()->execute($this->user));
        $message = collect($blocks)->firstWhere('key', 'cross_tenant_transfer')['message'];

        $this->assertNotSame('admin.user_delete.block.cross_tenant_transfer', $message, 'La cle de traduction est rendue brute.');

        foreach (['organization_id', 'RESTRICT', 'foreign', 'SQL', 'tenant', 'FK', 'constraint'] as $jargon) {
            $this->assertStringNotContainsStringIgnoringCase(
                $jargon,
                $message,
                "Le message montre un terme technique : « $jargon »."
            );
        }

        // La traduction existe dans les DEUX langues.
        foreach (['fr', 'en'] as $locale) {
            $this->assertNotSame(
                'admin.user_delete.block.cross_tenant_transfer',
                __('admin.user_delete.block.cross_tenant_transfer', ['count' => 1], $locale),
                "Traduction manquante en $locale."
            );
        }
    }

    public function test_une_propriete_hors_tenant_n_empeche_pas_le_compte_du_voisin(): void
    {
        // Le blocage porte sur le compte VISE, pas sur la table entiere.
        $this->insertService($this->user->id, $this->autreOrganization->id);
        $this->insertService($this->repreneur->id, $this->organization->id);

        $this->assertContains(
            'cross_tenant_transfer',
            array_column($this->executor()->precheck($this->user)['blocks'], 'key')
        );

        $this->assertSame(
            [],
            array_column($this->executor()->precheck($this->repreneur)['blocks'], 'key'),
            "Le voisin, dont la propriete est dans SON Organization, ne doit pas etre bloque."
        );
    }

    public function test_un_melange_same_tenant_et_hors_tenant_bloque_et_ne_transfere_rien(): void
    {
        $propre = $this->insertService($this->user->id, $this->organization->id);
        $etranger = $this->insertBlogPost($this->user->id, $this->autreOrganization->id);

        $blocks = $this->assertRefused(fn () => $this->executor()->execute($this->user, $this->repreneur->id));

        $this->assertSame(1, collect($blocks)->firstWhere('key', 'cross_tenant_transfer')['count']);

        // La propriete transferable, elle, n'a PAS bouge : le refus arrive avant
        // toute mutation, pas au milieu.
        $this->assertDatabaseHas('services', ['id' => $propre, 'user_id' => $this->user->id]);
        $this->assertDatabaseHas('blog_posts', ['id' => $etranger, 'user_id' => $this->user->id]);
        $this->assertDatabaseHas('users', ['id' => $this->user->id]);
    }

    // =====================================================================
    // 4. Les HARD BLOCK existants sont inchanges
    // =====================================================================

    public function test_les_blocages_economiques_restent_inchanges(): void
    {
        $transactionId = $this->insertTransaction($this->user->id, $this->repreneur->id);
        $this->insertLedger($this->user->id, 'adjustment');

        $blocks = $this->assertRefused(fn () => $this->executor()->execute($this->user));
        $keys = array_column($blocks, 'key');

        $this->assertContains('point_ledger', $keys);
        $this->assertContains('transactions_as_buyer', $keys);
        $this->assertNotContains('cross_tenant_transfer', $keys, 'Aucune propriete hors-tenant ici.');

        $this->assertDatabaseHas('transactions', ['id' => $transactionId]);
        $this->assertDatabaseHas('users', ['id' => $this->user->id]);
    }

    public function test_le_bonus_de_bienvenue_reste_resolvable(): void
    {
        // Non-regression TASK-1638 : ce que cette TASK ajoute ne doit pas
        // ressusciter le blocage par le ledger.
        $this->insertLedger($this->user->id, 'welcome_bonus');

        $this->assertSame([], array_column($this->executor()->precheck($this->user)['blocks'], 'key'));

        $report = $this->executor()->execute($this->user);

        $this->assertSame(1, $report['resolved']['point_ledger'] ?? 0);
        $this->assertDatabaseMissing('users', ['id' => $this->user->id]);
    }

    /**
     * Le compte hors-tenant est le COMPLEMENT de ce que le transfert deplace.
     *
     * Garde structurelle : elle ne verifie pas une formule, elle verifie que les
     * deux surfaces decrivent le meme ensemble. Un jour ou `transfer()` cesserait
     * de filtrer par Organization, ce test suivrait au lieu de mentir.
     */
    public function test_ce_qui_bloque_est_exactement_ce_que_le_transfert_ne_deplace_pas(): void
    {
        $this->insertService($this->user->id, $this->organization->id);
        $this->insertService($this->user->id, $this->autreOrganization->id);
        $this->insertBlogPost($this->user->id, $this->autreOrganization->id);

        $precheck = $this->executor()->precheck($this->user);
        $possedees = array_sum($precheck['transferable']);
        $bloquantes = collect($precheck['blocks'])->firstWhere('key', 'cross_tenant_transfer')['count'];

        // 3 proprietes possedees, 2 hors-tenant : le transfert en deplacerait 1.
        $this->assertSame(3, $possedees);
        $this->assertSame(2, $bloquantes);

        // Et la suppression refuse tant que ces 2 sont la.
        $this->assertContains('cross_tenant_transfer', array_column($precheck['blocks'], 'key'));
    }

    // =====================================================================
    // Fabrique
    // =====================================================================

    private function executor(): UserDeletionExecutor
    {
        return app(UserDeletionExecutor::class);
    }

    private function insertTransferable(string $table, string $userId, string $organizationId): string
    {
        return match ($table) {
            'services' => $this->insertService($userId, $organizationId),
            'blog_posts' => $this->insertBlogPost($userId, $organizationId),
            'feed_posts' => $this->insertFeedPost($userId, $organizationId),
            'service_requests' => $this->insertServiceRequest($userId, $organizationId),
        };
    }

    private function insertCategory(string $organizationId): string
    {
        $id = (string) Str::uuid();

        DB::table('categories')->insert([
            'id' => $id,
            'organization_id' => $organizationId,
            'name_b2c' => 'Categorie',
            'name_b2b' => 'Categorie',
            'slug' => 'cat-'.Str::random(10),
            'color' => '#6366f1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function insertService(string $userId, string $organizationId): string
    {
        $id = (string) Str::uuid();

        DB::table('services')->insert([
            'id' => $id,
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'category_id' => $this->insertCategory($organizationId),
            'title' => 'Service',
            'description' => 'Description',
            'delivery_mode' => 'remote',
            'points_cost' => 10,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function insertServiceRequest(string $userId, string $organizationId): string
    {
        $id = (string) Str::uuid();

        DB::table('service_requests')->insert([
            'id' => $id,
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'category_id' => $this->insertCategory($organizationId),
            'title' => 'Demande',
            'description' => 'Description',
            'delivery_mode' => 'remote',
            'budget_min' => 10,
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function insertBlogPost(string $userId, string $organizationId): string
    {
        $id = (string) Str::uuid();

        DB::table('blog_posts')->insert([
            'id' => $id,
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'title' => 'Article',
            'slug' => 'article-'.Str::random(10),
            'content' => 'Contenu',
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function insertFeedPost(string $userId, string $organizationId): string
    {
        $id = (string) Str::uuid();

        DB::table('feed_posts')->insert([
            'id' => $id,
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'content' => 'Message',
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

    private function insertLedger(string $userId, string $reason): string
    {
        $id = (string) Str::uuid();

        DB::table('point_ledger')->insert([
            'id' => $id,
            'user_id' => $userId,
            'organization_id' => $this->organization->id,
            'delta' => 100,
            'reason' => $reason,
            'created_at' => now(),
        ]);

        return $id;
    }

    /** @return list<array{key: string, count: int, message: string}> */
    private function assertRefused(callable $action): array
    {
        try {
            $action();

            $this->fail('La suppression a ete acceptee alors qu elle devait etre refusee.');
        } catch (QueryException $sql) {
            $this->fail('Une erreur SQL est remontee au lieu du refus metier : '.$sql->getMessage());
        } catch (UserDeletionBlockedException $blocked) {
            return $blocked->blocks;
        }
    }
}
