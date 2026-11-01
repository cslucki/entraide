<?php

namespace Tests\Feature;

use App\Jobs\SendNotificationEmail;
use App\Jobs\SendNotificationWebPush;
use App\Models\MemberNotification;
use App\Models\MemberNotificationDelivery;
use App\Models\Organization;
use App\Models\PushSubscription;
use App\Models\User;
use App\Support\Notifications\NotificationCatalogue;
use App\Support\Notifications\NotificationDeliveryPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Lang;
use Tests\TestCase;

/**
 * TASK-1740 — le canal `web_push` comme TROISIEME mode de livraison.
 *
 * Deux choses sont defendues ici, et la premiere conditionne la seconde :
 * que le defaut du planificateur soit reellement corrige, et que le Push
 * n'emporte rien de prive sur un ecran verrouille.
 */
class TASK1740WebPushChannelTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $alice;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('webpush.enabled', true);
        config()->set('webpush.vapid.subject', 'mailto:contact@exemple.test');

        $this->org = Organization::factory()->create(['is_active' => true]);
        $this->alice = User::factory()->create(['organization_id' => $this->org->id]);
    }

    // ── Le defaut du planificateur ──────────────────────────────────────────

    public function test_un_canal_ne_met_en_file_que_SON_job(): void
    {
        // LE defaut de T1377 : `plan()` dispatchait `SendNotificationEmail`
        // DANS la boucle sur les canaux, sans jamais lui passer le canal.
        // Invisible tant que la liste des canaux asynchrones n'avait qu'une
        // entree ; a deux entrees, chaque canal mettait un job EMAIL en file.
        Bus::fake();

        $planificateur = new class extends NotificationDeliveryPlanner
        {
            // On force les DEUX canaux asynchrones, sans dependre de ce que le
            // catalogue autorise aujourd'hui : c'est le DISPATCH qu'on mesure.
            protected function canauxALivrer(MemberNotification $notification): array
            {
                return [NotificationCatalogue::CHANNEL_EMAIL, NotificationCatalogue::CHANNEL_WEB_PUSH];
            }
        };

        $notification = $this->notification();
        $planificateur->plan($notification);

        // UN job email, UN job push. Avant le correctif : DEUX jobs email.
        Bus::assertDispatchedTimes(SendNotificationEmail::class, 1);
        Bus::assertDispatchedTimes(SendNotificationWebPush::class, 1);
    }

    public function test_les_deux_jobs_vivent_dans_des_files_distinctes(): void
    {
        // Un worker borne a l'une ne doit pas drainer l'autre : les deux
        // canaux n'ont ni le meme cout ni la meme urgence.
        $this->assertNotSame(
            (new SendNotificationEmail('x'))->queue,
            (new SendNotificationWebPush('x'))->queue,
        );
    }

    // ── L'accord entre le catalogue et la table ─────────────────────────────

    public function test_tout_canal_du_catalogue_est_accepte_en_livraison(): void
    {
        // `MemberNotificationDelivery::garantirCanal()` porte une SECONDE
        // enumeration, non derivee du catalogue. Elle defend la table — pas de
        // livraison `pending` eternelle faute de worker — mais elle doit etre
        // tenue a jour en meme temps. Ce test mesure cet accord plutot que de
        // compter sur la vigilance.
        $notification = $this->notification();

        foreach ([NotificationCatalogue::CHANNEL_IN_APP, NotificationCatalogue::CHANNEL_EMAIL, NotificationCatalogue::CHANNEL_WEB_PUSH] as $canal) {
            $livraison = MemberNotificationDelivery::create([
                'notification_id' => $notification->id,
                'channel' => $canal,
            ]);

            $this->assertSame($canal, $livraison->channel);
        }
    }

    public function test_un_canal_inconnu_reste_refuse(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        MemberNotificationDelivery::create([
            'notification_id' => $this->notification()->id,
            'channel' => 'sms',
        ]);
    }

    public function test_le_canal_porte_un_libelle_dans_les_DEUX_locales(): void
    {
        // Exige par `TASK1379NotificationCatalogueCoherenceTest` pour tout
        // canal declare ; verifie ici aussi pour que l'oubli se voie dans la
        // TASK qui ajoute le canal, pas trois suites plus loin.
        foreach (['fr', 'en'] as $locale) {
            $this->assertTrue(
                Lang::has('notifications.channel_web_push', $locale),
                "libelle manquant en {$locale}",
            );
        }
    }

    // ── La charge utile, sur un ecran VERROUILLE ────────────────────────────

    public function test_la_charge_utile_ne_dit_rien_de_prive(): void
    {
        // CDC 6.4 : un Push s'affiche devant n'importe qui. Le nom de la
        // Boucle, celui de la personne et tout extrait doivent rester
        // DEHORS — le detail se lit apres le clic, sur une page re-autorisee.
        Http::fake(['*' => Http::response('', 201)]);

        $boucleSecrete = 'Boucle Tres Confidentielle';
        $this->alice->forceFill(['name' => 'Alice Nommee'])->save();

        $appareil = $this->appareil();
        $envoye = null;

        Http::fake(function ($request) use (&$envoye) {
            $envoye = $request;

            return Http::response('', 201);
        });

        app(\App\Support\Notifications\NotificationWebPushDeliverer::class)
            ->envoyerUnTest(collect([$appareil]), [
                'title' => 'BouclePro',
                'body' => 'Touchez pour voir.',
                'url' => route('notifications.index'),
                'tag' => 'test',
            ]);

        $this->assertNotNull($envoye, 'aucune requete sortante');

        // Le corps est CHIFFRE : rien de lisible ne doit y apparaitre, et
        // c'est aussi la preuve que le chiffrement RFC 8291 a bien eu lieu.
        $corps = $envoye->body();
        $this->assertStringNotContainsString($boucleSecrete, $corps);
        $this->assertStringNotContainsString('Alice Nommee', $corps);
        $this->assertStringNotContainsString('Touchez pour voir', $corps);
    }

    public function test_l_envoi_porte_les_entetes_VAPID_et_le_chiffrement(): void
    {
        $appareil = $this->appareil();
        $vu = null;

        Http::fake(function ($request) use (&$vu) {
            $vu = $request;

            return Http::response('', 201);
        });

        app(\App\Support\Notifications\NotificationWebPushDeliverer::class)
            ->envoyerUnTest(collect([$appareil]), ['title' => 'T', 'body' => 'B', 'url' => '/', 'tag' => 't']);

        $this->assertNotNull($vu);
        $this->assertSame('aes128gcm', $vu->header('Content-Encoding')[0] ?? null);
        $this->assertStringStartsWith('vapid t=', $vu->header('Authorization')[0] ?? '');
    }

    public function test_un_endpoint_revoque_est_retire(): void
    {
        // 410 : le fournisseur dit que cet endpoint n'existe plus. Il fait foi
        // sur ses propres endpoints — c'est le seul nettoyage sur lequel nous
        // avons une autorite.
        $appareil = $this->appareil();
        Http::fake(['*' => Http::response('', 410)]);

        $resultat = app(\App\Support\Notifications\NotificationWebPushDeliverer::class)
            ->envoyerUnTest(collect([$appareil]), ['title' => 'T', 'body' => 'B', 'url' => '/', 'tag' => 't']);

        $this->assertSame(1, $resultat['revoque']);
        $this->assertSame(0, PushSubscription::count());
    }

    public function test_un_envoi_de_test_ne_cree_aucune_livraison(): void
    {
        // Un test n'est pas un evenement du produit : il ne doit laisser
        // aucune trace qui lui ressemblerait.
        Http::fake(['*' => Http::response('', 201)]);

        app(\App\Support\Notifications\NotificationWebPushDeliverer::class)
            ->envoyerUnTest(collect([$this->appareil()]), ['title' => 'T', 'body' => 'B', 'url' => '/', 'tag' => 't']);

        $this->assertSame(0, MemberNotificationDelivery::count());
        $this->assertSame(0, MemberNotification::count());
    }

    // ── Le Service Worker ───────────────────────────────────────────────────

    public function test_le_service_worker_ecoute_push_et_notificationclick(): void
    {
        $sw = (string) file_get_contents(public_path('sw.js'));

        $this->assertStringContainsString("addEventListener('push'", $sw);
        $this->assertStringContainsString("addEventListener('notificationclick'", $sw);
    }

    public function test_les_ecouteurs_push_n_ecrivent_JAMAIS_dans_le_cache(): void
    {
        // LE point qui affaiblirait T1692 : la cle de cache est l'URL SEULE,
        // sans identite. Tout ce qu'un ecouteur `push` y deposerait serait
        // restituable a un autre profil.
        $sw = (string) file_get_contents(public_path('sw.js'));
        $apres = substr($sw, (int) strpos($sw, "addEventListener('push'"));

        // On mesure le CODE, pas les commentaires qui nomment la regle.
        $code = implode("\n", array_filter(
            explode("\n", $apres),
            static fn (string $l): bool => ! preg_match('/^\s*(\/\/|\*|\/\*)/', $l),
        ));

        $this->assertStringNotContainsString('cache.put', $code);
        $this->assertStringNotContainsString('caches.match', $code);
        $this->assertStringNotContainsString('caches.open', $code);
    }

    public function test_la_strategie_fetch_de_T1692_est_intacte(): void
    {
        // Les documents restent en RESEAU SEUL : ni lecture ni ecriture du
        // cache. C'est l'invariant que T1692 a paye, et aucun ecouteur ajoute
        // ici ne doit l'entamer.
        $sw = (string) file_get_contents(public_path('sw.js'));

        $this->assertStringContainsString("TASK-1692 / MICRO-09 — reseau SEUL", $sw);
        $this->assertStringContainsString("request.destination === 'document'", $sw);
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    private function notification(): MemberNotification
    {
        return MemberNotification::create([
            'organization_id' => $this->org->id,
            'recipient_id' => $this->alice->id,
            'notification_key' => NotificationCatalogue::LOOP_INVITATION,
            'event_id' => (string) \Illuminate\Support\Str::uuid(),
            'object_type' => NotificationCatalogue::objectTypeFor(NotificationCatalogue::LOOP_INVITATION),
        ]);
    }

    private function appareil(): PushSubscription
    {
        // Des clefs VAPID et un p256dh REELS : la cryptographie refuse une
        // valeur bidon, et un test qui ne chiffre pas ne mesure rien.
        $paire = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $detail = openssl_pkey_get_details($paire);
        $publique = "\x04".str_pad($detail['ec']['x'], 32, "\0", STR_PAD_LEFT).str_pad($detail['ec']['y'], 32, "\0", STR_PAD_LEFT);

        $serveur = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $detailServeur = openssl_pkey_get_details($serveur);
        $b64 = static fn (string $b): string => rtrim(strtr(base64_encode($b), '+/', '-_'), '=');

        config()->set('webpush.vapid.public_key', $b64("\x04".str_pad($detailServeur['ec']['x'], 32, "\0", STR_PAD_LEFT).str_pad($detailServeur['ec']['y'], 32, "\0", STR_PAD_LEFT)));
        config()->set('webpush.vapid.private_key', $b64(str_pad($detailServeur['ec']['d'], 32, "\0", STR_PAD_LEFT)));

        return app(\App\Services\Push\PushSubscriptionStore::class)->revendiquer(
            $this->alice,
            'https://fcm.googleapis.com/fcm/send/test-'.bin2hex(random_bytes(6)),
            $b64($publique),
            $b64(random_bytes(16)),
            'Android',
        );
    }

    // ── L'outil d'administration ────────────────────────────────────────────

    public function test_l_ecran_d_administration_se_CHARGE(): void
    {
        // Defaut trouve par Cyril : 500 sur /admin/outils/test-notification.
        // Cause — un mauvais namespace pour `ConfigurationUrlParser`, que
        // `php -l` ne voit pas et qu'aucun test ne voyait non plus, parce
        // qu'AUCUN ne chargeait la page. Verifier qu'une route est declaree ne
        // dit rien de ce qui se passe quand on l'ouvre.
        $admin = User::factory()->create(['organization_id' => $this->org->id, 'is_admin' => true]);

        $this->actingAs($admin)->get(route('admin.outils.test-notification'))->assertOk();
    }

    public function test_l_ecran_n_affiche_JAMAIS_la_clef_privee(): void
    {
        config()->set('webpush.vapid.private_key', 'SECRET-PRIVE-A-NE-PAS-AFFICHER');
        $admin = User::factory()->create(['organization_id' => $this->org->id, 'is_admin' => true]);

        $html = $this->actingAs($admin)->get(route('admin.outils.test-notification'))->assertOk()->getContent();

        $this->assertStringNotContainsString('SECRET-PRIVE-A-NE-PAS-AFFICHER', $html);
    }

    public function test_l_outil_est_reserve_a_l_administration(): void
    {
        $this->actingAs($this->alice)->get(route('admin.outils.test-notification'))->assertForbidden();
        $this->actingAs($this->alice)->post(route('admin.outils.test-notification.send'))->assertForbidden();
    }

    public function test_l_outil_n_envoie_QU_A_SOI(): void
    {
        // La garde demandee par MASTER, et elle est STRUCTURELLE : l'outil ne
        // choisit pas de destinataire, il prend les appareils de la personne
        // qui clique. Le telephone d'un autre ne peut pas etre reveille.
        Http::fake(['*' => Http::response('', 201)]);

        $admin = User::factory()->create(['organization_id' => $this->org->id, 'is_admin' => true]);
        $appareilDAlice = $this->appareil();

        $this->actingAs($admin)->post(route('admin.outils.test-notification.send'))->assertRedirect();

        // Alice a un appareil, l'admin n'en a pas : rien ne doit partir.
        Http::assertNothingSent();
        $this->assertSame(1, PushSubscription::where('id', $appareilDAlice->id)->count());
    }
}
