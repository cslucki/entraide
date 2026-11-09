<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Service;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TASK-1795 correctif 4 — `/admin/messages` : prenom, destinataire, onglet prive.
 *
 * Trois demandes de Cyril, une seule table : « mettre le prénom, on a que le
 * nom dans la colonne Expéditeur », « ajouter le destinataire », « on va créer
 * une nouvelle vue "Messages privés" ».
 *
 * ## Le prenom ne manquait pas a la VUE
 *
 * `admin/messages/index.blade.php` affichait DEJA `$message->sender->full_name`.
 * Ce qui manquait vivait dans le controleur : `with(['sender:id,name,email'])`
 * — une selection de colonnes qui EXCLUT `first_name`. L'accesseur
 * `getFullNameAttribute()` lisait donc `first_name = null` et retombait sur le
 * seul `name`. Mesure du 09/11/2026 sur le jumeau : avec le select d'origine
 * `full_name = 'SLUCKI'` ; avec `first_name` ajoute, `full_name = 'Cyril SLUCKI'`.
 */
class TASK1795AdminPrivateMessagesViewTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $superAdmin;

    private User $amandine;

    private User $benoit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create([
            'slug' => 'org-1795d',
            'is_active' => true,
            'is_default' => true,
            'loops_enabled' => true,
        ]);

        $this->superAdmin = User::factory()->create([
            'organization_id' => $this->org->id,
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);

        $this->amandine = User::factory()->create([
            'organization_id' => $this->org->id,
            'first_name' => 'Amandine',
            'name' => 'Rousseau',
        ]);

        $this->benoit = User::factory()->create([
            'organization_id' => $this->org->id,
            'first_name' => 'Benoit',
            'name' => 'Lefevre',
        ]);

        app()->instance('current_organization', $this->org);

        Http::preventStrayRequests();
        Http::fake();
    }

    private function conversationPrivee(string $body): Message
    {
        $transaction = Transaction::factory()->create([
            'organization_id' => $this->org->id,
            'buyer_id' => $this->amandine->id,
            'seller_id' => $this->benoit->id,
            'service_id' => null,
            'request_id' => null,
            'points_proposed' => 0,
            'status' => 'pending',
        ]);

        return Message::query()->create([
            'transaction_id' => $transaction->id,
            'sender_id' => $this->amandine->id,
            'organization_id' => $this->org->id,
            'body' => $body,
        ]);
    }

    private function echangeDEntraide(string $body): Message
    {
        $service = Service::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->benoit->id,
            'category_id' => Category::factory()->create(['organization_id' => $this->org->id])->id,
            'title' => 'Relecture de devis',
        ]);

        $transaction = Transaction::factory()->create([
            'organization_id' => $this->org->id,
            'buyer_id' => $this->amandine->id,
            'seller_id' => $this->benoit->id,
            'service_id' => $service->id,
            'request_id' => null,
            'points_proposed' => 20,
            'status' => 'pending',
        ]);

        return Message::query()->create([
            'transaction_id' => $transaction->id,
            'sender_id' => $this->amandine->id,
            'organization_id' => $this->org->id,
            'body' => $body,
        ]);
    }

    private function messageDeBoucle(string $body): LoopMessage
    {
        $loop = Loop::factory()->create([
            'organization_id' => $this->org->id,
            'visibility' => 'public',
            'status' => 'active',
        ]);

        return LoopMessage::query()->create([
            'loop_id' => $loop->id,
            'sender_id' => $this->amandine->id,
            'organization_id' => $this->org->id,
            'body' => $body,
            'type' => 'user',
        ]);
    }

    /**
     * L'ecran `/admin/*` ne lie AUCUNE Organization courante.
     *
     * `ResolveUrlOrganization::$platformGlobalPrefixes` contient `admin` : la
     * surface est « plateforme globale ». Lier `current_organization` dans
     * `setUp()` — ce dont les factories ont besoin — et la laisser en place
     * pendant la requete donnerait une FAUSSE ASSURANCE : la premiere version
     * de ce correctif classait les conversations par un `whereHas` sur
     * `transactions`, scope tenant compris, et rendait 0 ligne sur l'ecran
     * reel pendant que ces tests restaient verts.
     *
     * On defait donc le liage juste avant la requete, comme sur l'ecran.
     */
    private function commeSurLEcranAdmin(): static
    {
        app()->forgetInstance('current_organization');

        return $this;
    }

    // ---------------------------------------------------------------------
    // Le prenom
    // ---------------------------------------------------------------------

    public function test_la_colonne_expediteur_porte_le_prenom_et_le_nom(): void
    {
        $this->conversationPrivee('Message prive');

        $this->commeSurLEcranAdmin()->actingAs($this->superAdmin)
            ->get(route('admin.messages', ['filter' => 'private']))
            ->assertOk()
            // Le nom complet, pas seulement le patronyme : c'est exactement ce
            // que la selection de colonnes empechait.
            ->assertSee('Amandine Rousseau');
    }

    public function test_le_prenom_est_charge_pour_les_messages_de_boucle_aussi(): void
    {
        $this->messageDeBoucle('Message de boucle');

        $this->commeSurLEcranAdmin()->actingAs($this->superAdmin)
            ->get(route('admin.messages', ['filter' => 'chatloop']))
            ->assertOk()
            ->assertSee('Amandine Rousseau');
    }

    // ---------------------------------------------------------------------
    // Le destinataire
    // ---------------------------------------------------------------------

    public function test_la_colonne_destinataire_nomme_l_autre_partie(): void
    {
        $this->conversationPrivee('Message prive');

        $reponse = $this->commeSurLEcranAdmin()->actingAs($this->superAdmin)
            ->get(route('admin.messages', ['filter' => 'private']))
            ->assertOk();

        $reponse->assertSee('Destinataire');
        // L'expediteur est Amandine : le destinataire est donc Benoit, l'autre
        // partie de la transaction — jamais l'expediteur lui-meme.
        $reponse->assertSee('Benoit Lefevre');
    }

    public function test_le_destinataire_suit_le_sens_du_message(): void
    {
        $message = $this->conversationPrivee('Message prive');

        // Meme conversation, message envoye par l'AUTRE : le destinataire bascule.
        $message->forceFill(['sender_id' => $this->benoit->id])->save();

        $this->commeSurLEcranAdmin()->actingAs($this->superAdmin)
            ->get(route('admin.messages', ['filter' => 'private']))
            ->assertOk()
            ->assertSeeInOrder(['Benoit Lefevre', 'Amandine Rousseau']);
    }

    // ---------------------------------------------------------------------
    // L'onglet « Messages privés » et son complement
    // ---------------------------------------------------------------------

    public function test_l_onglet_messages_prives_ne_montre_que_les_discussions_directes(): void
    {
        $this->conversationPrivee('DISCUSSION PRIVEE');
        $this->echangeDEntraide('ECHANGE ENTRAIDE');
        $this->messageDeBoucle('MESSAGE DE BOUCLE');

        $reponse = $this->commeSurLEcranAdmin()->actingAs($this->superAdmin)
            ->get(route('admin.messages', ['filter' => 'private']))
            ->assertOk();

        $corps = $reponse->viewData('messages')->pluck('body')->all();
        $this->assertSame(['DISCUSSION PRIVEE'], $corps);
    }

    public function test_l_onglet_echanges_est_le_complement_exact_et_exclut_les_discussions(): void
    {
        $this->conversationPrivee('DISCUSSION PRIVEE');
        $this->echangeDEntraide('ECHANGE ENTRAIDE');

        $reponse = $this->commeSurLEcranAdmin()->actingAs($this->superAdmin)
            ->get(route('admin.messages', ['filter' => 'exchanges']))
            ->assertOk();

        $corps = $reponse->viewData('messages')->pluck('body')->all();
        $this->assertSame(['ECHANGE ENTRAIDE'], $corps);
    }

    public function test_l_onglet_tous_nomme_les_trois_natures(): void
    {
        $this->conversationPrivee('DISCUSSION PRIVEE');
        $this->echangeDEntraide('ECHANGE ENTRAIDE');
        $this->messageDeBoucle('MESSAGE DE BOUCLE');

        $reponse = $this->commeSurLEcranAdmin()->actingAs($this->superAdmin)
            ->get(route('admin.messages'))
            ->assertOk();

        // Les trois lignes sont la...
        $corps = $reponse->viewData('messages')->pluck('body')->all();
        foreach (['DISCUSSION PRIVEE', 'ECHANGE ENTRAIDE', 'MESSAGE DE BOUCLE'] as $attendu) {
            $this->assertContains($attendu, $corps);
        }

        // ...et chacune porte SON etiquette : une discussion privee ne doit plus
        // s'annoncer « Échange », ce que son propre onglet contredirait.
        $reponse->assertSee('Message privé');
        $reponse->assertSee('Échange');
        $reponse->assertSee('ChatLoop');

        $natures = $reponse->viewData('messages')->pluck('message_type')->filter()->sort()->values()->all();
        $this->assertSame(['chatloop', 'exchange', 'private'], $natures);
    }

    // ---------------------------------------------------------------------
    // La garantie de TASK-1666 survit au quatrieme onglet
    // ---------------------------------------------------------------------

    public function test_un_transaction_id_de_discussion_privee_ouvre_l_onglet_prive(): void
    {
        $prive = $this->conversationPrivee('DISCUSSION PRIVEE');

        // T1666 : un lien ne doit JAMAIS tomber sur un onglet vide. Forcer
        // `exchanges` en dur, maintenant qu'il exclut les discussions, aurait
        // recree ce faux « il n'y a rien ».
        $reponse = $this->commeSurLEcranAdmin()->actingAs($this->superAdmin)
            ->get(route('admin.messages', ['transaction_id' => $prive->transaction_id]))
            ->assertOk();

        $reponse->assertViewHas('filter', 'private');
        $this->assertSame(['DISCUSSION PRIVEE'], $reponse->viewData('messages')->pluck('body')->all());
    }

    public function test_un_transaction_id_d_echange_ouvre_toujours_l_onglet_echanges(): void
    {
        $echange = $this->echangeDEntraide('ECHANGE ENTRAIDE');

        $reponse = $this->commeSurLEcranAdmin()->actingAs($this->superAdmin)
            ->get(route('admin.messages', ['transaction_id' => $echange->transaction_id]))
            ->assertOk();

        $reponse->assertViewHas('filter', 'exchanges');
        $this->assertSame(['ECHANGE ENTRAIDE'], $reponse->viewData('messages')->pluck('body')->all());
    }

    public function test_un_transaction_id_qui_n_est_pas_un_uuid_ne_leve_pas_d_erreur(): void
    {
        $this->echangeDEntraide('ECHANGE ENTRAIDE');

        // PostgreSQL leve SQLSTATE 22P02 sur un texte qui n'est pas un UUID.
        // Le choix d'onglet lit la transaction : il doit resister a la meme
        // entree que `applyTransactionFilter()` sait deja refuser.
        $reponse = $this->commeSurLEcranAdmin()->actingAs($this->superAdmin)
            ->get(route('admin.messages', ['transaction_id' => 'pas-un-uuid']))
            ->assertOk();

        $reponse->assertViewHas('filter', 'exchanges');
        $this->assertSame([], $reponse->viewData('messages')->pluck('body')->all());
    }

    // ---------------------------------------------------------------------
    // La suppression en masse reste bornee a l'onglet affiche
    // ---------------------------------------------------------------------

    public function test_la_suppression_en_masse_sur_l_onglet_prive_n_emporte_pas_un_echange(): void
    {
        $prive = $this->conversationPrivee('DISCUSSION PRIVEE');
        $echange = $this->echangeDEntraide('ECHANGE ENTRAIDE');

        $this->commeSurLEcranAdmin()->actingAs($this->superAdmin)->delete(route('admin.messages.bulk-destroy'), [
            'ids' => [$prive->id, $echange->id],
            'filter' => 'private',
            'organization_id' => $this->org->id,
        ]);

        $this->assertDatabaseMissing('messages', ['id' => $prive->id]);
        $this->assertDatabaseHas('messages', ['id' => $echange->id]);
    }

    // ---------------------------------------------------------------------
    // Correctif 7 — « Se connecter sous » depuis l'ecran des messages
    // ---------------------------------------------------------------------

    public function test_le_lien_se_connecter_sous_est_pose_sur_l_expediteur_et_le_destinataire(): void
    {
        $this->conversationPrivee('Message prive');

        $reponse = $this->commeSurLEcranAdmin()->actingAs($this->superAdmin)
            ->get(route('admin.messages', ['filter' => 'private']))
            ->assertOk();

        // Les DEUX parties, pas seulement l'expediteur : c'est la demande.
        $reponse->assertSee('data-login-as="'.$this->amandine->id.'"', false);
        $reponse->assertSee('data-login-as="'.$this->benoit->id.'"', false);

        // Et le formulaire vise bien la route d'impersonation, en POST — on
        // demande l'URL au ROUTEUR, on ne la reecrit pas.
        $reponse->assertSee(route('admin.users.login-as', $this->amandine), false);
        $reponse->assertSee(route('admin.users.login-as', $this->benoit), false);
    }

    /**
     * Aucun geste offert ne doit etre refuse (regle du bug K, TASK-1693).
     *
     * `AdminController::loginAsUser()` refuse un compte BANNI. `/admin/users`
     * ne verifie que cela et propose quand meme le bouton sur un
     * administrateur — on ne reproduit pas ce decalage ici.
     *
     * Ce test garde AUSSI la selection de colonnes : si `banned_at` quittait le
     * `select` du chargement, la garde lirait `null` et le lien reapparaitrait.
     * C'est le defaut du correctif 4, transpose.
     */
    public function test_aucun_lien_sur_un_compte_banni_et_la_raison_est_dite(): void
    {
        $this->conversationPrivee('Message prive');
        $this->benoit->forceFill(['banned_at' => now()])->save();

        $reponse = $this->commeSurLEcranAdmin()->actingAs($this->superAdmin)
            ->get(route('admin.messages', ['filter' => 'private']))
            ->assertOk();

        $reponse->assertDontSee('data-login-as="'.$this->benoit->id.'"', false);
        $reponse->assertSee('data-login-as-unavailable="'.$this->benoit->id.'"', false);
        $reponse->assertSee(__('admin.login_as_banned'));

        // L'expediteur, lui, reste joignable : la garde est par personne.
        $reponse->assertSee('data-login-as="'.$this->amandine->id.'"', false);
    }

    /** Meme regle pour un AUTRE administrateur, que le serveur refuse aussi. */
    public function test_aucun_lien_sur_un_autre_administrateur(): void
    {
        $this->conversationPrivee('Message prive');
        $this->benoit->forceFill(['is_admin' => true])->save();

        $reponse = $this->commeSurLEcranAdmin()->actingAs($this->superAdmin)
            ->get(route('admin.messages', ['filter' => 'private']))
            ->assertOk();

        $reponse->assertDontSee('data-login-as="'.$this->benoit->id.'"', false);
        $reponse->assertSee(__('admin.login_as_admin'));
    }

    /**
     * Le lien pose TIENT : le serveur honore ce que l'ecran a propose.
     *
     * Un test qui verifierait seulement la presence du formulaire laisserait
     * passer une route qui refuse ensuite. On POSTe donc vraiment.
     */
    public function test_le_lien_pose_aboutit_reellement_a_l_impersonation(): void
    {
        $this->conversationPrivee('Message prive');

        $this->commeSurLEcranAdmin()->actingAs($this->superAdmin)
            ->post(route('admin.users.login-as', $this->benoit))
            ->assertRedirect('/');

        $this->assertAuthenticatedAs($this->benoit->fresh());
    }

    /** Un message de ChatLoop n'a pas de destinataire : un seul lien, l'expediteur. */
    public function test_un_message_de_boucle_n_offre_le_lien_que_sur_l_expediteur(): void
    {
        $this->messageDeBoucle('Message de boucle');

        $reponse = $this->commeSurLEcranAdmin()->actingAs($this->superAdmin)
            ->get(route('admin.messages', ['filter' => 'chatloop']))
            ->assertOk();

        $reponse->assertSee('data-login-as="'.$this->amandine->id.'"', false);
        $reponse->assertSee('La Boucle');
        $reponse->assertDontSee('data-login-as="'.$this->benoit->id.'"', false);
    }
}
