<?php

namespace Tests\Feature;

use App\Livewire\MessageThread;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1704 correctif 2 — modifier un message texte dans la Messagerie.
 *
 * Cyril : « il faudrait aussi pouvoir modifier les messages textes, on peut le
 * faire sur la chatloop. »
 *
 * ## Repris, pas reinvente
 *
 * Le ChatLoop sait deja le faire. Le mecanisme est donc repris a l'identique :
 * memes noms de methodes (la bulle partagee appelle deja `editMessage(...)`),
 * meme regle d'editabilite, meme recalcul de l'apercu de lien. Ecrire un
 * second mecanisme aurait donne deux comportements a maintenir pour un seul
 * geste, et deux reponses a « puis-je corriger ma faute de frappe ? ».
 *
 * ## Ce qui est mesure
 *
 * Surtout ce qui est REFUSE. Ouvrir un formulaire de modification est anodin ;
 * ce qui ne l'est pas, c'est qui peut reecrire la parole de qui. Les gardes
 * sont donc exercees a l'OUVERTURE **et** a l'ENREGISTREMENT, separement :
 * une garde posee a la seule ouverture laisserait enregistrer ce qu'on n'a
 * plus le droit de modifier.
 */
class TASK1704EditMessageTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $acheteur;

    private User $vendeur;

    private Transaction $echange;

    protected function setUp(): void
    {
        parent::setUp();

        // PAS de `Http::fake()` ici : Laravel FUSIONNE les doublures et la
        // premiere inscrite l'emporte — un `Http::fake([...])` pose dans un
        // test serait donc ignore au profit de ce fourre-tout. On interdit
        // plutot toute requete non doublee, ce qui prouve au passage qu'aucun
        // test de ce fichier n'atteint le reseau par inadvertance.
        Http::preventStrayRequests();

        $this->organization = Organization::factory()->create(['slug' => 'org-1704-c2', 'is_active' => true]);
        $this->acheteur = User::factory()->create(['organization_id' => $this->organization->id, 'points_balance' => 200]);
        $this->vendeur = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->echange = Transaction::factory()->create([
            'organization_id' => $this->organization->id,
            'buyer_id' => $this->acheteur->id,
            'seller_id' => $this->vendeur->id,
            'status' => 'accepted',
            'points_proposed' => 50,
        ]);

        app()->instance('current_organization', $this->organization);
    }

    private function message(?User $auteur = null, string $corps = 'Bonjou', string $type = 'user'): Message
    {
        return Message::create([
            'transaction_id' => $this->echange->id,
            'sender_id' => $auteur?->id,
            'body' => $corps,
            'type' => $type,
            'organization_id' => $this->organization->id,
        ]);
    }

    private function composant(?User $qui = null)
    {
        return Livewire::actingAs($qui ?? $this->acheteur)
            ->test(MessageThread::class, ['transaction' => $this->echange]);
    }

    // ── 1. Le geste demande ─────────────────────────────────────────────────

    public function test_l_auteur_peut_modifier_son_message(): void
    {
        $message = $this->message($this->acheteur, 'Bonjou');

        $this->composant()
            ->call('editMessage', $message->id)
            ->assertSet('editingMessageId', $message->id)
            ->assertSet('editingBody', 'Bonjou')
            ->set('editingBody', 'Bonjour')
            ->call('saveEdit')
            ->assertHasNoErrors()
            ->assertSet('editingMessageId', null);

        $this->assertSame('Bonjour', $message->fresh()->body);
    }

    /**
     * La modification se VOIT.
     *
     * Sans `edited_at`, la bulle n'affiche aucune mention : un message
     * reecrit se presenterait comme l'original. La bulle partagee sait deja
     * l'afficher (`:is-edited`), encore faut-il que la colonne soit remplie.
     */
    public function test_la_modification_est_tracee(): void
    {
        $message = $this->message($this->acheteur);

        $this->assertNull($message->edited_at);

        $this->composant()
            ->call('editMessage', $message->id)
            ->set('editingBody', 'Corrige')
            ->call('saveEdit');

        $message = $message->fresh();

        $this->assertNotNull($message->edited_at, 'La modification n\'est pas tracee : elle passerait pour l\'original.');
        $this->assertSame((string) $this->acheteur->id, (string) $message->edited_by);
    }

    /** Annuler ne modifie rien. */
    public function test_annuler_ne_modifie_rien(): void
    {
        $message = $this->message($this->acheteur, 'Intact');

        $this->composant()
            ->call('editMessage', $message->id)
            ->set('editingBody', 'Jamais enregistre')
            ->call('cancelEdit')
            ->assertSet('editingMessageId', null)
            ->assertSet('editingBody', '');

        $this->assertSame('Intact', $message->fresh()->body);
        $this->assertNull($message->fresh()->edited_at);
    }

    // ── 2. Ce qui est REFUSE ────────────────────────────────────────────────

    /** On ne reecrit pas la parole d'autrui. */
    public function test_on_ne_peut_pas_modifier_le_message_d_un_autre(): void
    {
        $message = $this->message($this->vendeur, 'Parole du vendeur');

        $this->composant($this->acheteur)
            ->call('editMessage', $message->id)
            ->assertSet('editingMessageId', null);

        $this->assertSame('Parole du vendeur', $message->fresh()->body);
    }

    /**
     * Et pas davantage en forcant l'enregistrement.
     *
     * C'est le volet qui compte : `editingMessageId` est une propriete
     * PUBLIQUE de composant Livewire, donc modifiable depuis le navigateur.
     * Une garde posee a la seule ouverture ne protegerait rien.
     */
    public function test_forcer_l_identifiant_ne_permet_pas_d_enregistrer(): void
    {
        $message = $this->message($this->vendeur, 'Parole du vendeur');

        $this->composant($this->acheteur)
            ->set('editingMessageId', $message->id)
            ->set('editingBody', 'Detourne')
            ->call('saveEdit');

        $this->assertSame('Parole du vendeur', $message->fresh()->body, 'Le message d\'autrui a ete reecrit.');
        $this->assertNull($message->fresh()->edited_at);
    }

    /**
     * Un message SYSTEME n'est la parole de personne.
     *
     * Il relate un fait — un echange accepte, des points verses. Le reecrire
     * falsifierait l'historique de l'echange.
     */
    public function test_un_message_systeme_n_est_pas_modifiable(): void
    {
        $message = $this->message(null, 'Echange accepte', 'system');

        $this->composant()
            ->set('editingMessageId', $message->id)
            ->set('editingBody', 'Echange refuse')
            ->call('saveEdit');

        $this->assertSame('Echange accepte', $message->fresh()->body);
    }

    /** Un message d'un AUTRE echange ne se modifie pas depuis celui-ci. */
    public function test_un_message_d_un_autre_echange_est_refuse(): void
    {
        $autre = Transaction::factory()->create([
            'organization_id' => $this->organization->id,
            'buyer_id' => $this->acheteur->id,
            'seller_id' => User::factory()->create(['organization_id' => $this->organization->id])->id,
            'status' => 'accepted',
            'points_proposed' => 10,
        ]);

        $ailleurs = Message::create([
            'transaction_id' => $autre->id,
            'sender_id' => $this->acheteur->id,
            'body' => 'Dans un autre echange',
            'type' => 'user',
            'organization_id' => $this->organization->id,
        ]);

        // L'acheteur EST l'auteur, et il est partie aux deux echanges : seule
        // la correspondance message <-> echange peut refuser.
        $this->composant()
            ->set('editingMessageId', $ailleurs->id)
            ->set('editingBody', 'Detourne')
            ->call('saveEdit');

        $this->assertSame('Dans un autre echange', $ailleurs->fresh()->body);
    }

    /**
     * Une conversation close ne se reecrit plus.
     *
     * C'est la meme borne que `sendMessage()`. L'omettre laisserait reecrire
     * l'historique d'un echange termine, alors qu'on ne peut plus y repondre.
     */
    public function test_une_conversation_close_n_est_plus_modifiable(): void
    {
        $message = $this->message($this->acheteur, 'Avant cloture');
        $this->echange->update(['status' => 'completed']);

        $this->composant()
            ->set('editingMessageId', $message->id)
            ->set('editingBody', 'Apres cloture')
            ->call('saveEdit');

        $this->assertSame('Avant cloture', $message->fresh()->body);
    }

    /** Un identifiant qui n'est pas un UUID ne doit pas atteindre la base. */
    public function test_un_identifiant_non_uuid_ne_casse_rien(): void
    {
        $this->composant()
            ->call('editMessage', 'pas-un-uuid')
            ->assertSet('editingMessageId', null);
    }

    /** Un corps vide est refuse. */
    public function test_un_corps_vide_est_refuse(): void
    {
        $message = $this->message($this->acheteur, 'Non vide');

        $this->composant()
            ->call('editMessage', $message->id)
            ->set('editingBody', '   ')
            ->call('saveEdit')
            ->assertHasErrors('editingBody');

        $this->assertSame('Non vide', $message->fresh()->body);
    }

    // ── 3. L'apercu de lien suit la correction ──────────────────────────────

    /**
     * Corriger l'URL change la vignette.
     *
     * Sans recalcul, un membre qui corrige son lien garderait la carte de
     * l'ancien — un apercu qui ne correspond plus a ce qui est ecrit.
     */
    public function test_l_apercu_de_lien_est_recalcule(): void
    {
        Http::fake(['*' => Http::response(
            '<html><head><meta property="og:title" content="Le bon article">'
            .'<meta property="og:image" content="https://exemple.test/v.jpg"></head></html>', 200
        )]);

        $message = $this->message($this->acheteur, 'Sans lien');

        $this->composant()
            ->call('editMessage', $message->id)
            ->set('editingBody', 'Voir https://exemple.test/article')
            ->call('saveEdit')
            ->assertHasNoErrors();

        $meta = $message->fresh()->metadata;

        $this->assertSame('Le bon article', $meta['url_preview']['title'] ?? null);
    }

    // ── 4. Le bouton n'apparait que sur ses propres messages ────────────────

    public function test_le_bouton_n_apparait_que_sur_ses_propres_messages(): void
    {
        $sien = $this->message($this->acheteur, 'Mon message');
        $autre = $this->message($this->vendeur, 'Son message');

        app()->forgetInstance('current_organization');

        $html = $this->actingAs($this->acheteur)
            ->get('/org/'.$this->organization->slug.'/messages/'.$this->echange->id)
            ->assertOk()
            ->getContent();

        // Les apostrophes de l'attribut sont LITTERALES : seul le contenu de
        // `{{ }}` est echappe, pas les quotes qui l'entourent. Mesure faite
        // sur le HTML reellement rendu, pas supposee.
        $this->assertSame(
            1,
            preg_match("/editMessage\('".preg_quote($sien->id, '/')."'\)/", $html),
            'Le bouton de modification manque sur son propre message.'
        );
        $this->assertSame(
            0,
            preg_match("/editMessage\('".preg_quote($autre->id, '/')."'\)/", $html),
            'Le bouton de modification est propose sur le message d\'autrui.'
        );
    }
}
