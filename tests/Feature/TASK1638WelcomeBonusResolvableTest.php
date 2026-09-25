<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Services\UserDataLifecycleRegistry;
use App\Services\Users\Exceptions\UserDeletionBlockedException;
use App\Services\Users\UserDeletionExecutor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-1638 — le bonus de bienvenue ne bloque plus, le reste du ledger si.
 *
 * ## La regle mesuree ici
 *
 * Un compte est supprimable si **toutes** ses lignes `point_ledger` sont
 * exactement la raison declaree au registre. UNE SEULE ligne d'une autre raison
 * et `point_ledger` bloque franchement : c'est un tout ou rien par compte.
 *
 * ## Ce que ces tests refusent de faire
 *
 * Ils ne prennent pas la chaine `welcome_bonus` dans leur propre code : ils la
 * lisent au registre, la ou la regle est declaree. Un test qui ecrirait la
 * valeur en dur passerait meme si le registre disait autre chose — c'est
 * exactement le defaut qui avait ete mesure sur TASK-1636, ou le test prenait sa
 * valeur attendue a la meme source que le code teste.
 */
class TASK1638WelcomeBonusResolvableTest extends TestCase
{
    private Organization $organization;

    private User $user;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->user = User::factory()->for($this->organization)->create();
        $this->other = User::factory()->for($this->organization)->create();
    }

    // =====================================================================
    // La declaration, lue au registre — aucune valeur en dur dans ce fichier
    // =====================================================================

    private function declaration(): array
    {
        $resolvable = UserDataLifecycleRegistry::resolvableRows('point_ledger');

        $this->assertNotNull(
            $resolvable,
            "Le registre ne declare plus de sous-cas resolvable pour point_ledger."
        );

        return $resolvable;
    }

    /** La raison que le registre declare resolvable. */
    private function resolvableReason(): string
    {
        return $this->declaration()['value'];
    }

    /** Une raison qui n'est PAS celle declaree — donc bloquante par contrat. */
    private function blockingReason(string $reason): string
    {
        $this->assertNotSame(
            $this->resolvableReason(),
            $reason,
            "Ce test a besoin d'une raison BLOQUANTE ; '$reason' est celle que le registre resout."
        );

        return $reason;
    }

    // =====================================================================
    // 1. welcome_bonus seul -> supprimable
    // =====================================================================

    public function test_un_compte_dont_le_ledger_est_uniquement_le_bonus_est_supprimable(): void
    {
        $bonus = $this->insertLedger($this->user->id, $this->resolvableReason());

        // L'ecran ne doit plus annoncer de blocage, ni pour le SuperAdmin
        // (sans Organization) ni pour l'OrgAdmin (avec).
        $registry = app(UserDataLifecycleRegistry::class);
        $this->assertSame(0, $registry->preview($this->user)['block']['point_ledger']);
        $this->assertSame(0, $registry->preview($this->user, $this->organization)['block']['point_ledger']);

        $precheck = app(UserDeletionExecutor::class)->precheck($this->user);
        $this->assertSame([], array_column($precheck['blocks'], 'key'));

        $report = app(UserDeletionExecutor::class)->execute($this->user);

        $this->assertSame(1, $report['resolved']['point_ledger'] ?? 0);
        $this->assertDatabaseMissing('point_ledger', ['id' => $bonus]);
        $this->assertDatabaseMissing('users', ['id' => $this->user->id]);
    }

    public function test_plusieurs_bonus_partent_tous_et_ne_bloquent_pas(): void
    {
        // Rien n'interdit deux ecritures de bienvenue (creation admin puis
        // ajustement initial) : la regle porte sur la RAISON, pas sur le nombre.
        $premier = $this->insertLedger($this->user->id, $this->resolvableReason());
        $second = $this->insertLedger($this->user->id, $this->resolvableReason());

        $report = app(UserDeletionExecutor::class)->execute($this->user);

        $this->assertSame(2, $report['resolved']['point_ledger'] ?? 0);
        $this->assertDatabaseMissing('point_ledger', ['id' => $premier]);
        $this->assertDatabaseMissing('point_ledger', ['id' => $second]);
        $this->assertDatabaseMissing('users', ['id' => $this->user->id]);
    }

    // =====================================================================
    // 2/3/4. une seule autre raison, et tout bloque
    // =====================================================================

    /**
     * Le compte du blocage ne doit PAS inclure le bonus : un compteur a 2
     * dirait a l'admin que deux lignes s'opposent a la suppression, alors
     * qu'une seule le fait.
     */
    public function test_le_bonus_plus_un_ajustement_bloque_et_ne_compte_que_l_ajustement(): void
    {
        $bonus = $this->insertLedger($this->user->id, $this->resolvableReason());
        $ajustement = $this->insertLedger($this->user->id, $this->blockingReason('adjustment'));

        $blocks = $this->assertRefused(fn () => app(UserDeletionExecutor::class)->execute($this->user));

        $this->assertContains('point_ledger', array_column($blocks, 'key'));
        $this->assertSame(1, collect($blocks)->firstWhere('key', 'point_ledger')['count']);

        // Rien n'a bouge : ni le compte, ni AUCUNE des deux lignes. Le bonus en
        // particulier ne doit pas avoir ete purge « en avance ».
        $this->assertDatabaseHas('users', ['id' => $this->user->id]);
        $this->assertDatabaseHas('point_ledger', ['id' => $bonus]);
        $this->assertDatabaseHas('point_ledger', ['id' => $ajustement]);
    }

    public function test_le_bonus_plus_une_recompense_de_parrainage_bloque(): void
    {
        $bonus = $this->insertLedger($this->user->id, $this->resolvableReason());
        $parrainage = $this->insertLedger($this->user->id, $this->blockingReason('referral_reward'));

        $blocks = $this->assertRefused(fn () => app(UserDeletionExecutor::class)->execute($this->user));

        $this->assertSame(1, collect($blocks)->firstWhere('key', 'point_ledger')['count']);
        $this->assertDatabaseHas('users', ['id' => $this->user->id]);
        $this->assertDatabaseHas('point_ledger', ['id' => $bonus]);
        $this->assertDatabaseHas('point_ledger', ['id' => $parrainage]);
    }

    public function test_le_bonus_plus_un_echange_lie_a_une_transaction_bloque_deux_fois(): void
    {
        $bonus = $this->insertLedger($this->user->id, $this->resolvableReason());
        $transactionId = $this->insertTransaction($this->user->id, $this->other->id);
        $echange = $this->insertLedger($this->user->id, $this->blockingReason('exchange_spent'), $transactionId);

        $blocks = $this->assertRefused(fn () => app(UserDeletionExecutor::class)->execute($this->user));
        $keys = array_column($blocks, 'key');

        // Deux refus INDEPENDANTS : la ligne de ledger, et la transaction
        // elle-meme. Lever l'un ne doit pas lever l'autre.
        $this->assertContains('point_ledger', $keys);
        $this->assertContains('transactions_as_buyer', $keys);

        $this->assertDatabaseHas('users', ['id' => $this->user->id]);
        $this->assertDatabaseHas('point_ledger', ['id' => $bonus]);
        $this->assertDatabaseHas('point_ledger', ['id' => $echange]);
        $this->assertDatabaseHas('transactions', ['id' => $transactionId]);
    }

    public function test_une_transaction_seule_bloque_meme_sans_ligne_de_ledger(): void
    {
        // Garde de non-regression : la resolution du bonus ne doit pas avoir
        // ouvert un chemin pour les transactions.
        $transactionId = $this->insertTransaction($this->user->id, $this->other->id);

        $blocks = $this->assertRefused(fn () => app(UserDeletionExecutor::class)->execute($this->user));

        $this->assertContains('transactions_as_buyer', array_column($blocks, 'key'));
        $this->assertDatabaseHas('transactions', ['id' => $transactionId]);
        $this->assertDatabaseHas('users', ['id' => $this->user->id]);
    }

    /**
     * La purge est bornee au compte supprime.
     *
     * Sans cette garde, un `delete()` qui oublierait la clause `user_id`
     * emporterait le bonus de TOUS les membres — et aucun autre test ne le
     * verrait, puisque `execute()` n'atteint la purge que lorsqu'il ne reste
     * plus aucune ligne bloquante sur le compte VISE.
     */
    public function test_le_bonus_d_un_autre_compte_n_est_jamais_emporte(): void
    {
        $sien = $this->insertLedger($this->user->id, $this->resolvableReason());
        $celuiDuVoisin = $this->insertLedger($this->other->id, $this->resolvableReason());

        $tiers = User::factory()->for(Organization::factory()->create())->create();
        $celuiDuTiers = $this->insertLedger(
            $tiers->id,
            $this->resolvableReason(),
            null,
            $tiers->organization_id
        );

        app(UserDeletionExecutor::class)->execute($this->user);

        $this->assertDatabaseMissing('point_ledger', ['id' => $sien]);
        $this->assertDatabaseHas('point_ledger', ['id' => $celuiDuVoisin, 'user_id' => $this->other->id]);
        $this->assertDatabaseHas('point_ledger', ['id' => $celuiDuTiers, 'user_id' => $tiers->id]);
    }

    // =====================================================================
    // 5. aucune ligne point_ledger -> comportement normal
    // =====================================================================

    public function test_un_compte_sans_aucune_ligne_de_ledger_se_supprime_normalement(): void
    {
        $this->assertSame(0, DB::table('point_ledger')->where('user_id', $this->user->id)->count());

        $report = app(UserDeletionExecutor::class)->execute($this->user);

        // Aucune purge annoncee : il n'y avait rien a resoudre.
        $this->assertSame([], $report['resolved']);
        $this->assertDatabaseMissing('users', ['id' => $this->user->id]);
    }

    // =====================================================================
    // Garde de coherence — registre, comptage et purge ne peuvent pas diverger
    // =====================================================================

    /**
     * La declaration PILOTE reellement les trois surfaces.
     *
     * Ce test ne connait aucune valeur : il lit la raison declaree, en fabrique
     * une autre par construction, et verifie que le comptage ET la purge
     * suivent. Si demain quelqu'un change la declaration sans toucher
     * l'executor — ou l'inverse — ce test rougit.
     */
    public function test_la_declaration_du_registre_pilote_le_comptage_et_la_purge(): void
    {
        $declaree = $this->resolvableReason();
        $autre = $declaree.'-non-declaree';

        $resolvable = $this->insertLedger($this->user->id, $declaree);
        $bloquante = $this->insertLedger($this->user->id, $autre);

        $registry = app(UserDataLifecycleRegistry::class);

        // Comptage : seule la ligne NON declaree compte.
        $this->assertSame(1, $registry->preview($this->user)['block']['point_ledger']);
        $this->assertSame(
            1,
            collect(app(UserDeletionExecutor::class)->precheck($this->user)['blocks'])
                ->firstWhere('key', 'point_ledger')['count'],
            "precheck() et preview() doivent compter la MEME chose."
        );

        // La ligne non declaree retiree, le compte devient supprimable...
        DB::table('point_ledger')->where('id', $bloquante)->delete();
        $this->assertSame(0, $registry->preview($this->user)['block']['point_ledger']);

        // ... et la purge emporte exactement la ligne declaree.
        app(UserDeletionExecutor::class)->execute($this->user);
        $this->assertDatabaseMissing('point_ledger', ['id' => $resolvable]);
    }

    /**
     * La valeur ne vit qu'au registre.
     *
     * Le service de suppression ne doit JAMAIS porter la chaine : s'il la
     * portait, la regle existerait en deux exemplaires libres de diverger. Les
     * ECRIVAINS du bonus (inscription, seeders, ScenarioPacks) la portent
     * legitimement et ne sont pas concernes — la garde borne le chemin de
     * SUPPRESSION.
     */
    public function test_la_valeur_declaree_n_est_ecrite_nulle_part_dans_le_chemin_de_suppression(): void
    {
        $valeur = $this->resolvableReason();

        $registre = base_path('app/Services/UserDataLifecycleRegistry.php');
        $this->assertSame(
            1,
            preg_match_all('/'.preg_quote("'".$valeur."'", '/').'/', (string) file_get_contents($registre)),
            "La valeur doit apparaitre EXACTEMENT une fois au registre, et pas une de plus."
        );

        foreach (glob(base_path('app/Services/Users/*.php')) ?: [] as $fichier) {
            $this->assertStringNotContainsString(
                $valeur,
                (string) file_get_contents($fichier),
                basename($fichier).' porte la valeur en dur : la regle serait dupliquee.'
            );
        }
    }

    /**
     * Le ledger reste BLOCK au registre.
     *
     * TASK-1638 resout un sous-cas ; elle ne degrade pas la politique. Si
     * quelqu'un passait `point_ledger` en DELETE ou en DETACH pour « simplifier »,
     * ce test le dirait.
     */
    public function test_point_ledger_reste_une_politique_de_blocage(): void
    {
        $entree = collect(UserDataLifecycleRegistry::entries())->firstWhere('key', 'point_ledger');

        $this->assertSame(UserDataLifecycleRegistry::POLICY_BLOCK, $entree['policy']);
        $this->assertContains('point_ledger', UserDeletionExecutor::HARD_BLOCK_KEYS);
    }

    /**
     * Le critere est la RAISON, jamais `transaction_id`.
     *
     * Une ligne de bienvenue reste resolvable meme si elle portait une
     * transaction, et une ligne d'echange reste bloquante meme sans. Le mandat
     * a explicitement ecarte `transaction_id IS NULL` comme critere : cette
     * garde empeche de le reintroduire discretement.
     */
    public function test_le_critere_est_la_raison_et_non_la_presence_d_une_transaction(): void
    {
        $this->assertSame('reason', $this->declaration()['column']);

        // Une ligne bloquante SANS transaction bloque quand meme.
        $sansTransaction = $this->insertLedger($this->user->id, $this->blockingReason('adjustment'));
        $this->assertNull(DB::table('point_ledger')->where('id', $sansTransaction)->value('transaction_id'));

        $blocks = $this->assertRefused(fn () => app(UserDeletionExecutor::class)->execute($this->user));
        $this->assertSame(1, collect($blocks)->firstWhere('key', 'point_ledger')['count']);
    }

    // =====================================================================
    // Fabrique
    // =====================================================================

    private function insertLedger(string $userId, string $reason, ?string $transactionId = null, ?string $organizationId = null): string
    {
        $id = (string) Str::uuid();

        DB::table('point_ledger')->insert([
            'id' => $id,
            'user_id' => $userId,
            // Une ligne de ledger appartient a l'Organization de son membre :
            // la laisser pointer ailleurs fausserait le comptage org-scope.
            'organization_id' => $organizationId ?? $this->organization->id,
            'transaction_id' => $transactionId,
            'delta' => 100,
            'reason' => $reason,
            'created_at' => now(),
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

    /** @return list<array{key: string, count: int, message: string}> */
    private function assertRefused(callable $action): array
    {
        try {
            $action();

            $this->fail('La suppression a ete acceptee alors qu elle devait etre refusee.');
        } catch (UserDeletionBlockedException $blocked) {
            return $blocked->blocks;
        }
    }
}
