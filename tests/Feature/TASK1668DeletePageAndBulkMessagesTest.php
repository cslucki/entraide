<?php

namespace Tests\Feature;

use App\Models\LoopMessage;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-1668 — deux volets qui se tiennent : l'un donne la place, l'autre
 * l'occupe.
 *
 * **A.** La suppression d'un compte quitte la fenetre surgissante pour une
 * PAGE. Elle avait grossi a chaque TASK et debordait.
 *
 * **B.** Les messages d'administration se suppriment en groupe.
 *
 * ## Ce que ces tests protegent en priorite
 *
 * 1. **La page consomme le MEME payload que l'ancien endpoint JSON.** Rien du
 *    travail de T1665/T1666/T1667 n'a ete reecrit : les liens de blocage et le
 *    detail des transferts doivent se retrouver a l'identique.
 *
 * 2. **Le perimetre d'une suppression groupee est RECALCULE au serveur.** Un
 *    identifiant poste qui n'appartient pas a la liste affichee doit etre
 *    IGNORE — ni supprime, ni cause d'echec global. C'est le test le plus
 *    important du fichier : sans lui, n'importe qui pourrait supprimer
 *    n'importe quel message en devinant un identifiant.
 *
 * 3. **« Tout cocher » ne vaut que pour la page affichee.** Un « tout le
 *    filtre » supprimerait des lignes que personne n'a vues.
 */
class TASK1668DeletePageAndBulkMessagesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $superAdmin;

    private User $cible;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->superAdmin = User::factory()->for($this->organization)->create(['is_admin' => true]);
        $this->cible = User::factory()->for($this->organization)->create();
    }

    // ═══════════════════ A. la page de suppression ═══════════════════

    public function test_la_page_s_ouvre_et_nomme_le_compte(): void
    {
        $this->actingAs($this->superAdmin)
            ->get(route('admin.users.delete-page', $this->cible))
            ->assertOk()
            ->assertSee($this->cible->fullName, false)
            ->assertSee($this->cible->email, false);
    }

    public function test_un_membre_ordinaire_n_atteint_pas_la_page(): void
    {
        $this->actingAs($this->cible)
            ->get(route('admin.users.delete-page', $this->cible))
            ->assertForbidden();
    }

    public function test_sans_blocage_la_page_porte_le_formulaire_destructif(): void
    {
        $page = $this->actingAs($this->superAdmin)
            ->get(route('admin.users.delete-page', $this->cible))
            ->assertOk();

        $page->assertSee(route('admin.users.destroy', $this->cible), false);
        // L'empreinte vient du serveur : c'est elle qui rend une decision
        // perimee refusable.
        $page->assertSee('name="preview_fingerprint"', false);
        $page->assertSee(__('admin.user_delete_modal_irreversible'), false);
    }

    public function test_avec_un_blocage_la_page_ne_porte_AUCUN_formulaire_destructif(): void
    {
        DB::table('point_ledger')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $this->cible->id,
            'organization_id' => $this->organization->id,
            'delta' => 10,
            // Pas `welcome_bonus` : T1638 l'a rendu RESOLVABLE.
            'reason' => 'exchange_earned',
            'created_at' => now(),
        ]);

        $page = $this->actingAs($this->superAdmin)
            ->get(route('admin.users.delete-page', $this->cible))
            ->assertOk();

        $page->assertSee(__('admin.user_delete_blocked_title'), false);
        $page->assertDontSee(route('admin.users.destroy', $this->cible), false);
    }

    public function test_la_page_porte_les_liens_de_blocage_comme_la_modal(): void
    {
        $transaction = Transaction::factory()->create([
            'organization_id' => $this->organization->id,
            'seller_id' => $this->cible->id,
            'buyer_id' => User::factory()->for($this->organization)->create()->id,
        ]);

        // Le payload est celui du precheck : ce que T1665 avait livre doit se
        // retrouver a l'identique, sans avoir ete reecrit.
        $this->actingAs($this->superAdmin)
            ->get(route('admin.users.delete-page', $this->cible))
            ->assertOk()
            ->assertSee('transaction_id='.$transaction->id, false);
    }

    public function test_la_suppression_depuis_la_page_aboutit(): void
    {
        $empreinte = $this->actingAs($this->superAdmin)
            ->getJson(route('admin.users.delete-precheck', $this->cible))
            ->assertOk()
            ->json('preview_fingerprint');

        $this->actingAs($this->superAdmin)
            ->delete(route('admin.users.destroy', $this->cible), ['preview_fingerprint' => $empreinte])
            ->assertRedirect();

        $this->assertDatabaseMissing('users', ['id' => $this->cible->id]);
    }

    public function test_la_modal_a_bien_disparu_de_la_liste(): void
    {
        $html = $this->actingAs($this->superAdmin)->get(route('admin.users'))->assertOk()->getContent();

        // Les deux ne coexistent pas : garder un raccourci modal doublerait la
        // surface a tester pour la meme garantie.
        $this->assertStringNotContainsString('openDelete(', $html);
        $this->assertStringContainsString(route('admin.users.delete-page', $this->cible), $html);
    }

    // ═══════════════ B. la suppression groupee de messages ═══════════════

    private function conversation(int $combien): array
    {
        $acheteur = User::factory()->for($this->organization)->create();
        $transaction = Transaction::factory()->create([
            'organization_id' => $this->organization->id,
            'buyer_id' => $acheteur->id,
            'seller_id' => $this->cible->id,
        ]);

        $messages = collect(range(1, $combien))->map(fn () => Message::factory()
            ->forTransaction($transaction)
            ->create(['organization_id' => $this->organization->id, 'sender_id' => $acheteur->id]));

        return [$transaction, $messages];
    }

    public function test_plusieurs_messages_se_suppriment_en_une_fois(): void
    {
        [$transaction, $messages] = $this->conversation(3);

        $this->actingAs($this->superAdmin)
            ->delete(route('admin.messages.bulk-destroy'), [
                'filter' => 'exchanges',
                'organization_id' => $this->organization->id,
                'transaction_id' => $transaction->id,
                'ids' => [$messages[0]->id, $messages[1]->id],
            ])->assertRedirect();

        $this->assertDatabaseMissing('messages', ['id' => $messages[0]->id]);
        $this->assertDatabaseMissing('messages', ['id' => $messages[1]->id]);
        // Le troisieme n'etait pas coche : il reste.
        $this->assertDatabaseHas('messages', ['id' => $messages[2]->id]);
    }

    public function test_un_identifiant_hors_du_perimetre_affiche_est_ignore(): void
    {
        [$transaction, $messages] = $this->conversation(1);
        [$autreTransaction, $horsPerimetre] = $this->conversation(1);

        // La garde essentielle : le perimetre est RECALCULE au serveur. Sans
        // elle, il suffirait de deviner un identifiant pour supprimer n'importe
        // quel message de la plateforme.
        $this->actingAs($this->superAdmin)
            ->delete(route('admin.messages.bulk-destroy'), [
                'filter' => 'exchanges',
                'organization_id' => $this->organization->id,
                'transaction_id' => $transaction->id,
                'ids' => [$messages[0]->id, $horsPerimetre[0]->id],
            ])->assertRedirect();

        $this->assertDatabaseMissing('messages', ['id' => $messages[0]->id]);
        $this->assertDatabaseHas('messages', ['id' => $horsPerimetre[0]->id]);
    }

    public function test_une_selection_entierement_hors_perimetre_ne_supprime_rien(): void
    {
        [$transaction] = $this->conversation(1);
        [, $ailleurs] = $this->conversation(1);

        $this->actingAs($this->superAdmin)
            ->delete(route('admin.messages.bulk-destroy'), [
                'filter' => 'exchanges',
                'organization_id' => $this->organization->id,
                'transaction_id' => $transaction->id,
                'ids' => [$ailleurs[0]->id],
            ])->assertRedirect();

        $this->assertDatabaseHas('messages', ['id' => $ailleurs[0]->id]);
    }

    public function test_une_selection_vide_est_refusee(): void
    {
        $this->actingAs($this->superAdmin)
            ->delete(route('admin.messages.bulk-destroy'), [
                'filter' => 'exchanges',
                'organization_id' => $this->organization->id,
                'ids' => [],
            ])->assertSessionHasErrors('ids');
    }

    public function test_le_mode_tous_est_refuse(): void
    {
        [$transaction, $messages] = $this->conversation(1);

        // Le flux unifie melange LoopMessage et Message : un identifiant ne dit
        // pas auquel il appartient.
        $this->actingAs($this->superAdmin)
            ->delete(route('admin.messages.bulk-destroy'), [
                'filter' => 'all',
                'organization_id' => $this->organization->id,
                'ids' => [$messages[0]->id],
            ])->assertSessionHasErrors('filter');

        $this->assertDatabaseHas('messages', ['id' => $messages[0]->id]);
    }

    public function test_un_membre_ordinaire_ne_supprime_pas_en_groupe(): void
    {
        [, $messages] = $this->conversation(1);

        $this->actingAs($this->cible)
            ->delete(route('admin.messages.bulk-destroy'), [
                'filter' => 'exchanges',
                'ids' => [$messages[0]->id],
            ])->assertForbidden();

        $this->assertDatabaseHas('messages', ['id' => $messages[0]->id]);
    }

    public function test_aucun_formulaire_imbrique_dans_la_page(): void
    {
        [$transaction] = $this->conversation(1);

        // Chaque ligne porte deja son formulaire de suppression unitaire.
        // Envelopper le tableau dans le formulaire groupe creerait des
        // formulaires IMBRIQUES : invalides en HTML, le navigateur supprime les
        // internes et la suppression ligne a ligne cesse de fonctionner.
        // Aucun test cote serveur ne l'aurait vu — d'ou celui-ci.
        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.messages', [
                'filter' => 'exchanges',
                'organization_id' => $this->organization->id,
                'transaction_id' => $transaction->id,
            ]))
            ->assertOk()
            ->getContent();

        $profondeur = 0;
        $maximum = 0;

        foreach (preg_split('/(<form\b|<\/form>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE) as $morceau) {
            if ($morceau === '<form') {
                $maximum = max($maximum, ++$profondeur);
            } elseif ($morceau === '</form>') {
                $profondeur--;
            }
        }

        $this->assertSame(1, $maximum, 'aucun formulaire ne doit en contenir un autre');

        // Et le rattachement TIENT : l'identifiant que les cases designent doit
        // exister comme formulaire dans la page. Asserter seulement la presence
        // de `form="..."` laisserait passer un identifiant qui ne pointe sur
        // rien — les cases ne seraient alors jamais envoyees.
        $this->assertSame(
            1,
            preg_match('/<input[^>]+name="ids\[\]"[^>]+form="([^"]+)"/', $html, $rattachement),
            'les cases doivent designer un formulaire'
        );

        $this->assertStringContainsString(
            'id="'.$rattachement[1].'"',
            $html,
            "les cases designent le formulaire « {$rattachement[1]} », qui n'existe pas dans la page"
        );
    }

    public function test_l_ecran_offre_la_selection_sur_une_conversation(): void
    {
        [$transaction] = $this->conversation(2);

        $this->actingAs($this->superAdmin)
            ->get(route('admin.messages', [
                'filter' => 'exchanges',
                'organization_id' => $this->organization->id,
                'transaction_id' => $transaction->id,
            ]))
            ->assertOk()
            ->assertSee('name="ids[]"', false)
            ->assertSee(route('admin.messages.bulk-destroy'), false);
    }

    public function test_l_ecran_n_offre_pas_la_selection_en_mode_tous(): void
    {
        $this->conversation(1);

        // Proposer puis refuser serait pire que ne pas proposer.
        $this->actingAs($this->superAdmin)
            ->get(route('admin.messages', ['filter' => 'all', 'organization_id' => $this->organization->id]))
            ->assertOk()
            ->assertDontSee('name="ids[]"', false);
    }

    public function test_aucun_confirm_natif_sur_l_ecran(): void
    {
        [$transaction] = $this->conversation(1);

        // T1655 : un `confirm()` non gere par Playwright annule la soumission
        // SANS erreur ni log. La confirmation est une etape visible.
        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.messages', [
                'filter' => 'exchanges',
                'organization_id' => $this->organization->id,
                'transaction_id' => $transaction->id,
            ]))
            ->assertOk()
            ->getContent();

        // L'assertion porte sur la confirmation GROUPEE, pas sur la page entiere :
        // les formulaires de suppression UNITAIRE portent, eux, un `confirm()`
        // natif — defaut PRE-EXISTANT, signale et non corrige ici.
        $this->assertStringContainsString(__('admin.messages_bulk_confirm_yes'), $html);
        $this->assertStringNotContainsString(
            'onsubmit="return confirm',
            substr($html, strpos($html, 'bulk-messages'), 2000),
            'la confirmation groupee doit etre une etape visible, pas un confirm() natif'
        );
    }
}
