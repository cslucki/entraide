<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Push\PushSubscriptionStore;
use App\Services\UserDataLifecycleRegistry;
use App\Support\ScenarioManager\ScenarioSandboxPreflight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1739 — le magasin d'abonnements Web Push.
 *
 * L'invariant central, et celui qui justifie cette suite :
 * **un appareil n'appartient qu'a UN compte a un instant donne** (CDC 5.2).
 * Sans lui, une personne qui bascule entre ses comptes freres recevrait, au
 * chantier d'envoi, une notification du compte quitte sur son ecran
 * verrouille — une fuite inter-tenant visible sans deverrouiller l'appareil.
 */
class TASK1739PushSubscriptionStoreTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $alice;

    /** Le compte FRERE d'Alice, dans une autre Organization. */
    private User $aliceChezB;

    private User $bob;

    private const ENDPOINT = 'https://fcm.googleapis.com/fcm/send/cEgRtYu-Xw4:APA91bHq_exemple_de_jeton_long';

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create(['is_active' => true]);
        $this->orgB = Organization::factory()->create(['is_active' => true]);

        $this->alice = User::factory()->create(['organization_id' => $this->orgA->id]);
        $this->aliceChezB = User::factory()->create(['organization_id' => $this->orgB->id]);
        $this->bob = User::factory()->create(['organization_id' => $this->orgA->id]);
    }

    private function magasin(): PushSubscriptionStore
    {
        return app(PushSubscriptionStore::class);
    }

    // ── L'invariant central ──────────────────────────────────────────────────

    public function test_un_appareil_n_appartient_qu_a_un_seul_compte(): void
    {
        $this->magasin()->revendiquer($this->alice, self::ENDPOINT, 'cle-p256dh', 'cle-auth');

        // Le MEME appareil se reabonne, mais sous le compte frere — ce qui
        // arrive des qu'on bascule de compte puis qu'on reactive.
        $this->magasin()->revendiquer($this->aliceChezB, self::ENDPOINT, 'cle-p256dh', 'cle-auth');

        // UNE seule ligne, et elle appartient au DERNIER qui a revendique.
        $this->assertSame(1, PushSubscription::count());
        $this->assertSame($this->aliceChezB->id, PushSubscription::sole()->user_id);

        // Et l'ancien proprietaire ne voit plus cet appareil comme le sien.
        $this->assertFalse($this->magasin()->estAbonne($this->alice, self::ENDPOINT));
        $this->assertTrue($this->magasin()->estAbonne($this->aliceChezB, self::ENDPOINT));
    }

    public function test_la_contrainte_d_unicite_vit_EN_BASE_pas_seulement_dans_le_service(): void
    {
        // Le service pourrait etre contourne — par une commande, un seeder, un
        // futur appelant distrait. L'invariant doit donc etre tenu par la
        // BASE, et c'est cela qu'on mesure : une seconde insertion directe du
        // meme endpoint doit etre REFUSEE par le moteur.
        $this->magasin()->revendiquer($this->alice, self::ENDPOINT, 'p', 'a');

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        PushSubscription::query()->insert([
            'id' => (new PushSubscription)->newUniqueId(),
            'user_id' => $this->bob->id,
            'endpoint' => self::ENDPOINT,
            'endpoint_hash' => PushSubscription::empreinteDe(self::ENDPOINT),
            'p256dh' => 'p', 'auth' => 'a',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_une_personne_peut_abonner_PLUSIEURS_appareils(): void
    {
        // L'autre sens de la relation : l'unicite porte sur l'appareil, pas
        // sur la personne (CDC 5.1 — « un ou plusieurs appareils »).
        $this->magasin()->revendiquer($this->alice, self::ENDPOINT.'/telephone', 'p', 'a', 'Telephone');
        $this->magasin()->revendiquer($this->alice, self::ENDPOINT.'/tablette', 'p', 'a', 'Tablette');
        $this->magasin()->revendiquer($this->alice, self::ENDPOINT.'/portable', 'p', 'a', 'Portable');

        $this->assertCount(3, $this->magasin()->appareilsDe($this->alice));
    }

    public function test_revendiquer_deux_fois_le_meme_appareil_est_sans_effet_de_bord(): void
    {
        // C'est EXACTEMENT ce que fait la reconciliation a chaque reouverture
        // de l'application, puisque `pushsubscriptionchange` n'est pas pris en
        // charge partout (CDC 5.4). Elle doit donc etre idempotente.
        $premier = $this->magasin()->revendiquer($this->alice, self::ENDPOINT, 'p', 'a');
        $second = $this->magasin()->revendiquer($this->alice, self::ENDPOINT, 'p', 'a');

        $this->assertSame(1, PushSubscription::count());
        $this->assertSame($premier->id, $second->id);
    }

    public function test_une_reconciliation_rafraichit_les_clefs_et_la_derniere_vue(): void
    {
        $this->travelTo(now()->subHour());
        $this->magasin()->revendiquer($this->alice, self::ENDPOINT, 'ancienne-p256dh', 'ancien-auth');
        $vuAvant = PushSubscription::sole()->last_seen_at;

        $this->travelBack();
        $this->magasin()->revendiquer($this->alice, self::ENDPOINT, 'nouvelle-p256dh', 'nouvel-auth');

        $abonnement = PushSubscription::sole();
        $this->assertSame('nouvelle-p256dh', $abonnement->p256dh);
        $this->assertSame('nouvel-auth', $abonnement->auth);
        $this->assertTrue($abonnement->last_seen_at->greaterThan($vuAvant));
    }

    // ── Le renoncement ──────────────────────────────────────────────────────

    public function test_renoncer_retire_l_abonnement_de_cet_appareil(): void
    {
        $this->magasin()->revendiquer($this->alice, self::ENDPOINT, 'p', 'a');

        $this->assertSame(1, $this->magasin()->renoncer($this->alice, self::ENDPOINT));
        $this->assertSame(0, PushSubscription::count());
    }

    public function test_personne_ne_peut_desabonner_l_appareil_d_un_autre(): void
    {
        // Un endpoint n'est PAS un secret : il voyage dans les requetes du
        // navigateur. Le filtre sur le proprietaire est donc la seule chose
        // qui empeche de couper les notifications de quelqu'un d'autre.
        $this->magasin()->revendiquer($this->alice, self::ENDPOINT, 'p', 'a');

        $this->assertSame(0, $this->magasin()->renoncer($this->bob, self::ENDPOINT));
        $this->assertSame(1, PushSubscription::count());
        $this->assertSame($this->alice->id, PushSubscription::sole()->user_id);
    }

    public function test_renoncer_a_un_appareil_inconnu_est_un_cas_ordinaire(): void
    {
        // Le navigateur peut avoir deja oublie son abonnement de son cote :
        // ce n'est pas une erreur, c'est zero ligne retiree.
        $this->assertSame(0, $this->magasin()->renoncer($this->alice, self::ENDPOINT));
    }

    public function test_les_appareils_ne_fuient_jamais_d_une_personne_a_l_autre(): void
    {
        $this->magasin()->revendiquer($this->alice, self::ENDPOINT.'/alice', 'p', 'a');
        $this->magasin()->revendiquer($this->bob, self::ENDPOINT.'/bob', 'p', 'a');

        $this->assertCount(1, $this->magasin()->appareilsDe($this->alice));
        $this->assertCount(1, $this->magasin()->appareilsDe($this->bob));
        $this->assertFalse($this->magasin()->estAbonne($this->alice, self::ENDPOINT.'/bob'));
    }

    // ── L'empreinte, qui PORTE l'unicite ────────────────────────────────────

    public function test_l_empreinte_est_derivee_de_l_endpoint_et_non_fournie(): void
    {
        // Si un appelant pouvait l'ecrire, il pourrait la desynchroniser de
        // l'endpoint — et deux lignes coexisteraient pour le meme appareil.
        $abonnement = new PushSubscription;
        $abonnement->user_id = $this->alice->id;
        $abonnement->fill([
            'endpoint' => self::ENDPOINT,
            'p256dh' => 'p', 'auth' => 'a',
            // Tentative d'injection d'une empreinte mensongere :
            'endpoint_hash' => str_repeat('0', 64),
        ]);
        $abonnement->save();

        $this->assertSame(PushSubscription::empreinteDe(self::ENDPOINT), $abonnement->fresh()->endpoint_hash);
    }

    public function test_l_empreinte_suit_un_changement_d_endpoint(): void
    {
        $abonnement = $this->magasin()->revendiquer($this->alice, self::ENDPOINT, 'p', 'a');

        $abonnement->update(['endpoint' => self::ENDPOINT.'/renouvele']);

        $this->assertSame(
            PushSubscription::empreinteDe(self::ENDPOINT.'/renouvele'),
            $abonnement->fresh()->endpoint_hash,
        );
    }

    public function test_un_endpoint_vide_est_refuse(): void
    {
        $this->expectException(\RuntimeException::class);

        $abonnement = new PushSubscription;
        $abonnement->user_id = $this->alice->id;
        $abonnement->fill(['endpoint' => '   ', 'p256dh' => 'p', 'auth' => 'a']);
        $abonnement->save();
    }

    public function test_le_proprietaire_ne_vient_jamais_de_la_requete(): void
    {
        // `user_id` hors `$fillable` : un `create($request->validated())` un
        // peu rapide laisserait abonner l'appareil de quelqu'un d'autre.
        $abonnement = new PushSubscription;
        $abonnement->fill(['user_id' => $this->bob->id, 'endpoint' => self::ENDPOINT, 'p256dh' => 'p', 'auth' => 'a']);

        $this->assertNull($abonnement->user_id);
    }

    // ── Suppression du compte ───────────────────────────────────────────────

    public function test_les_abonnements_disparaissent_avec_le_compte(): void
    {
        $this->magasin()->revendiquer($this->bob, self::ENDPOINT.'/un', 'p', 'a');
        $this->magasin()->revendiquer($this->bob, self::ENDPOINT.'/deux', 'p', 'a');
        $this->magasin()->revendiquer($this->alice, self::ENDPOINT.'/alice', 'p', 'a');

        $this->bob->delete();

        // La FK est `cascadeOnDelete`, et le registre le DIT (POLICY_DELETE) :
        // les deux doivent concorder, sinon la CI rougit.
        $this->assertSame(1, PushSubscription::count());
        $this->assertSame($this->alice->id, PushSubscription::sole()->user_id);
    }

    public function test_la_table_est_declaree_aux_deux_registres(): void
    {
        // Les deux declarations obligatoires du depot, verifiees ici en plus
        // des suites generiques : une TASK a deja ete rejetee par la CI pour
        // deux FK non declarees.
        $paires = app(UserDataLifecycleRegistry::class)->sqlRegistryPairs();
        $this->assertContains('push_subscriptions.user_id', $paires);

        $this->assertContains('push_subscriptions', ScenarioSandboxPreflight::TABLES_HORS_PORTEE);
    }

    // ── Minimisation (CDC 5.4) ──────────────────────────────────────────────

    public function test_la_table_ne_porte_aucune_donnee_d_identification(): void
    {
        // Pas d'IP, pas d'UA complet, pas de localisation, pas de modele.
        // Mesure sur le SCHEMA, pas sur une intention : une colonne ajoutee
        // plus tard sans y penser fera rougir ce test.
        $colonnes = \Illuminate\Support\Facades\Schema::getColumnListing('push_subscriptions');

        $this->assertSame([
            'id', 'user_id', 'endpoint', 'endpoint_hash', 'p256dh', 'auth',
            'device_label', 'last_seen_at', 'created_at', 'updated_at',
        ], $colonnes);
    }

    public function test_la_reconciliation_n_efface_pas_le_libelle_d_appareil(): void
    {
        // Defaut trouve par Cyril en recette sur son Android : le libelle est
        // pose au CLIC d'activation, mais la reconciliation — qui rejoue cette
        // methode a chaque affichage de l'ecran et a chaque retour sur
        // l'application — n'en envoie aucun. L'appareil redevenait anonyme en
        // quelques secondes.
        //
        // Un `null` entrant veut dire « je n'en sais rien », pas « efface ».
        $this->magasin()->revendiquer($this->alice, self::ENDPOINT, 'p', 'a', 'Android');
        $this->assertSame('Android', PushSubscription::sole()->device_label);

        // La reconciliation, telle que le script l'envoie : sans libelle.
        $this->magasin()->revendiquer($this->alice, self::ENDPOINT, 'p', 'a');

        $this->assertSame('Android', PushSubscription::sole()->device_label);
    }

    public function test_un_libelle_fourni_remplace_bien_le_precedent(): void
    {
        // L'autre moitie de la regle : quand un libelle ARRIVE, il gagne.
        $this->magasin()->revendiquer($this->alice, self::ENDPOINT, 'p', 'a', 'Android');
        $this->magasin()->revendiquer($this->alice, self::ENDPOINT, 'p', 'a', 'Windows');

        $this->assertSame('Windows', PushSubscription::sole()->device_label);
    }
}
