<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\MemberNotification;
use App\Models\MemberNotificationDelivery;
use App\Models\MemberNotificationPreference;
use App\Models\Organization;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Push\PushSubscriptionStore;
use App\Support\Notifications\NotificationCatalogue;
use App\Support\Notifications\NotificationDeliveryStatus;
use App\Support\Notifications\NotificationWebPushDeliverer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-1822 — sécuriser la réconciliation VAPID, et lire les erreurs que le
 * fournisseur place sous un HTTP 2xx.
 *
 * ## Le défaut que ce fichier ferme
 *
 * Un abonnement porte À VIE la clé VAPID avec laquelle le navigateur l'a
 * créé. Si celle du serveur change, le fournisseur accepte toujours l'envoi
 * et le navigateur jette le message EN SILENCE (T1740). La parade était la
 * colonne `vapid_key_hash` — et notre propre code l'effaçait :
 * `revendiquer()` la réécrivait à CHAQUE réconciliation, avec `created_env`.
 *
 * T1821 l'a mesuré et épinglé par un test
 * (`TASK1821...::test_DEFAUT_CONNU_...`). Ce fichier le REMPLACE par des
 * tests qui imposent le comportement corrigé.
 *
 * ## Ce que ce fichier NE peut PAS tester, et pourquoi
 *
 * La garde côté NAVIGATEUR — comparer `options.applicationServerKey` avant
 * de conclure « déjà abonné » et avant toute reconfirmation silencieuse —
 * vit dans `push-abonnement.js` / `push-first-run.js`. Le dépôt n'a aucun
 * banc de test JavaScript unitaire (ni vitest ni jest) : aucun test PHP ne
 * peut l'exercer. Elle est vérifiée en recette navigateur réelle, et c'est
 * dit plutôt que masqué par un test qui ne prouverait rien.
 *
 * Ce fichier couvre donc les deux moitiés TESTABLES : l'intégrité serveur
 * des métadonnées de création, et la lecture des réponses fournisseur.
 */
class TASK1822VapidReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $membre;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        // L'interrupteur ET la clef : aucun CI ne porte l'un ni l'autre, et
        // sans l'interrupteur les routes rendent 404 (leçon T1821).
        config()->set('webpush.enabled', true);
        config()->set('webpush.vapid.subject', 'mailto:contact@exemple.test');

        $this->org = Organization::factory()->create(['is_active' => true]);
        $this->membre = User::factory()->create([
            'organization_id' => $this->org->id,
            'email' => 'membre.t1822@bouclepro.test',
        ]);

        // Une VRAIE Boucle : `NotificationTargetResolver` revalide la cible
        // avant tout envoi, et un `object_id` inventé la rendrait
        // introuvable — la livraison conclurait `target_no_longer_reachable`
        // sans jamais atteindre le transport qu'on veut mesurer.
        $this->loop = Loop::factory()->create([
            'organization_id' => $this->org->id,
            'created_by' => $this->membre->id,
            'status' => 'active',
        ]);

        // L'appartenance ACTIVE : `NotificationTargetResolver` l'exige, et
        // sans elle la cible est jugee « plus joignable » — la livraison
        // n'atteindrait jamais le transport que ces tests mesurent.
        LoopMember::query()->create([
            'loop_id' => $this->loop->id,
            'user_id' => $this->membre->id,
            'status' => 'active',
            'role' => 'member',
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 1. INTÉGRITÉ SERVEUR — les métadonnées de création sont immuables
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Le REMPLAÇANT de `test_DEFAUT_CONNU_...` (T1821).
     *
     * Même scénario, verdict inversé : ce que T1821 constatait comme un
     * défaut est désormais interdit.
     */
    public function test_la_reconciliation_preserve_l_environnement_et_l_empreinte_de_creation(): void
    {
        config()->set('webpush.vapid.public_key', 'clef-de-creation-t1822');

        $endpoint = $this->endpoint();
        $magasin = app(PushSubscriptionStore::class);

        $abonnement = $magasin->revendiquer($this->membre, $endpoint, str_repeat('A', 87), str_repeat('B', 22), 'Android');

        $empreinteDeCreation = $abonnement->vapid_key_hash;
        $environnementDeCreation = $abonnement->created_env;

        $this->assertNotNull($empreinteDeCreation);

        // Le serveur change de clef — exactement ce que la colonne existe
        // pour rendre visible.
        config()->set('webpush.vapid.public_key', 'une-toute-autre-clef');

        // Une simple réconciliation : le geste que le navigateur rejoue à
        // chaque ouverture de l'écran et à chaque retour sur l'application.
        $magasin->revendiquer($this->membre, $endpoint, str_repeat('A', 87), str_repeat('B', 22), null);

        $apres = $abonnement->fresh();

        $this->assertSame(
            $empreinteDeCreation,
            $apres->vapid_key_hash,
            'Une reconciliation ne doit JAMAIS re-stamper la clef : ce serait effacer la seule trace de la divergence.',
        );
        $this->assertSame($environnementDeCreation, $apres->created_env);
        $this->assertTrue(
            $apres->cleVapidDiverge(),
            'La divergence doit rester VISIBLE apres reconciliation — c\'est tout l\'objet du correctif.',
        );
    }

    public function test_un_abonnement_sans_empreinte_n_en_recoit_aucune_a_la_reconciliation(): void
    {
        config()->set('webpush.vapid.public_key', 'clef-courante-t1822');

        $endpoint = $this->endpoint();
        $magasin = app(PushSubscriptionStore::class);
        $abonnement = $magasin->revendiquer($this->membre, $endpoint, str_repeat('A', 87), str_repeat('B', 22));

        // Une ligne HISTORIQUE : créée avant que la colonne existe (T1740),
        // ou dont le hash a déjà été réécrit avant ce correctif.
        $abonnement->forceFill(['vapid_key_hash' => null])->save();

        $magasin->revendiquer($this->membre, $endpoint, str_repeat('A', 87), str_repeat('B', 22));

        $this->assertNull(
            $abonnement->fresh()->vapid_key_hash,
            "On n'invente AUCUNE clef d'origine a une ligne historique : une valeur reconstituee serait une affirmation sans mesure.",
        );
    }

    public function test_la_bascule_entre_comptes_freres_fonctionne_toujours_et_preserve_la_creation(): void
    {
        config()->set('webpush.vapid.public_key', 'clef-de-creation-t1822');

        $frere = User::factory()->create(['organization_id' => $this->org->id]);
        $endpoint = $this->endpoint();
        $magasin = app(PushSubscriptionStore::class);

        $abonnement = $magasin->revendiquer($this->membre, $endpoint, str_repeat('A', 87), str_repeat('B', 22), 'Android');
        $empreinteDeCreation = $abonnement->vapid_key_hash;

        config()->set('webpush.vapid.public_key', 'clef-changee-entre-temps');

        // Le compte frère revendique le MÊME appareil (CDC 5.2 : le dernier
        // qui s'abonne gagne).
        $magasin->revendiquer($frere, $endpoint, str_repeat('A', 87), str_repeat('B', 22));

        $apres = $abonnement->fresh();

        $this->assertSame((string) $frere->id, (string) $apres->user_id, 'La bascule de proprietaire doit continuer de fonctionner.');
        $this->assertSame(1, PushSubscription::query()->count(), 'Une seule ligne par appareil, invariant T1739 intact.');
        $this->assertSame($empreinteDeCreation, $apres->vapid_key_hash);
    }

    public function test_la_reconfirmation_repetee_reste_idempotente_et_avance_la_derniere_vue(): void
    {
        config()->set('webpush.vapid.public_key', 'clef-courante-t1822');

        $endpoint = $this->endpoint();
        $magasin = app(PushSubscriptionStore::class);

        $abonnement = $magasin->revendiquer($this->membre, $endpoint, str_repeat('A', 87), str_repeat('B', 22), 'Android');
        $vuLe = $abonnement->last_seen_at;

        $this->travel(2)->minutes();
        $magasin->revendiquer($this->membre, $endpoint, str_repeat('A', 87), str_repeat('B', 22));
        $magasin->revendiquer($this->membre, $endpoint, str_repeat('A', 87), str_repeat('B', 22));

        $apres = $abonnement->fresh();

        $this->assertSame(1, PushSubscription::query()->count());
        $this->assertTrue($apres->last_seen_at->greaterThan($vuLe), 'La reconciliation doit continuer de dire « je suis la ».');
        $this->assertSame('Android', $apres->device_label, 'Le libelle pose a l\'activation ne doit pas etre efface (T1739).');
    }

    public function test_les_clefs_de_chiffrement_restent_mises_a_jour_par_une_reconciliation(): void
    {
        config()->set('webpush.vapid.public_key', 'clef-courante-t1822');

        $endpoint = $this->endpoint();
        $magasin = app(PushSubscriptionStore::class);
        $abonnement = $magasin->revendiquer($this->membre, $endpoint, str_repeat('A', 87), str_repeat('B', 22));

        // `p256dh`/`auth` peuvent être renégociées pour un même endpoint :
        // elles doivent rester dans les colonnes mises à jour, sans quoi le
        // chiffrement viserait des clés périmées.
        $magasin->revendiquer($this->membre, $endpoint, str_repeat('C', 87), str_repeat('D', 22));

        $apres = $abonnement->fresh();

        $this->assertSame(str_repeat('C', 87), $apres->p256dh);
        $this->assertSame(str_repeat('D', 22), $apres->auth);
    }

    public function test_un_endpoint_renouvele_cree_une_nouvelle_ligne_stampee_avec_la_clef_courante(): void
    {
        config()->set('webpush.vapid.public_key', 'ancienne-clef-t1822');

        $magasin = app(PushSubscriptionStore::class);
        $ancien = $magasin->revendiquer($this->membre, $this->endpoint(), str_repeat('A', 87), str_repeat('B', 22));

        config()->set('webpush.vapid.public_key', 'nouvelle-clef-t1822');

        // Un vrai renouvellement change d'endpoint : c'est une INSERTION,
        // donc une ligne stampée avec la clé en vigueur. Aucune mise à jour
        // de métadonnées n'est nécessaire pour que la réactivation marche.
        $nouveau = $magasin->revendiquer($this->membre, $this->endpoint(), str_repeat('A', 87), str_repeat('B', 22));

        $this->assertNotSame($ancien->vapid_key_hash, $nouveau->vapid_key_hash);
        $this->assertSame(PushSubscription::empreinteCleVapid(), $nouveau->vapid_key_hash);
        $this->assertFalse($nouveau->cleVapidDiverge());
    }

    // ═══════════════════════════════════════════════════════════════════
    // 2. RÉPONSE FOURNISSEUR — un 2xx n'est pas forcément une acceptation
    // ═══════════════════════════════════════════════════════════════════

    public function test_un_2xx_au_corps_vide_reste_une_acceptation(): void
    {
        Http::fake(['*' => Http::response('', 201)]);

        $this->appareilReel();
        $livraison = $this->livrerVers();

        $this->assertSame(NotificationDeliveryStatus::SENT, $livraison->status);
        $this->assertSame('devices:1', $livraison->diagnostic);
    }

    public function test_un_2xx_json_sans_erreur_reste_une_acceptation(): void
    {
        Http::fake(['*' => Http::response(json_encode(['multicast_id' => 1, 'success' => 1, 'failure' => 0, 'results' => [['message_id' => 'x']]]), 200)]);

        $this->appareilReel();
        $livraison = $this->livrerVers();

        $this->assertSame(NotificationDeliveryStatus::SENT, $livraison->status);
    }

    public function test_un_2xx_porteur_d_une_erreur_fournisseur_n_est_plus_compte_comme_envoye(): void
    {
        // La forme exacte de l'endpoint FCM historique `/fcm/send/`.
        Http::fake(['*' => Http::response(json_encode([
            'multicast_id' => 42, 'success' => 0, 'failure' => 1, 'canonical_ids' => 0,
            'results' => [['error' => 'Unavailable']],
        ]), 200)]);

        $appareil = $this->appareilReel();
        $livraison = $this->livrerVers();

        $this->assertSame(NotificationDeliveryStatus::FAILED, $livraison->status);
        $this->assertSame('push_provider_error', $livraison->diagnostic);
        $this->assertNull($livraison->sent_at, 'Une erreur explicite ne doit jamais produire un horodatage d\'envoi.');
        $this->assertNotNull(
            PushSubscription::query()->find($appareil->id),
            'Une erreur TEMPORAIRE ne doit supprimer aucun abonnement.',
        );
    }

    public function test_un_2xx_NotRegistered_revoque_l_abonnement_comme_un_410(): void
    {
        Http::fake(['*' => Http::response(json_encode([
            'success' => 0, 'failure' => 1, 'results' => [['error' => 'NotRegistered']],
        ]), 200)]);

        $appareil = $this->appareilReel();
        $livraison = $this->livrerVers();

        $this->assertSame(NotificationDeliveryStatus::SKIPPED_UNREACHABLE, $livraison->status);
        $this->assertSame('all_devices_revoked', $livraison->diagnostic);
        $this->assertNull(
            PushSubscription::query()->find($appareil->id),
            'NotRegistered etablit que l\'enregistrement n\'existe plus : meme consequence qu\'un 410.',
        );
    }

    public function test_un_2xx_MismatchSenderId_ne_supprime_jamais_l_abonnement(): void
    {
        Http::fake(['*' => Http::response(json_encode([
            'success' => 0, 'failure' => 1, 'results' => [['error' => 'MismatchSenderId']],
        ]), 200)]);

        $appareil = $this->appareilReel();
        $livraison = $this->livrerVers();

        $this->assertSame(NotificationDeliveryStatus::FAILED, $livraison->status);
        $this->assertSame('push_provider_error', $livraison->diagnostic);
        $this->assertNotNull(
            PushSubscription::query()->find($appareil->id),
            "MismatchSenderId dit « mauvaise identite d'envoi », pas « cet appareil a disparu » : le supprimer "
            ."detruirait un abonnement vivant a cause d'un probleme de CLEF — le defaut meme que T1822 corrige.",
        );
    }

    public function test_un_corps_2xx_malforme_ne_fabrique_aucun_echec(): void
    {
        Http::fake(['*' => Http::response('<html>maintenance</html>', 200)]);

        $this->appareilReel();
        $livraison = $this->livrerVers();

        $this->assertSame(
            NotificationDeliveryStatus::SENT,
            $livraison->status,
            "Un corps illisible ne se lit pas comme un echec : on ne traite comme tel que ce qu'on a LU comme tel.",
        );
    }

    public function test_un_corps_2xx_json_inattendu_ne_fabrique_aucun_echec(): void
    {
        // Un fournisseur qui rendrait un JSON sans `failure` ni `results`.
        Http::fake(['*' => Http::response(json_encode(['status' => 'queued']), 200)]);

        $this->appareilReel();
        $livraison = $this->livrerVers();

        $this->assertSame(NotificationDeliveryStatus::SENT, $livraison->status);
    }

    public function test_un_refus_http_ordinaire_reste_un_echec_de_transport(): void
    {
        Http::fake(['*' => Http::response('', 503)]);

        $appareil = $this->appareilReel();
        $livraison = $this->livrerVers();

        $this->assertSame(NotificationDeliveryStatus::FAILED, $livraison->status);
        $this->assertSame(
            'push_service_unreachable',
            $livraison->diagnostic,
            'Un 5xx reste « le fournisseur n\'a pas repondu », distinct de « il a repondu, et il a dit non ».',
        );
        $this->assertNotNull(PushSubscription::query()->find($appareil->id));
    }

    // ═══════════════════════════════════════════════════════════════════
    // 3. NON-RÉGRESSION — le push ne traverse toujours aucune frontière
    // ═══════════════════════════════════════════════════════════════════

    public function test_l_appareil_d_un_autre_compte_n_est_jamais_servi(): void
    {
        Http::fake(['*' => Http::response('', 201)]);

        $etranger = User::factory()->create(['organization_id' => $this->org->id]);
        $sonAppareil = $this->appareilReel($etranger);

        // Le destinataire, lui, n'a aucun appareil.
        $livraison = $this->livrerVers();

        $this->assertSame(NotificationDeliveryStatus::SKIPPED_UNREACHABLE, $livraison->status);
        $this->assertSame('no_device_subscribed', $livraison->diagnostic);
        Http::assertNothingSent();
        $this->assertNotNull(PushSubscription::query()->find($sonAppareil->id));
    }

    public function test_un_destinataire_sorti_de_l_organization_ne_recoit_rien(): void
    {
        Http::fake(['*' => Http::response('', 201)]);

        $this->appareilReel();

        // Hors Eloquent : `organization_id` est immuable par garde applicative
        // (`UserOrganizationImmutableException`), et le scope de tenant
        // masquerait la mutation. Meme contournement que T1744, pour la meme
        // raison — on simule un DEPART, pas un changement ordinaire.
        // La notification existe AVANT le depart : c'est l'ordre reel, et
        // le seul que `NotificationInvariants` accepte.
        $livraison = $this->preparerLivraison();

        $autreOrg = Organization::factory()->create(['is_active' => true]);
        DB::table('users')->where('id', $this->membre->id)->update(['organization_id' => $autreOrg->id]);

        app(NotificationWebPushDeliverer::class)->deliver((string) $livraison->notification_id);
        $livraison = $livraison->fresh();

        $this->assertSame(NotificationDeliveryStatus::SKIPPED_UNREACHABLE, $livraison->status);
        $this->assertSame('recipient_left_organization', $livraison->diagnostic);
        Http::assertNothingSent();
    }

    // ═══════════════════════════════════════════════════════════════════
    // Harnais
    // ═══════════════════════════════════════════════════════════════════

    private function endpoint(): string
    {
        return 'https://fcm.googleapis.com/fcm/send/t1822-'.bin2hex(random_bytes(8));
    }

    /**
     * Un appareil RÉELLEMENT abonné, cryptographie comprise.
     *
     * Harnais de T1740/T1744 : la couche de chiffrement refuse une valeur
     * bidon, et un test qui ne chiffre pas ne mesure rien.
     */
    private function appareilReel(?User $proprietaire = null): PushSubscription
    {
        $paire = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $detail = openssl_pkey_get_details($paire);
        $publique = "\x04".str_pad($detail['ec']['x'], 32, "\0", STR_PAD_LEFT).str_pad($detail['ec']['y'], 32, "\0", STR_PAD_LEFT);

        $b64 = static fn (string $b): string => rtrim(strtr(base64_encode($b), '+/', '-_'), '=');

        // La paire du SERVEUR est posée UNE fois : sans cela, la garde de
        // divergence écarterait des abonnements parfaitement valides.
        if (((string) config('webpush.vapid.public_key')) === '') {
            $serveur = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
            $d = openssl_pkey_get_details($serveur);

            config()->set('webpush.vapid.public_key', $b64("\x04".str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT).str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT)));
            config()->set('webpush.vapid.private_key', $b64(str_pad($d['ec']['d'], 32, "\0", STR_PAD_LEFT)));
        }

        return app(PushSubscriptionStore::class)->revendiquer(
            $proprietaire ?? $this->membre,
            $this->endpoint(),
            $b64($publique),
            $b64(random_bytes(16)),
            'Android',
        );
    }

    /**
     * Fabriquer une notification + sa livraison Push, puis la livrer.
     *
     * Rend la livraison RELUE depuis la base, jamais l'instance d'avant.
     */
    private function livrerVers(): MemberNotificationDelivery
    {
        $livraison = $this->preparerLivraison();

        app(NotificationWebPushDeliverer::class)->deliver((string) $livraison->notification_id);

        return $livraison->fresh();
    }

    /**
     * Fabriquer la notification et sa livraison, SANS encore livrer.
     *
     * Séparé parce que `NotificationInvariants` refuse de créer une
     * notification pour un destinataire hors de l'Organization visée : un
     * test qui simule un DÉPART doit donc créer d'abord, muter ensuite.
     */
    private function preparerLivraison(): MemberNotificationDelivery
    {
        $this->allumerLePush();

        $notification = MemberNotification::create([
            'organization_id' => $this->org->id,
            'recipient_id' => $this->membre->id,
            'notification_key' => NotificationCatalogue::LOOP_MEMBER_ADDED,
            'event_id' => (string) Str::uuid(),
            'object_type' => NotificationCatalogue::OBJECT_LOOP,
            'object_id' => (string) $this->loop->id,
        ]);

        return MemberNotificationDelivery::query()->create([
            'notification_id' => $notification->id,
            'channel' => NotificationCatalogue::CHANNEL_WEB_PUSH,
        ]);
    }

    private function allumerLePush(): void
    {
        $preference = new MemberNotificationPreference([
            'notification_key' => NotificationCatalogue::LOOP_MEMBER_ADDED,
            'channel' => NotificationCatalogue::CHANNEL_WEB_PUSH,
            'enabled' => true,
        ]);
        // `user_id` n'est pas affectable en masse (T1375) : posé à la main.
        $preference->user_id = (string) $this->membre->id;
        $preference->save();
    }
}
