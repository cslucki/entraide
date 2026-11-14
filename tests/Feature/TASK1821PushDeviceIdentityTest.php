<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Push\PushSubscriptionStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1821 — « Mes appareils Push » (T1820) affichait une liste VIDE alors
 * qu'un abonnement venait d'être créé depuis un téléphone, via un tunnel
 * Cloudflare distinct de `test.laravel`.
 *
 * ## Le diagnostic, PAS un bug applicatif
 *
 * L'abonnement créé depuis le téléphone appartenait à un compte de
 * démonstration (`main.member1@bouclepro.test`, non-admin), pendant que
 * l'écran Superadmin était ouvert avec le compte Superadmin réel
 * (`cslucki@gmail.com`), qui n'avait — et n'a toujours — aucun abonnement en
 * base. `AdminMyPushDevicesController::index()` et `PushSubscriptionStore`
 * filtrent STRICTEMENT par `auth()->id()` (T1820) : une liste vide pour un
 * compte sans abonnement est le comportement correct, pas un défaut.
 *
 * `test_un_abonnement_cree_sous_un_autre_compte_ne_fuit_jamais_vers_le_superadmin()`
 * reproduit exactement ce scénario. Les autres tests de ce fichier couvrent
 * ce que le MASTER demandait en plus :
 *  - la cohérence entre `PushSubscriptionController::show()`,
 *    `PushSubscriptionStore::appareilsDe()` et la page Superadmin, pour le
 *    MÊME compte (point 2.D du MASTER) ;
 *  - le nouveau diagnostic LOCAL uniquement (libellé + empreinte de
 *    l'abonnement courant sur `/notifications/preferences`), absent
 *    ailleurs qu'en local.
 *
 * Aucune migration, aucun changement de droit : ce fichier ne teste QUE du
 * code déjà en place (T1739/T1820) et l'ajout local de T1821.
 */
class TASK1821PushDeviceIdentityTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        // Même nécessité que TASK1820MyPushDevicesTest : aucun CI ne porte
        // de vraie clef VAPID, et sans elle `etatCleVapid()` ne peut jamais
        // différer de VAPID_INCONNUE.
        config(['webpush.vapid.public_key' => 'clef-vapid-de-test-task-1821']);

        // L'INTERRUPTEUR, et pas seulement la clef. Mesuré à l'échec CI, pas
        // en local : `webpush.enabled` vaut `false` par défaut
        // (`env('WEB_PUSH_ENABLED', false)`), et `PushSubscriptionController`
        // rend alors 404 sur les trois routes. Mon `.env` local porte
        // `WEB_PUSH_ENABLED=true`, qui masquait l'absence de fixture — la
        // MÊME erreur que T1820 avait faite avec la clef VAPID, répétée sur
        // le drapeau voisin. `TASK1739PushRoutesTest` le pose explicitement
        // depuis toujours : c'était le modèle à suivre.
        config()->set('webpush.enabled', true);

        $this->organization = Organization::factory()->create(['is_active' => true]);
    }

    private function superadmin(): User
    {
        return User::factory()->create([
            'organization_id' => $this->organization->id,
            'is_admin' => true,
        ]);
    }

    private function membreDemo(): User
    {
        return User::factory()->create([
            'organization_id' => $this->organization->id,
            'is_admin' => false,
        ]);
    }

    private function abonnementValide(string $endpoint): array
    {
        return [
            'endpoint' => $endpoint,
            'keys' => ['p256dh' => str_repeat('A', 87), 'auth' => str_repeat('B', 22)],
            'device_label' => 'Android',
        ];
    }

    // ═══════════════════════════════════════════════════════════════════
    // 1. Le scénario exact du MASTER : deux comptes, une seule base
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Le scénario EXACT de la recette du 14/11, et ce que T1821 en fait.
     *
     * Avant : l'écran d'administration ne montrait que le compte connecté,
     * donc cet abonnement — créé depuis le téléphone, via le tunnel, sous le
     * compte de démonstration — était invisible. Exact, et inutile.
     *
     * Après : l'écran le montre, NOMMÉ avec son propriétaire, pour qu'on ne
     * puisse plus confondre « rien n'est enregistré » avec « c'est enregistré
     * sous un autre compte ».
     */
    public function test_un_abonnement_cree_sous_un_autre_compte_est_visible_et_son_proprietaire_nomme(): void
    {
        $superadmin = $this->superadmin();
        $compteDemo = $this->membreDemo();

        // Le téléphone, via le tunnel, s'abonne alors que CE navigateur est
        // authentifié comme le compte de démonstration — pas le Superadmin.
        $this->actingAs($compteDemo)
            ->postJson(route('notifications.push.store'), $this->abonnementValide(
                'https://fcm.googleapis.com/fcm/send/t1821-tunnel-'.bin2hex(random_bytes(8)),
            ))
            ->assertOk()
            ->assertJson(['subscribed' => true, 'device_count' => 1]);

        $html = $this->actingAs($superadmin)
            ->get(route('admin.outils.my-push-devices'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(__('admin.all_push_devices_empty'), $html);
        $this->assertStringContainsString($compteDemo->email, $html);

        // Et l'abonnement appartient TOUJOURS a son compte d'origine :
        // l'ecran l'affiche, il ne le transfere pas.
        $this->assertSame(
            1,
            app(PushSubscriptionStore::class)->appareilsDe($compteDemo)->count(),
            "L'appartenance de l'abonnement ne doit pas bouger : seul l'affichage a change."
        );
        $this->assertSame(
            0,
            app(PushSubscriptionStore::class)->appareilsDe($superadmin)->count(),
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // 2. Cohérence entre les écrans, pour le MÊME compte (MASTER point 2.D)
    // ═══════════════════════════════════════════════════════════════════

    public function test_le_compte_d_appareils_concorde_entre_show_le_magasin_et_la_page_superadmin(): void
    {
        $utilisateur = $this->superadmin();

        $this->actingAs($utilisateur)->postJson(route('notifications.push.store'), $this->abonnementValide(
            'https://fcm.googleapis.com/fcm/send/t1821-a-'.bin2hex(random_bytes(8)),
        ))->assertOk();

        $endpointB = 'https://fcm.googleapis.com/fcm/send/t1821-b-'.bin2hex(random_bytes(8));
        $this->actingAs($utilisateur)->postJson(route('notifications.push.store'), $this->abonnementValide($endpointB))->assertOk();

        $depuisMagasin = app(PushSubscriptionStore::class)->appareilsDe($utilisateur)->count();

        $depuisShow = $this->actingAs($utilisateur)
            ->getJson(route('notifications.push.show', ['endpoint' => $endpointB]))
            ->assertOk()
            ->json('device_count');

        $pageSuperadmin = $this->actingAs($utilisateur)
            ->get(route('admin.outils.my-push-devices'))
            ->assertOk()
            ->getContent();

        $this->assertSame(2, $depuisMagasin);
        $this->assertSame(2, $depuisShow);
        $this->assertStringContainsString(
            trans_choice('admin.push_devices_total', 2, ['count' => 2]),
            $pageSuperadmin,
            'Les trois lectures (magasin, show(), page Superadmin) doivent concorder pour le même compte.'
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // 3. Diagnostic LOCAL uniquement — libellé + empreinte de CET appareil
    // ═══════════════════════════════════════════════════════════════════

    public function test_store_rend_le_libelle_et_l_empreinte_en_local_seulement(): void
    {
        $utilisateur = $this->superadmin();
        $endpoint = 'https://fcm.googleapis.com/fcm/send/t1821-local-'.bin2hex(random_bytes(8));

        app()->detectEnvironment(fn () => 'local');

        $reponse = $this->actingAs($utilisateur)
            ->postJson(route('notifications.push.store'), $this->abonnementValide($endpoint))
            ->assertOk();

        $reponse->assertJsonPath('device_label', 'Android');
        $this->assertSame(
            substr(PushSubscription::query()->sole()->endpoint_hash, 0, 8),
            $reponse->json('fingerprint'),
        );
    }

    public function test_show_rend_le_libelle_et_l_empreinte_en_local_seulement(): void
    {
        $utilisateur = $this->superadmin();
        $endpoint = 'https://fcm.googleapis.com/fcm/send/t1821-local-show-'.bin2hex(random_bytes(8));

        $this->actingAs($utilisateur)->postJson(route('notifications.push.store'), $this->abonnementValide($endpoint))->assertOk();

        app()->detectEnvironment(fn () => 'local');

        $reponse = $this->actingAs($utilisateur)
            ->getJson(route('notifications.push.show', ['endpoint' => $endpoint]))
            ->assertOk();

        $reponse->assertJsonPath('device_label', 'Android');
        $this->assertNotNull($reponse->json('fingerprint'));
    }

    public function test_aucun_libelle_ni_empreinte_hors_environnement_local(): void
    {
        $utilisateur = $this->superadmin();
        $endpoint = 'https://fcm.googleapis.com/fcm/send/t1821-non-local-'.bin2hex(random_bytes(8));

        // L'environnement de test N'EST PAS 'local' par défaut : c'est
        // précisément ce que ce test vérifie, sans le forcer.
        $this->assertNotSame('local', app()->environment());

        $reponseStore = $this->actingAs($utilisateur)
            ->postJson(route('notifications.push.store'), $this->abonnementValide($endpoint))
            ->assertOk();

        $this->assertArrayNotHasKey('device_label', $reponseStore->json());
        $this->assertArrayNotHasKey('fingerprint', $reponseStore->json());

        $reponseShow = $this->actingAs($utilisateur)
            ->getJson(route('notifications.push.show', ['endpoint' => $endpoint]))
            ->assertOk();

        $this->assertArrayNotHasKey('device_label', $reponseShow->json());
        $this->assertArrayNotHasKey('fingerprint', $reponseShow->json());
    }

    public function test_show_ne_rend_jamais_l_appareil_d_un_autre_compte_meme_en_local(): void
    {
        $proprietaire = $this->superadmin();
        $curieux = $this->superadmin();
        $endpoint = 'https://fcm.googleapis.com/fcm/send/t1821-isole-'.bin2hex(random_bytes(8));

        $this->actingAs($proprietaire)->postJson(route('notifications.push.store'), $this->abonnementValide($endpoint))->assertOk();

        app()->detectEnvironment(fn () => 'local');

        $reponse = $this->actingAs($curieux)
            ->getJson(route('notifications.push.show', ['endpoint' => $endpoint]))
            ->assertOk();

        $reponse->assertJson(['subscribed' => false]);
        $this->assertArrayNotHasKey('device_label', $reponse->json(), "Un abonnement d'un AUTRE compte ne doit jamais être révélé, même en local.");
    }

    // ═══════════════════════════════════════════════════════════════════
    // 4. La page Superadmin dit QUEL compte elle décrit, en local
    // ═══════════════════════════════════════════════════════════════════

    public function test_la_page_superadmin_affiche_le_compte_connecte_en_local(): void
    {
        $superadmin = $this->superadmin();

        app()->detectEnvironment(fn () => 'local');

        $html = $this->actingAs($superadmin)
            ->get(route('admin.outils.my-push-devices'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($superadmin->email, $html);
    }

    public function test_la_page_superadmin_ne_revele_pas_l_email_hors_local(): void
    {
        $superadmin = $this->superadmin();

        $this->assertNotSame('local', app()->environment());

        $html = $this->actingAs($superadmin)
            ->get(route('admin.outils.my-push-devices'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString($superadmin->email, $html);
    }
}
