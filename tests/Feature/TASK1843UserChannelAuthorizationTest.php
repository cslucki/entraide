<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Tests\TestCase;

/**
 * TASK-1843 — autorisation du canal prive `user.{userId}` (routes/channels.php).
 *
 * Invoque la fermeture ENREGISTREE directement (`Broadcast::getChannels()`),
 * plutot que de poster sur `/broadcasting/auth` : le driver par defaut des
 * tests (`BROADCAST_CONNECTION=null`, phpunit.xml) n'exerce jamais le
 * callback (`NullBroadcaster::auth()` est un no-op, comme
 * `LogBroadcaster::auth()`), et changer le driver par defaut APRES coup ne
 * fonctionne pas non plus : `Broadcast::channel()` enregistre la fermeture
 * sur le DRIVER actif AU MOMENT du boot (celui lu dans `BROADCAST_CONNECTION`
 * avant tout code de test), jamais sur un driver choisi plus tard — un
 * `config()->set('broadcasting.default', 'pusher')` en `setUp()` resoudrait
 * alors un `PusherBroadcaster` flambant neuf, aux canaux vides, et ferait
 * echouer CHAQUE abonnement avec un simple 403 qui n'aurait rien a voir avec
 * la fermeture testee (mesure en ecrivant ce test). Cette approche exerce la
 * MEME fermeture que `routes/channels.php` charge reellement au demarrage,
 * sans dependre d'un driver de diffusion particulier.
 */
class TASK1843UserChannelAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $alice;

    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create();
        $this->orgB = Organization::factory()->create();

        $this->alice = User::factory()->create(['organization_id' => $this->orgA->id]);
        $this->bob = User::factory()->create(['organization_id' => $this->orgB->id]);
    }

    private function autoriser(?User $user, string $userId): mixed
    {
        $fermeture = Broadcast::getChannels()->get('user.{userId}');
        $this->assertNotNull($fermeture, 'le canal user.{userId} doit etre enregistre dans routes/channels.php');

        return $fermeture($user, $userId);
    }

    public function test_alice_peut_s_abonner_a_son_propre_canal(): void
    {
        $this->assertNotFalse($this->autoriser($this->alice, (string) $this->alice->id));
    }

    public function test_alice_ne_peut_pas_s_abonner_au_canal_de_bob(): void
    {
        // TASK-1843 — identite ET Organization sont verifiees, mais c'est
        // L'IDENTITE seule qui tranche ici : un UUID different ne fait
        // JAMAIS passer la garde, Organization differente ou non.
        $this->assertFalse($this->autoriser($this->alice, (string) $this->bob->id));
    }

    public function test_deux_organizations_aucune_fuite_bob_ne_peut_pas_lire_le_canal_d_alice(): void
    {
        $this->assertFalse($this->autoriser($this->bob, (string) $this->alice->id));
    }

    public function test_une_requete_non_authentifiee_est_refusee(): void
    {
        // Pour un canal PRIVE, `PusherBroadcaster::auth()` refuse deja une
        // requete non authentifiee AVANT d'appeler cette fermeture — mais la
        // fermeture elle-meme ne doit JAMAIS presumer d'un appelant garanti
        // non nul (voir son commentaire dans routes/channels.php).
        $this->assertFalse($this->autoriser(null, (string) $this->alice->id));
    }

    public function test_un_compte_sans_organization_ne_peut_plus_s_abonner(): void
    {
        // TASK-1843 — defense en profondeur (section 3.3/3.4 de l'audit
        // TASK-1842) : un compte dont l'Organization a disparu (cycle de vie
        // RGPD, `organization_id` NULL) ne doit plus recevoir AUCUN signal
        // temps reel, meme en tant que proprietaire du canal. `organization_id`
        // est IMMUABLE une fois pose (`User::booted()`) : un compte orphelin
        // ne peut donc qu'etre CREE ainsi, jamais obtenu par mutation.
        $orphelin = User::factory()->create(['organization_id' => null]);

        $this->assertFalse($this->autoriser($orphelin, (string) $orphelin->id));
    }
}
