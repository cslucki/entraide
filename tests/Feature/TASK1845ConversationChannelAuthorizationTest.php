<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Tests\TestCase;

/**
 * TASK-1845 — autorisation du canal prive `conversation.{transactionId}`
 * (routes/channels.php).
 *
 * Invoque la fermeture ENREGISTREE directement (`Broadcast::getChannels()`),
 * exactement comme `TASK1843UserChannelAuthorizationTest` : le driver par
 * defaut des tests (`BROADCAST_CONNECTION=null`, phpunit.xml) n'exerce jamais
 * le callback via `/broadcasting/auth`, et changer le driver par defaut APRES
 * coup resoudrait un `PusherBroadcaster` flambant neuf aux canaux vides.
 * Cette approche exerce la MEME fermeture que `routes/channels.php` charge
 * reellement au demarrage.
 */
class TASK1845ConversationChannelAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $buyer;

    private User $seller;

    private User $tiers;

    private User $autreOrganization;

    private Transaction $transaction;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create();
        $this->orgB = Organization::factory()->create();

        $this->buyer = User::factory()->create(['organization_id' => $this->orgA->id]);
        $this->seller = User::factory()->create(['organization_id' => $this->orgA->id]);
        $this->tiers = User::factory()->create(['organization_id' => $this->orgA->id]);
        $this->autreOrganization = User::factory()->create(['organization_id' => $this->orgB->id]);

        $this->transaction = Transaction::factory()->create([
            'organization_id' => $this->orgA->id,
            'buyer_id' => $this->buyer->id,
            'seller_id' => $this->seller->id,
            'status' => 'accepted',
            'points_proposed' => 50,
        ]);
    }

    private function autoriser(?User $user, string $transactionId): mixed
    {
        $fermeture = Broadcast::getChannels()->get('conversation.{transactionId}');
        $this->assertNotNull($fermeture, 'le canal conversation.{transactionId} doit etre enregistre dans routes/channels.php');

        return $fermeture($user, $transactionId);
    }

    // ── Les deux parties legitimes peuvent s'abonner ────────────────────────

    public function test_l_acheteur_peut_s_abonner_a_sa_conversation(): void
    {
        $this->assertNotFalse($this->autoriser($this->buyer, $this->transaction->id));
    }

    public function test_le_vendeur_peut_s_abonner_a_sa_conversation(): void
    {
        $this->assertNotFalse($this->autoriser($this->seller, $this->transaction->id));
    }

    public function test_la_reponse_d_autorisation_ne_porte_aucun_contenu_de_conversation(): void
    {
        $reponse = $this->autoriser($this->buyer, $this->transaction->id);

        $this->assertIsArray($reponse);
        $this->assertSame(['id'], array_keys($reponse));
        $this->assertSame($this->buyer->id, $reponse['id']);
    }

    // ── Refus : tiers, autre Organization, non authentifie ──────────────────

    public function test_un_tiers_de_la_meme_organization_ne_peut_pas_s_abonner(): void
    {
        $this->assertFalse($this->autoriser($this->tiers, $this->transaction->id));
    }

    public function test_un_membre_d_une_autre_organization_ne_peut_pas_s_abonner(): void
    {
        $this->assertFalse($this->autoriser($this->autreOrganization, $this->transaction->id));
    }

    public function test_une_requete_non_authentifiee_est_refusee(): void
    {
        // Pour un canal PRIVE, `PusherBroadcaster::auth()` refuse deja une
        // requete non authentifiee AVANT d'appeler cette fermeture — mais la
        // fermeture elle-meme ne doit JAMAIS presumer d'un appelant garanti
        // non nul (meme garde que `user.{userId}`, TASK-1843).
        $this->assertFalse($this->autoriser(null, $this->transaction->id));
    }

    public function test_une_transaction_inexistante_ou_supprimee_est_refusee(): void
    {
        // Aucune suppression douce n'existe sur `transactions` aujourd'hui :
        // cette garde couvre un identifiant invalide ET, de facon
        // defensive, une eventuelle suppression future — meme motif que
        // `loop.{loopId}` refuse une Boucle introuvable.
        $this->assertFalse($this->autoriser($this->buyer, (string) \Illuminate\Support\Str::uuid()));
    }

    public function test_un_compte_sans_organization_ne_peut_plus_s_abonner(): void
    {
        $orphelin = User::factory()->create(['organization_id' => null]);
        $transactionOrpheline = Transaction::factory()->create([
            'organization_id' => $this->orgA->id,
            'buyer_id' => $orphelin->id,
            'seller_id' => $this->seller->id,
            'status' => 'accepted',
            'points_proposed' => 50,
        ]);

        $this->assertFalse($this->autoriser($orphelin, $transactionOrpheline->id));
    }

    // ── L'Organization verifiee est celle de la TRANSACTION, pas currentOrganization() ──

    public function test_l_autorisation_ne_depend_pas_de_currentorganization(): void
    {
        // Aucun `current_organization` n'est pose dans ce test — exactement
        // le contexte reel d'une requete `/broadcasting/auth`, qui ne passe
        // jamais par `ResolveUrlOrganization`. Si la fermeture s'appuyait a
        // tort sur `currentOrganization()`, cette assertion echouerait.
        $this->assertNotFalse($this->autoriser($this->buyer, $this->transaction->id));
    }

    public function test_le_decalage_entre_l_organization_de_l_utilisateur_et_celle_de_la_transaction_est_refuse(): void
    {
        // Un membre dont l'Organization courante differe de celle DE LA
        // TRANSACTION doit etre refuse, meme s'il figure encore comme
        // `buyer_id`/`seller_id` — meme principe que `NewMessageReceived::
        // urlDeLaConversation()` (TASK-1795) : la source de verite est la
        // ligne `transactions`, jamais le profil courant de la personne.
        //
        // `User::booted()` rend `organization_id` IMMUABLE une fois pose
        // (voir TASK1843UserChannelAuthorizationTest) : le passer par le
        // QUERY BUILDER, pas Eloquent, est le meme contournement deja tenu
        // par `AdminOrganizationController::destroy` pour detacher un
        // membre sans declencher cette garde.
        User::where('id', $this->buyer->id)->update(['organization_id' => $this->orgB->id]);

        $this->assertFalse($this->autoriser($this->buyer->fresh(), $this->transaction->id));
    }
}
