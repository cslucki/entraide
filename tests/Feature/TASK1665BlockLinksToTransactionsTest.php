<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Users\UserDeletionExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-1665 — un blocage de suppression dit desormais OU aller pour le lever.
 *
 * La modal de `/admin/users` affichait « ce membre est vendeur dans 1 echange »
 * sans le moindre moyen d'identifier l'echange. Elle rend maintenant un lien par
 * transaction bloquante, vers `/admin/transactions` filtre sur celle-la.
 *
 * ## Ce que ces tests protegent en priorite
 *
 * Le compte d'un blocage ne vient pas d'un `where` ecrit a la main : il est lu au
 * **registre lifecycle**, avec `excludeResolvableRows()`. Les liens le sont
 * aussi. Le test de coherence `count` <-> nombre de liens est donc le plus
 * important du fichier : si un jour l'un des deux predicats derive, la modal
 * annoncerait « 1 echange » en montrant zero lien, et rien d'autre ne le dirait.
 *
 * Le filtre `transaction_id` est teste sur une forme INVALIDE, et sur les deux
 * moteurs : une valeur libre atteignant une colonne `uuid` en PostgreSQL leve
 * `SQLSTATE 22P02` et rendrait un 500 la ou un filtre sans correspondance doit
 * simplement ne rien rendre. SQLite ne reproduit pas ce defaut — c'est pourquoi
 * ce cas ne vaut que joue aussi en PostgreSQL.
 */
class TASK1665BlockLinksToTransactionsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $superAdmin;

    private User $target;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->superAdmin = User::factory()->for($this->organization)->create(['is_admin' => true]);
        $this->target = User::factory()->for($this->organization)->create();
    }

    /** @return array<int, array{message: string, count: int, links?: list<array{label: string, url: string}>}> */
    private function blocks(?User $sur = null): array
    {
        return $this->actingAs($this->superAdmin)
            ->getJson(route('admin.users.delete-precheck', $sur ?? $this->target))
            ->assertOk()
            ->json('blocks');
    }

    private function transaction(array $attributs): Transaction
    {
        return Transaction::factory()->create($attributs + ['organization_id' => $this->organization->id]);
    }

    // ── 1 + 2. Vendeur, puis acheteur : un blocage, un lien ────────────────

    public function test_a_seller_of_one_blocking_transaction_gets_exactly_one_link(): void
    {
        $echange = $this->transaction(['seller_id' => $this->target->id]);

        $blocs = collect($this->blocks())->filter(fn ($b) => isset($b['links']))->values();

        $this->assertCount(1, $blocs, 'un seul blocage doit porter des liens');
        $this->assertSame(1, $blocs[0]['count']);
        $this->assertCount(1, $blocs[0]['links']);
        $this->assertStringContainsString('transaction_id='.$echange->id, $blocs[0]['links'][0]['url']);
    }

    public function test_a_buyer_of_one_blocking_transaction_gets_exactly_one_link(): void
    {
        $echange = $this->transaction(['buyer_id' => $this->target->id]);

        $blocs = collect($this->blocks())->filter(fn ($b) => isset($b['links']))->values();

        $this->assertCount(1, $blocs);
        $this->assertCount(1, $blocs[0]['links']);
        $this->assertStringContainsString('transaction_id='.$echange->id, $blocs[0]['links'][0]['url']);
    }

    // ── 3. Plusieurs transactions : tous les liens ─────────────────────────

    public function test_several_blocking_transactions_produce_one_link_each(): void
    {
        $echanges = collect(range(1, 3))->map(fn () => $this->transaction(['seller_id' => $this->target->id]));

        $bloc = collect($this->blocks())->firstWhere(fn ($b) => isset($b['links']));

        $this->assertSame(3, $bloc['count']);
        $this->assertCount(3, $bloc['links']);

        foreach ($echanges as $echange) {
            $this->assertTrue(
                collect($bloc['links'])->contains(fn (array $l) => str_contains($l['url'], 'transaction_id='.$echange->id)),
                "l'echange {$echange->id} doit avoir son lien"
            );
        }
    }

    public function test_being_both_buyer_and_seller_gives_two_blocks_each_with_its_own_link(): void
    {
        $vendu = $this->transaction(['seller_id' => $this->target->id]);
        $achete = $this->transaction(['buyer_id' => $this->target->id]);

        $blocs = collect($this->blocks())->filter(fn ($b) => isset($b['links']))->values();

        $this->assertCount(2, $blocs, 'acheteur et vendeur sont deux blocages distincts');

        $urls = $blocs->flatMap(fn ($b) => collect($b['links'])->pluck('url'))->implode(' ');
        $this->assertStringContainsString('transaction_id='.$vendu->id, $urls);
        $this->assertStringContainsString('transaction_id='.$achete->id, $urls);
    }

    // ── 5. LE test qui compte : count et liens ne doivent jamais diverger ──

    public function test_the_count_and_the_number_of_links_always_agree(): void
    {
        collect(range(1, 4))->each(fn () => $this->transaction(['seller_id' => $this->target->id]));
        collect(range(1, 2))->each(fn () => $this->transaction(['buyer_id' => $this->target->id]));

        foreach ($this->blocks() as $bloc) {
            if (! isset($bloc['links'])) {
                continue;
            }

            $this->assertCount(
                $bloc['count'],
                $bloc['links'],
                'un compte annonce sans le meme nombre de liens signale deux predicats qui ont derive'
            );
        }
    }

    public function test_the_links_carry_no_duplicate_url(): void
    {
        collect(range(1, 3))->each(fn () => $this->transaction(['seller_id' => $this->target->id]));

        $bloc = collect($this->blocks())->firstWhere(fn ($b) => isset($b['links']));
        $urls = collect($bloc['links'])->pluck('url');

        $this->assertSame($urls->count(), $urls->unique()->count(), 'un meme echange ne doit pas apparaitre deux fois');
    }

    // ── 6. Aucun faux lien sur les autres blocages ─────────────────────────

    public function test_a_block_that_is_not_about_transactions_carries_no_link(): void
    {
        // `orgs_as_admin` : le membre est responsable de son Organization.
        $this->organization->update(['admin_id' => $this->target->id]);

        $blocs = $this->blocks();

        $this->assertNotEmpty($blocs, 'le blocage doit bien exister');
        foreach ($blocs as $bloc) {
            $this->assertArrayNotHasKey('links', $bloc, 'seuls les blocages transaction portent des liens');
        }
    }

    public function test_a_user_without_any_transaction_gets_no_transaction_link(): void
    {
        foreach ($this->blocks() as $bloc) {
            $this->assertArrayNotHasKey('links', $bloc);
        }
    }

    // ── 7. La surface reste SuperAdmin ─────────────────────────────────────

    public function test_an_ordinary_member_still_cannot_call_the_precheck(): void
    {
        $ordinaire = User::factory()->for($this->organization)->create(['is_admin' => false]);

        $this->actingAs($ordinaire)
            ->getJson(route('admin.users.delete-precheck', $this->target))
            ->assertStatus(403);
    }

    // ── 8 / 9 / 7bis. Le filtre exact de /admin/transactions ───────────────

    public function test_an_exact_transaction_id_shows_only_that_exchange(): void
    {
        $vise = $this->transaction(['seller_id' => $this->target->id]);
        $autre = $this->transaction(['seller_id' => $this->target->id]);

        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.transactions', ['transaction_id' => $vise->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString((string) $vise->id, $html);
        $this->assertStringNotContainsString((string) $autre->id, $html, 'les autres echanges ne doivent pas apparaitre');
    }

    public function test_a_malformed_transaction_id_returns_an_empty_list_and_never_a_500(): void
    {
        $echange = $this->transaction(['seller_id' => $this->target->id]);

        foreach (['pas-un-uuid', '1 OR 1=1', "'; drop table transactions; --", '00000000'] as $invalide) {
            $html = $this->actingAs($this->superAdmin)
                ->get(route('admin.transactions', ['transaction_id' => $invalide]))
                ->assertOk()
                ->getContent();

            $this->assertStringNotContainsString((string) $echange->id, $html, "forme invalide [{$invalide}] : la liste doit rester vide");
        }

        // La table est intacte : aucune de ces valeurs n'a ete executee.
        $this->assertSame(1, Transaction::withoutGlobalScopes()->count());
    }

    public function test_a_wellformed_but_unknown_transaction_id_returns_an_empty_list(): void
    {
        $echange = $this->transaction(['seller_id' => $this->target->id]);

        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.transactions', ['transaction_id' => (string) Str::uuid()]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString((string) $echange->id, $html);
    }

    // ── 10 + 11. Les filtres existants ne sont pas casses ──────────────────

    public function test_the_existing_status_filter_still_works(): void
    {
        $enAttente = $this->transaction(['seller_id' => $this->target->id, 'status' => 'pending']);
        $terminee = $this->transaction(['seller_id' => $this->target->id, 'status' => 'completed']);

        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.transactions', ['status' => 'completed']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString((string) $terminee->id, $html);
        $this->assertStringNotContainsString((string) $enAttente->id, $html);
    }

    public function test_the_existing_search_filter_still_targets_people(): void
    {
        $vendeur = User::factory()->for($this->organization)->create(['name' => 'Zorglub']);
        $sien = $this->transaction(['seller_id' => $vendeur->id]);
        $autre = $this->transaction(['seller_id' => $this->target->id]);

        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.transactions', ['search' => 'Zorglub']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString((string) $sien->id, $html);
        $this->assertStringNotContainsString((string) $autre->id, $html);
    }

    // ── 11. Transaction supprimee -> le blocage disparait ──────────────────

    public function test_once_the_transaction_is_gone_the_block_is_gone_too(): void
    {
        $echange = $this->transaction(['seller_id' => $this->target->id]);

        $this->assertNotEmpty(
            collect($this->blocks())->filter(fn ($b) => isset($b['links']))->all(),
            'le blocage doit exister avant'
        );

        $this->actingAs($this->superAdmin)
            ->delete(route('admin.transactions.destroy', $echange->id))
            ->assertRedirect();

        $this->assertEmpty(
            collect($this->blocks())->filter(fn ($b) => isset($b['links']))->all(),
            'une fois l\'echange supprime, plus aucun blocage transaction'
        );
        $this->assertSame([], app(UserDeletionExecutor::class)->precheck($this->target->fresh())['blocks']);
    }

    // ── La modal rend-elle vraiment les liens ? ────────────────────────────

    public function test_la_page_de_suppression_rend_les_liens_du_blocage(): void
    {
        // TASK-1668 — le rendu etait fait par Alpine dans une modal, et seule la
        // PRESENCE du gabarit etait verifiable. La page rend cote serveur : on
        // peut desormais exiger le lien LUI-MEME, ce qui est strictement plus
        // fort que ce que ce test prouvait avant.
        $transaction = Transaction::factory()->create([
            'organization_id' => $this->organization->id,
            'seller_id' => $this->target->id,
            'buyer_id' => User::factory()->for($this->organization)->create()->id,
        ]);

        $this->actingAs($this->superAdmin)
            ->get(route('admin.users.delete-page', $this->target))
            ->assertOk()
            ->assertSee('transaction_id='.$transaction->id, false);
    }
}
