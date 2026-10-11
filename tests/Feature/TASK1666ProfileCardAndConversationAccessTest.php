<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\Organization;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-1666 — tout voir d'un echange AVANT de supprimer un compte.
 *
 * Deux manques constates a la recette de T1665, sur `/admin/transactions` :
 * le nom de l'acheteur ou du vendeur rendait 404, et la conversation n'etait
 * atteignable par aucun lien.
 *
 * ## Ce que ces tests protegent en priorite
 *
 * 1. **Le 404 n'etait pas un defaut de droits.** `profile.show` est une route
 *    FRONT qui fait `abort(404)` hors tenant courant. La corriger en donnant un
 *    bypass au SuperAdmin percerait `Organization = Tenant`. Les tests verifient
 *    donc que la garde reste INTACTE, et que l'ecran d'administration passe par
 *    la Fiche, qui ne depend d'aucun tenant.
 *
 * 2. **Un echange porte toujours un message systeme d'ouverture**
 *    (`sender_id` nul). Compter tous les messages ferait annoncer « 1 message »
 *    sur un echange ou personne n'a parle. Les tests separent explicitement les
 *    deux comptages.
 *
 * 3. **Un `transaction_id` sans `filter` doit quand meme montrer la
 *    conversation.** Sans la bascule forcee vers `exchanges`, le lien tomberait
 *    sur l'onglet ChatLoop par defaut et paraitrait vide alors que la
 *    conversation existe — un faux « il n'y a rien » est pire qu'une erreur.
 *
 * 4. La forme de l'UUID est validee avant d'atteindre PostgreSQL
 *    (`SQLSTATE 22P02`). Ce cas est **vide de sens en SQLite**, qui accepte le
 *    texte libre : il ne prouve quelque chose qu'en PostgreSQL.
 */
class TASK1666ProfileCardAndConversationAccessTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $superAdmin;

    private User $buyer;

    private User $seller;

    private Transaction $transaction;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->superAdmin = User::factory()->for($this->organization)->create(['is_admin' => true]);
        $this->buyer = User::factory()->for($this->organization)->create();
        $this->seller = User::factory()->for($this->organization)->create();

        $this->transaction = Transaction::factory()->create([
            'organization_id' => $this->organization->id,
            'buyer_id' => $this->buyer->id,
            'seller_id' => $this->seller->id,
        ]);
    }

    private function systemMessage(?Transaction $sur = null): Message
    {
        return Message::factory()
            ->forTransaction($sur ?? $this->transaction)
            ->system()
            ->create(['organization_id' => $this->organization->id]);
    }

    private function humanMessage(User $de, ?Transaction $sur = null): Message
    {
        return Message::factory()
            ->forTransaction($sur ?? $this->transaction)
            ->create([
                'organization_id' => $this->organization->id,
                'sender_id' => $de->id,
            ]);
    }

    /**
     * Libelle attendu du lien de conversation, pour un nombre de messages donne.
     *
     * Passe par la clef de traduction plutot que par une chaine en dur : la
     * suite tourne en locale `en`, et plusieurs formulations se ressemblent
     * d'une langue a l'autre au point de rendre une assertion VERTE sans rien
     * prouver. Ce qui est verifie reste le nombre.
     */
    private function libelleConversation(int $nombre): string
    {
        return trans_choice('admin.transactions_conversation_link', $nombre, ['count' => $nombre]);
    }

    // ───────────────────────── la conversation ─────────────────────────

    public function test_le_filtre_transaction_id_ne_rend_que_les_messages_de_cet_echange(): void
    {
        $this->systemMessage();
        $attendu = $this->humanMessage($this->buyer);

        $autre = Transaction::factory()->create([
            'organization_id' => $this->organization->id,
            'buyer_id' => $this->buyer->id,
            'seller_id' => $this->seller->id,
        ]);
        $horsSujet = $this->humanMessage($this->seller, $autre);

        $reponse = $this->actingAs($this->superAdmin)->get(route('admin.messages', [
            'filter' => 'exchanges',
            'organization_id' => $this->organization->id,
            'transaction_id' => $this->transaction->id,
        ]))->assertOk();

        $reponse->assertSee(Str::limit($attendu->body, 100), false);
        $reponse->assertDontSee(Str::limit($horsSujet->body, 100), false);
    }

    public function test_un_transaction_id_sans_filter_bascule_quand_meme_sur_les_echanges(): void
    {
        $attendu = $this->humanMessage($this->buyer);

        // Sans la bascule, le filtre par defaut est `chatloop` : la conversation
        // existe mais l'ecran serait vide. C'est le faux negatif a empecher.
        $this->actingAs($this->superAdmin)->get(route('admin.messages', [
            'organization_id' => $this->organization->id,
            'transaction_id' => $this->transaction->id,
        ]))->assertOk()->assertSee(Str::limit($attendu->body, 100), false);
    }

    public function test_un_uuid_malforme_ne_leve_pas_d_erreur_et_ne_rend_rien(): void
    {
        $present = $this->humanMessage($this->buyer);

        // En PostgreSQL une valeur libre sur une colonne `uuid` leverait 22P02.
        $this->actingAs($this->superAdmin)->get(route('admin.messages', [
            'filter' => 'exchanges',
            'organization_id' => $this->organization->id,
            'transaction_id' => 'pas-un-uuid',
        ]))->assertOk()->assertDontSee(Str::limit($present->body, 100), false);
    }

    public function test_un_uuid_inconnu_rend_une_liste_vide(): void
    {
        $present = $this->humanMessage($this->buyer);

        $this->actingAs($this->superAdmin)->get(route('admin.messages', [
            'filter' => 'exchanges',
            'organization_id' => $this->organization->id,
            'transaction_id' => (string) Str::uuid(),
        ]))->assertOk()->assertDontSee(Str::limit($present->body, 100), false);
    }

    public function test_le_filtre_est_reporte_en_champ_cache_sinon_il_s_elargit_en_silence(): void
    {
        $this->humanMessage($this->buyer);

        $this->actingAs($this->superAdmin)->get(route('admin.messages', [
            'filter' => 'exchanges',
            'organization_id' => $this->organization->id,
            'transaction_id' => $this->transaction->id,
        ]))->assertOk()->assertSee(
            '<input type="hidden" name="transaction_id" value="'.$this->transaction->id.'">',
            false
        );
    }

    public function test_une_liste_bornee_a_une_conversation_le_dit_a_l_ecran(): void
    {
        $this->humanMessage($this->buyer);

        $this->actingAs($this->superAdmin)->get(route('admin.messages', [
            'filter' => 'exchanges',
            'organization_id' => $this->organization->id,
            'transaction_id' => $this->transaction->id,
        ]))->assertOk()->assertSee(strtoupper(substr($this->transaction->id, 0, 8)), false);
    }

    public function test_sans_transaction_id_aucune_banniere_ni_champ_cache(): void
    {
        $this->humanMessage($this->buyer);

        $this->actingAs($this->superAdmin)->get(route('admin.messages', [
            'filter' => 'exchanges',
            'organization_id' => $this->organization->id,
        ]))->assertOk()->assertDontSee('name="transaction_id"', false);
    }

    public function test_un_membre_ordinaire_n_atteint_pas_les_messages_d_administration(): void
    {
        $this->actingAs($this->buyer)->get(route('admin.messages', [
            'filter' => 'exchanges',
            'transaction_id' => $this->transaction->id,
        ]))->assertForbidden();
    }

    // ──────────── le lien depuis la liste des echanges ────────────

    public function test_la_liste_des_echanges_mene_a_la_conversation_quand_on_a_parle(): void
    {
        $this->systemMessage();
        $this->humanMessage($this->buyer);
        $this->humanMessage($this->seller);

        $this->actingAs($this->superAdmin)
            ->get(route('admin.transactions', ['organization_id' => $this->organization->id]))
            ->assertOk()
            ->assertSee('transaction_id='.$this->transaction->id, false)
            // Le libelle est assertie VIA la clef, pas en dur : cette suite tourne
            // en locale `en`, et « 2 messages » matche aussi bien l'anglais que le
            // francais. Une assertion en dur passait donc par COINCIDENCE de
            // formulation. Ce qui est reellement protege ici, c'est le COMPTE :
            // on exige 2 et on refuse 3, le message systeme devant rester exclu.
            ->assertSee($this->libelleConversation(2), false)
            ->assertDontSee($this->libelleConversation(3), false);
    }

    public function test_un_echange_sans_message_humain_ne_promet_aucune_discussion(): void
    {
        // Seul le marqueur systeme d'ouverture existe. Annoncer « 1 message »
        // ferait croire a un echange de paroles qui n'a pas eu lieu.
        $this->systemMessage();

        $reponse = $this->actingAs($this->superAdmin)
            ->get(route('admin.transactions', ['organization_id' => $this->organization->id]))
            ->assertOk();

        $reponse->assertDontSee('transaction_id='.$this->transaction->id, false);
        $reponse->assertSee(__('admin.transactions_conversation_empty'), false);
    }

    public function test_le_compte_affiche_ignore_les_messages_systeme(): void
    {
        $this->systemMessage();
        $this->systemMessage();
        $this->humanMessage($this->buyer);

        $this->actingAs($this->superAdmin)
            ->get(route('admin.transactions', ['organization_id' => $this->organization->id]))
            ->assertOk()
            ->assertSee($this->libelleConversation(1), false)
            // Trois messages existent, dont deux systeme : annoncer 3 serait le
            // defaut exact que ce test protege.
            ->assertDontSee($this->libelleConversation(3), false);
    }

    // ───────────────────────── la Fiche ─────────────────────────

    public function test_les_noms_de_la_liste_ouvrent_la_fiche_et_non_la_route_front(): void
    {
        $reponse = $this->actingAs($this->superAdmin)
            ->get(route('admin.transactions', ['organization_id' => $this->organization->id]))
            ->assertOk();

        // C'etait la cause exacte du 404 : une route FRONT bornee au tenant.
        $reponse->assertDontSee(route('profile.show', $this->buyer), false);
        $reponse->assertDontSee(route('profile.show', $this->seller), false);

        $reponse->assertSee("open-user-profile", false);
        $reponse->assertSee($this->buyer->id, false);
        $reponse->assertSee($this->seller->id, false);
    }

    public function test_la_fiche_est_presente_sur_les_deux_ecrans(): void
    {
        foreach ([
            route('admin.transactions', ['organization_id' => $this->organization->id]),
            route('admin.users'),
        ] as $url) {
            $this->actingAs($this->superAdmin)->get($url)->assertOk()
                ->assertSee('adminUserProfileModal()', false)
                ->assertSee('open-user-profile', false);
        }
    }

    public function test_la_fiche_de_admin_users_s_ouvre_toujours_par_evenement(): void
    {
        $this->actingAs($this->superAdmin)->get(route('admin.users'))->assertOk()
            ->assertSee("\$dispatch('open-user-profile'", false)
            ->assertSee(route('admin.users.profile-summary', ['user' => '__ID__']), false);
    }

    // ────────── la garde de tenant de `profile.show` reste intacte ──────────

    public function test_la_route_front_refuse_toujours_un_compte_hors_tenant(): void
    {
        $horsTenant = User::factory()->create(['organization_id' => null]);

        // Le SuperAdmin n'y echappe pas : c'est la frontiere de tenant, pas un
        // droit manquant. T1666 ne la perce pas, il la contourne par la Fiche.
        $this->actingAs($this->superAdmin)
            ->get(route('profile.show', $horsTenant))
            ->assertNotFound();
    }

    public function test_le_lien_profil_n_est_affiche_que_la_ou_il_mene_quelque_part(): void
    {
        $horsTenant = User::factory()->create(['organization_id' => null]);

        // Le tenant courant est LIE A LA REQUETE : sans ce binding, aucun compte
        // n'appartient a `currentOrganization()` et le test vaudrait aussi avec
        // une garde qui masque tout. On fixe donc le tenant pour que le cas
        // POSITIF soit reellement observe.
        $this->app->instance('current_organization', $this->organization);

        $reponse = $this->actingAs($this->superAdmin)
            ->get(route('admin.users'))
            ->assertOk();

        $reponse->assertSee(route('profile.show', $this->buyer), false);
        $reponse->assertDontSee(route('profile.show', $horsTenant), false);

        // Et le compte hors tenant reste atteignable par la Fiche, qui ne
        // depend d'aucun tenant : masquer le lien ne doit pas rendre le compte
        // invisible.
        $reponse->assertSee($horsTenant->id, false);
    }
}
