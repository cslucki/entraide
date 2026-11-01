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
use App\Support\Ops\ExternalEgressGuard;
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

    /**
     * La CAUSE RACINE de la panne du 01/11, verrouillee.
     *
     * En `aes128gcm` (RFC 8188), le texte clair doit se terminer par l'octet
     * delimiteur de fin d'enregistrement `0x02`. `padPayload()` l'ajoute et le
     * complete a longueur fixe. Sans cet appel, l'enregistrement est MALFORME :
     * Chrome le dechiffre, le rejette, et jette le message EN SILENCE — pendant
     * que le fournisseur, qui ne regarde jamais le chiffre, repond `201`.
     *
     * Ce test mesure la seule chose observable depuis le serveur : la TAILLE du
     * corps. Rembourree, elle est CONSTANTE — 86 octets d'en-tete de codage,
     * 2820 de texte clair, 16 d'etiquette GCM. Retirer `padPayload()` la fait
     * s'effondrer a la taille du message, et ce test rougit.
     *
     * La constance est verifiee sur DEUX messages de longueurs tres
     * differentes : une taille correcte sur un seul message pourrait etre une
     * coincidence, deux tailles egales pour deux messages inegaux ne peuvent
     * pas en etre une. C'est aussi la garantie de confidentialite — sans
     * rembourrage, la taille du chiffre fuirait la longueur du message.
     */
    public function test_la_charge_est_REMBOURREE_a_longueur_fixe_avant_chiffrement(): void
    {
        $tailles = [];

        Http::fake(function ($request) use (&$tailles) {
            $tailles[] = strlen((string) $request->body());

            return Http::response('', 201);
        });

        $livreur = app(\App\Support\Notifications\NotificationWebPushDeliverer::class);

        $livreur->envoyerUnTest(collect([$this->appareil()]), [
            'title' => 'T', 'body' => 'B', 'url' => '/', 'tag' => 't',
        ]);

        $livreur->envoyerUnTest(collect([$this->appareil()]), [
            'title' => str_repeat('T', 70),
            'body' => str_repeat('B', 150),
            'url' => '/notifications',
            'tag' => 'une-cle-de-catalogue-bien-plus-longue',
        ]);

        $this->assertCount(2, $tailles);

        // 86 (en-tete de codage : sel 16 + taille 4 + longueur 1 + clef 65)
        // + 2820 (texte clair rembourre) + 16 (etiquette GCM).
        $this->assertSame(2922, $tailles[0], 'le corps du premier envoi n\'est pas rembourre');
        $this->assertSame(
            $tailles[0],
            $tailles[1],
            'deux messages de longueurs differentes produisent des corps de tailles differentes : la taille FUIT la longueur du message',
        );
    }

    public function test_l_envoi_porte_une_urgence_HAUTE(): void
    {
        // Android DIFFERE les messages d'urgence `normal` quand l'appareil est
        // en veille profonde : accepte, mis en file, affiche bien plus tard —
        // indiscernable d'une panne. Une notification destinee a etre VUE est
        // `high` par definition.
        $vu = null;

        Http::fake(function ($request) use (&$vu) {
            $vu = $request;

            return Http::response('', 201);
        });

        app(\App\Support\Notifications\NotificationWebPushDeliverer::class)
            ->envoyerUnTest(collect([$this->appareil()]), ['title' => 'T', 'body' => 'B', 'url' => '/', 'tag' => 't']);

        $this->assertSame('high', $vu->header('Urgency')[0] ?? null);
    }

    public function test_le_service_worker_ne_pose_AUCUN_badge(): void
    {
        // Android attend ici une silhouette MONOCHROME sur fond transparent,
        // qu'il peint en blanc dans la barre d'etat ; le logo couleur 192 px ne
        // s'y prete pas et produisait une barre d'etat VIDE. Recette du 01/11 :
        // l'essai sans badge montrait la cloche par defaut de Chrome, celui
        // avec badge ne montrait aucune icone.
        $sw = file_get_contents(public_path('sw.js'));

        $this->assertStringNotContainsString('badge:', $sw);
        $this->assertStringContainsString("icon: '/brand/icon-192.png'", $sw);
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

    /** @return array<string, array{0: string, 1: string, 2: bool, 3: bool}> */
    public static function casDEgress(): array
    {
        return [
            // nom => [schema, hote, marqueur de transport, attendu]
            'Push vers FCM' => ['https', 'fcm.googleapis.com', true, true],
            'Push vers Apple' => ['https', 'web.push.apple.com', true, true],
            'Push vers Firefox' => ['https', 'updates.push.services.mozilla.com', true, true],
            'Push vers Windows' => ['https', 'x.notify.windows.com', true, true],

            // L'exigence explicite de MASTER : le MEME domaine, sans le
            // marqueur de transport, reste refuse.
            'HTTP ordinaire vers FCM' => ['https', 'fcm.googleapis.com', false, false],

            'Push en http' => ['http', 'fcm.googleapis.com', true, false],
            'Domaine inconnu' => ['https', 'exemple-pirate.test', true, false],

            // Trois ruses de nommage, toutes trouvees en EPROUVANT le code et
            // non en le relisant : les deux premieres passaient avant que la
            // correspondance exacte ne remplace `str_ends_with`.
            'Prefixe colle' => ['https', 'evil-fcm.googleapis.com', true, false],
            'Prefixe colle court' => ['https', 'xfcm.googleapis.com', true, false],
            'Faux sous-domaine Apple' => ['https', 'evilpush.apple.com', true, false],
            'Ruse de suffixe' => ['https', 'fcm.googleapis.com.evil.net', true, false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('casDEgress')]
    public function test_l_exception_d_egress_n_admet_que_le_transport_push(string $schema, string $hote, bool $marqueur, bool $attendu): void
    {
        config()->set('webpush.egress_allowed', true);

        $options = $marqueur ? [ExternalEgressGuard::OPTION_TRANSPORT_PUSH => true] : [];

        $this->assertSame($attendu, ExternalEgressGuard::sortieDeRecetteAutorisee($schema, $hote, $options));
    }

    public function test_sans_le_drapeau_meme_le_push_est_refuse(): void
    {
        // Une variable absente n'ouvre rien : c'est la premiere des quatre
        // conditions, et elle est FAUSSE par defaut.
        config()->set('webpush.egress_allowed', false);

        $this->assertFalse(ExternalEgressGuard::sortieDeRecetteAutorisee(
            'https', 'fcm.googleapis.com', [ExternalEgressGuard::OPTION_TRANSPORT_PUSH => true],
        ));
    }

    public function test_en_PRODUCTION_l_exception_n_existe_pas(): void
    {
        // Verifie AVANT le drapeau : meme un `.env` de production qui
        // porterait WEB_PUSH_EGRESS_ALLOWED=true n'ouvrirait rien.
        config()->set('webpush.egress_allowed', true);
        app()->detectEnvironment(fn () => 'production');

        $this->assertFalse(ExternalEgressGuard::sortieDeRecetteAutorisee(
            'https', 'fcm.googleapis.com', [ExternalEgressGuard::OPTION_TRANSPORT_PUSH => true],
        ));
    }

    public function test_le_livreur_POSE_le_marqueur_de_transport(): void
    {
        // Si le marqueur n'etait pas pose, l'exception ne servirait a rien et
        // l'envoi serait refuse en recette. C'est le raccord entre le livreur
        // et le garde, et il doit etre mesure.
        $appareil = $this->appareil();
        $vu = null;

        Http::fake(function ($request, $options) use (&$vu) {
            $vu = $options;

            return Http::response('', 201);
        });

        app(\App\Support\Notifications\NotificationWebPushDeliverer::class)
            ->envoyerUnTest(collect([$appareil]), ['title' => 'T', 'body' => 'B', 'url' => '/', 'tag' => 't']);

        $this->assertTrue($vu[ExternalEgressGuard::OPTION_TRANSPORT_PUSH] ?? false);
    }

    // ── La politique de recette : QUI, et sur QUEL appareil ─────────────────

    private function admin(): User
    {
        return User::factory()->create([
            'organization_id' => $this->org->id,
            'is_admin' => true,
            'email' => 'admin-'.bin2hex(random_bytes(4)).'@bouclepro.test',
        ]);
    }

    private function compteReel(): User
    {
        // Le jumeau en porte 21 en `gmail.com` — mesure du 01/11.
        return User::factory()->create([
            'organization_id' => $this->org->id,
            'email' => 'vraie.personne-'.bin2hex(random_bytes(3)).'@gmail.com',
        ]);
    }

    public function test_un_compte_REEL_n_est_jamais_selectionnable(): void
    {
        $reel = $this->compteReel();
        $politique = app(\App\Support\Push\PushTestPolicy::class);

        $this->assertFalse($politique->destinataireEstAdmis($reel));
        $this->assertNotContains(
            $reel->id,
            $politique->destinatairesAdmis()->pluck('id')->all(),
        );
    }

    public function test_un_compte_de_recette_est_selectionnable(): void
    {
        $recette = User::factory()->create([
            'organization_id' => $this->org->id,
            'email' => 'main.member2@bouclepro.test',
        ]);

        $this->assertTrue(app(\App\Support\Push\PushTestPolicy::class)->destinataireEstAdmis($recette));
    }

    public function test_un_abonnement_IMPORTE_de_production_n_est_pas_envoyable(): void
    {
        // La garde que MASTER a demandee : « envoi limite aux abonnements
        // crees localement ». L'origine voyage avec la ligne.
        $appareil = $this->appareil();
        $appareil->forceFill(['created_env' => 'production'])->save();

        $this->assertSame(
            0,
            app(\App\Support\Push\PushTestPolicy::class)->abonnementsEnvoyables($this->alice)->count(),
        );
    }

    public function test_un_abonnement_d_origine_INCONNUE_n_est_pas_envoyable(): void
    {
        // Un `null` ne vaut pas « locale » : il vaut « origine inconnue ».
        $appareil = $this->appareil();
        $appareil->forceFill(['created_env' => null])->save();

        $this->assertSame(
            0,
            app(\App\Support\Push\PushTestPolicy::class)->abonnementsEnvoyables($this->alice)->count(),
        );
    }

    public function test_le_reperage_ne_rend_JAMAIS_l_endpoint_ni_les_clefs(): void
    {
        $appareil = $this->appareil();
        $reperage = app(\App\Support\Push\PushTestPolicy::class)->reperageDe($appareil);

        $this->assertSame('fcm.googleapis.com', $reperage['fournisseur']);
        $this->assertSame(8, strlen($reperage['empreinte']));

        $concatene = implode('|', $reperage);
        $this->assertStringNotContainsString($appareil->endpoint, $concatene);
        $this->assertStringNotContainsString($appareil->p256dh, $concatene);
        $this->assertStringNotContainsString($appareil->auth, $concatene);
    }

    // ── L'outil d'administration, de bout en bout ───────────────────────────

    public function test_l_admin_envoie_a_l_appareil_CHOISI_d_un_autre_compte(): void
    {
        // LE cas de recette de Cyril : depuis son PC, en administrateur, il
        // choisit main.member2 puis son Android, et le telephone sonne —
        // sans avoir a se connecter dessus avec le compte admin.
        Http::fake(['*' => Http::response('', 201)]);

        $appareil = $this->appareil();   // appartient a $this->alice
        $this->alice->forceFill(['email' => 'main.member2@bouclepro.test'])->save();

        $this->actingAs($this->admin())
            ->post(route('admin.outils.test-notification.send'), [
                'user' => $this->alice->id,
                'subscription' => $appareil->id,
                'title' => 'Test',
                'body' => 'Message',
            ])
            ->assertRedirect();

        Http::assertSentCount(1);
    }

    public function test_un_envoi_cible_ne_reveille_PAS_les_autres_appareils(): void
    {
        Http::fake(['*' => Http::response('', 201)]);

        $this->alice->forceFill(['email' => 'main.member2@bouclepro.test'])->save();
        $premier = $this->appareil();
        $this->appareil();  // un second appareil du meme compte
        $this->appareil();  // un troisieme

        $this->actingAs($this->admin())
            ->post(route('admin.outils.test-notification.send'), [
                'user' => $this->alice->id,
                'subscription' => $premier->id,
                'title' => 'T', 'body' => 'B',
            ])->assertRedirect();

        // UN seul depot, celui de l'appareil choisi.
        Http::assertSentCount(1);
    }

    public function test_un_destinataire_REEL_est_refuse_au_POST(): void
    {
        // Le formulaire ne fait pas autorite : il se fabrique a la main.
        Http::fake();

        $reel = $this->compteReel();
        $appareil = $this->appareil();

        $this->actingAs($this->admin())
            ->post(route('admin.outils.test-notification.send'), [
                'user' => $reel->id,
                'subscription' => $appareil->id,
                'title' => 'T', 'body' => 'B',
            ])->assertRedirect();

        Http::assertNothingSent();
    }

    public function test_un_appareil_d_un_AUTRE_compte_est_refuse_au_POST(): void
    {
        Http::fake();

        $this->alice->forceFill(['email' => 'main.member2@bouclepro.test'])->save();
        $appareilDAlice = $this->appareil();

        $autre = User::factory()->create([
            'organization_id' => $this->org->id,
            'email' => 'main.member1@bouclepro.test',
        ]);

        // On designe `autre` comme destinataire, mais l'appareil d'Alice.
        $this->actingAs($this->admin())
            ->post(route('admin.outils.test-notification.send'), [
                'user' => $autre->id,
                'subscription' => $appareilDAlice->id,
                'title' => 'T', 'body' => 'B',
            ])->assertRedirect();

        Http::assertNothingSent();
    }

    public function test_une_destination_EXTERNE_est_refusee(): void
    {
        Http::fake();
        $this->alice->forceFill(['email' => 'main.member2@bouclepro.test'])->save();
        $appareil = $this->appareil();

        $this->actingAs($this->admin())
            ->post(route('admin.outils.test-notification.send'), [
                'user' => $this->alice->id,
                'subscription' => $appareil->id,
                'title' => 'T', 'body' => 'B',
                'path' => 'https://exemple-pirate.test/phishing',
            ])->assertSessionHasErrors('path');

        Http::assertNothingSent();
    }

    public function test_l_ecran_ne_propose_QUE_des_comptes_de_recette(): void
    {
        $reel = $this->compteReel();
        $this->alice->forceFill(['email' => 'main.member2@bouclepro.test'])->save();

        $html = $this->actingAs($this->admin())
            ->get(route('admin.outils.test-notification'))->assertOk()->getContent();

        $this->assertStringContainsString('main.member2@bouclepro.test', $html);
        $this->assertStringNotContainsString($reel->email, $html);
    }

    public function test_l_ecran_n_affiche_jamais_l_endpoint_complet(): void
    {
        $this->alice->forceFill(['email' => 'main.member2@bouclepro.test'])->save();
        $appareil = $this->appareil();

        $html = $this->actingAs($this->admin())
            ->get(route('admin.outils.test-notification', ['user' => $this->alice->id]))
            ->assertOk()->getContent();

        $this->assertStringNotContainsString($appareil->endpoint, $html);
        $this->assertStringNotContainsString($appareil->p256dh, $html);
        $this->assertStringNotContainsString($appareil->auth, $html);
    }
}
