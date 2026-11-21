<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Push\PushSubscriptionStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as RequeteSortante;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TASK-1877 — « Notifications sur cet appareil » sur sa propre page, avec un
 * test Web Push REEL borne a l'appareil courant du compte connecte.
 *
 * Ce que ce fichier defend, par ordre d'importance :
 *
 * 1. le test ne touche QUE l'abonnement designe par l'endpoint du
 *    navigateur, et seulement s'il appartient au compte connecte et reste
 *    envoyable (environnement, clef VAPID) ;
 * 2. un 2xx n'est jamais presente comme une reception (R2 MASTER) ;
 * 3. une revocation retire la ligne serveur ET le script oublie l'abonnement
 *    navigateur avant toute reconfirmation (R1 MASTER) ;
 * 4. le diagnostic avance est publie, replie, sans ses outils de recette.
 */
class TASK1877DevicePageTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $alice;

    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();

        // T1822 — sans ces deux reglages, le banc rend 404 et le `.env`
        // local le masquerait.
        config()->set('webpush.enabled', true);
        config()->set('webpush.vapid.subject', 'mailto:contact@exemple.test');
        $this->poserPaireVapid();

        $this->org = Organization::factory()->create(['is_active' => true]);
        $this->alice = User::factory()->create(['organization_id' => $this->org->id]);
        $this->bob = User::factory()->create(['organization_id' => $this->org->id]);

    }

    // ── La page ──────────────────────────────────────────────────────────────

    public function test_la_page_dediee_porte_le_bloc_le_bouton_de_test_et_le_diagnostic_replie(): void
    {
        $html = $this->actingAs($this->alice)->get(route('notifications.device.edit'))
            ->assertOk()->getContent();

        $this->assertStringContainsString('data-push-device', $html);
        $this->assertStringContainsString('data-push-test-url="'.route('notifications.device.test').'"', $html);
        $this->assertStringContainsString('data-push-test ', $html);
        $this->assertStringContainsString('<details data-push-diag-panel', $html);
        $this->assertStringNotContainsString('<details data-push-diag-panel open', $html);
        $this->assertStringContainsString(e(__('push.diag.title')), $html);
        // Le lien d'aide mene aux reglages par evenement, sur LEUR page.
        $this->assertStringContainsString(route('notifications.preferences.edit'), $html);
    }

    public function test_la_page_existe_aussi_en_forme_Organization(): void
    {
        $params = ['organization' => $this->org->slug];

        $html = $this->actingAs($this->alice)->get(route('organization.notifications.device.edit', $params))
            ->assertOk()->getContent();

        // Les trois URL du bloc restent dans la forme de la page courante.
        $this->assertStringContainsString(route('organization.notifications.push.store', $params), $html);
        $this->assertStringContainsString(route('organization.notifications.device.test', $params), $html);
        $this->assertStringContainsString(route('organization.notifications.preferences.edit', $params), $html);
    }

    public function test_un_invite_ne_voit_ni_la_page_ni_le_test(): void
    {
        $this->get(route('notifications.device.edit'))->assertRedirect(route('login'));
        $this->post(route('notifications.device.test'), ['endpoint' => 'https://x.test/e'])->assertRedirect(route('login'));
        Http::assertNothingSent();
    }

    /**
     * Demande de Cyril (21/11) — les cartes de la page des preferences sont
     * PLIEES par defaut. Chaque carte de Boucle et d'evenement porte un
     * `<details>` ferme ; les ancres `data-preference-*` restent en place.
     */
    public function test_les_cartes_des_preferences_sont_pliees_par_defaut(): void
    {
        $html = $this->actingAs($this->alice)->get(route('notifications.preferences.edit'))
            ->assertOk()->getContent();

        $cartes = substr_count($html, '<details data-preference-card');
        $evenements = substr_count($html, '<li data-preference-key=');

        $this->assertGreaterThan(0, $evenements, 'precondition : des cartes d evenement sont rendues');
        $this->assertSame($evenements + substr_count($html, '<li data-loop-preference='), $cartes, 'chaque carte est pliable');
        // L'ATTRIBUT `open`, et non la classe Tailwind `open:pb-2` que
        // porte chaque carte : `\bopen\b` les confondait (le `:` est une
        // frontiere de mot) et voyait une carte ouverte qui ne l'etait pas.
        $this->assertSame(0, preg_match('/<details data-preference-card[^>]*\sopen(?=[\s>=])/', $html), 'aucune carte depliee par defaut');
    }

    public function test_la_page_des_preferences_ne_porte_plus_le_bloc_mais_y_mene(): void
    {
        $html = $this->actingAs($this->alice)->get(route('notifications.preferences.edit'))
            ->assertOk()->getContent();

        $this->assertStringNotContainsString('data-push-device=', $html);
        $this->assertStringNotContainsString('data-push-device ', $html);
        $this->assertStringNotContainsString('data-push-diag-panel', $html);
        $this->assertStringContainsString('data-push-device-link', $html);
        $this->assertStringContainsString('href="'.route('notifications.device.edit').'"', $html);
        $this->assertStringContainsString(e(__('push.device_settings_button')), $html);
        // Les reglages par evenement, eux, restent ici.
        $this->assertStringContainsString('id="reglages-par-evenement"', $html);
    }

    public function test_hors_local_le_diagnostic_ne_publie_aucun_outil_de_recette(): void
    {
        $html = $this->actingAs($this->alice)->get(route('notifications.device.edit'))
            ->assertOk()->getContent();

        // Publie : les lignes et les deux essais sans reseau.
        $this->assertStringContainsString('data-push-diag-value="permission"', $html);
        $this->assertStringContainsString('data-push-diag-value="cle"', $html);
        $this->assertStringContainsString('data-push-diag-local', $html);
        $this->assertStringContainsString('data-push-diag-simuler', $html);

        // Local seulement (arbitrage MASTER du 21/11).
        $this->assertStringNotContainsString('data-push-first-run-reset', $html);
        $this->assertStringNotContainsString('data-push-diag-value="premier-lancement"', $html);
        $this->assertStringNotContainsString('data-push-device-info', $html);
        $this->assertStringContainsString('data-push-debug="0"', $html);
        $this->assertStringNotContainsString('recette locale', $html);
    }

    public function test_en_local_les_outils_de_recette_reviennent(): void
    {
        app()->detectEnvironment(fn () => 'local');

        $html = $this->actingAs($this->alice)->get(route('notifications.device.edit'))
            ->assertOk()->getContent();

        $this->assertStringContainsString('data-push-first-run-reset', $html);
        $this->assertStringContainsString('data-push-diag-value="premier-lancement"', $html);
        $this->assertStringContainsString('data-push-debug="1"', $html);
    }

    // ── Le test Web Push reel ────────────────────────────────────────────────

    public function test_le_test_atteint_l_appareil_courant_et_lui_seul(): void
    {
        $courant = $this->appareil($this->alice);
        $autre = $this->appareil($this->alice);
        $this->appareil($this->bob);

        $vus = [];
        Http::fake(function (RequeteSortante $requete) use (&$vus) {
            $vus[] = $requete->url();

            return Http::response('', 201);
        });

        $this->actingAs($this->alice)
            ->postJson(route('notifications.device.test'), ['endpoint' => $courant->endpoint])
            ->assertOk()
            ->assertExactJson(['resultat' => 'envoye', 'message' => __('push.test_sent')]);

        $this->assertSame([$courant->endpoint], $vus);
        $this->assertNotContains($autre->endpoint, $vus);
    }

    public function test_un_2xx_n_est_jamais_presente_comme_une_reception(): void
    {
        foreach (['fr', 'en'] as $langue) {
            $texte = mb_strtolower(trans('push.test_sent', [], $langue));

            $this->assertStringNotContainsString('reçue', $texte);
            $this->assertStringNotContainsString('received', $texte);
        }

        $this->assertStringContainsString('Vérifiez sa réception', trans('push.test_sent', [], 'fr'));
    }

    public function test_l_endpoint_d_un_AUTRE_compte_ne_designe_rien(): void
    {
        $deBob = $this->appareil($this->bob);
        Http::fake(['*' => Http::response('', 201)]);

        $this->actingAs($this->alice)
            ->postJson(route('notifications.device.test'), ['endpoint' => $deBob->endpoint])
            ->assertNotFound()
            ->assertJson(['resultat' => 'inconnu']);

        Http::assertNothingSent();
        $this->assertNotNull($deBob->fresh());
    }

    public function test_un_abonnement_d_un_AUTRE_environnement_n_est_pas_envoyable(): void
    {
        $importe = $this->appareil($this->alice);
        $importe->forceFill(['created_env' => 'production-importee'])->save();
        Http::fake(['*' => Http::response('', 201)]);

        $this->actingAs($this->alice)
            ->postJson(route('notifications.device.test'), ['endpoint' => $importe->endpoint])
            ->assertNotFound()
            ->assertJson(['resultat' => 'inconnu']);

        Http::assertNothingSent();
    }

    public function test_un_abonnement_a_la_clef_VAPID_perimee_n_est_pas_envoyable(): void
    {
        $perime = $this->appareil($this->alice);
        $perime->forceFill(['vapid_key_hash' => str_repeat('0', 64)])->save();
        Http::fake(['*' => Http::response('', 201)]);

        $this->actingAs($this->alice)
            ->postJson(route('notifications.device.test'), ['endpoint' => $perime->endpoint])
            ->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_une_revocation_retire_la_ligne_et_le_dit(): void
    {
        $courant = $this->appareil($this->alice);
        Http::fake(['*' => Http::response('', 410)]);

        $this->actingAs($this->alice)
            ->postJson(route('notifications.device.test'), ['endpoint' => $courant->endpoint])
            ->assertOk()
            ->assertJson(['resultat' => 'revoque', 'message' => __('push.test_revoked')]);

        $this->assertNull($courant->fresh());
    }

    public function test_un_refus_du_fournisseur_est_un_echec_sans_detail_brut_hors_local(): void
    {
        $courant = $this->appareil($this->alice);
        Http::fake(['*' => Http::response('boom', 500)]);

        $reponse = $this->actingAs($this->alice)
            ->postJson(route('notifications.device.test'), ['endpoint' => $courant->endpoint])
            ->assertOk()
            ->assertJson(['resultat' => 'echec', 'message' => __('push.test_failed')]);

        $this->assertArrayNotHasKey('detail', $reponse->json());
        $this->assertNotNull($courant->fresh(), 'un echec ne supprime pas l abonnement');
    }

    public function test_le_test_est_borne_a_trois_essais_par_minute(): void
    {
        $courant = $this->appareil($this->alice);
        Http::fake(['*' => Http::response('', 201)]);

        foreach (range(1, 3) as $essai) {
            $this->actingAs($this->alice)
                ->postJson(route('notifications.device.test'), ['endpoint' => $courant->endpoint])
                ->assertOk();
        }

        $this->actingAs($this->alice)
            ->postJson(route('notifications.device.test'), ['endpoint' => $courant->endpoint])
            ->assertStatus(429);

        Http::assertSentCount(3);
    }

    public function test_push_coupe_aucun_envoi(): void
    {
        $courant = $this->appareil($this->alice);
        config()->set('webpush.enabled', false);
        Http::fake(['*' => Http::response('', 201)]);

        $this->actingAs($this->alice)
            ->postJson(route('notifications.device.test'), ['endpoint' => $courant->endpoint])
            ->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_le_test_fonctionne_en_forme_Organization(): void
    {
        $courant = $this->appareil($this->alice);
        Http::fake(['*' => Http::response('', 201)]);

        $this->actingAs($this->alice)
            ->postJson(route('organization.notifications.device.test', ['organization' => $this->org->slug]), [
                'endpoint' => $courant->endpoint,
            ])
            ->assertOk()
            ->assertJson(['resultat' => 'envoye']);

        Http::assertSentCount(1);
    }

    // ── R1 — un abonnement revoque ne revit pas cote navigateur ─────────────

    /**
     * Le comportement navigateur ne se joue pas en PHP ; ces gardes de FORME
     * (idiome T1768) verifient que les trois chemins qui pourraient
     * reconfirmer un abonnement consultent d'abord le marqueur de revocation.
     */
    public function test_les_trois_reconciliations_consultent_le_marqueur_de_revocation(): void
    {
        $bibliotheque = (string) file_get_contents(resource_path('js/push-abonnement.js'));
        $device = (string) file_get_contents(resource_path('js/push-device.js'));
        $premier = (string) file_get_contents(resource_path('js/push-first-run.js'));

        // Le marqueur est pose AVANT l'oubli, et retire seulement s'il a reussi.
        $oubli = $this->corpsDe($bibliotheque, 'export const oublierAbonnementRevoque');
        $this->assertLessThan(strpos($oubli, 'unsubscribe()'), strpos($oubli, 'localStorage.setItem'));
        $this->assertLessThan(strpos($oubli, 'localStorage.removeItem'), strpos($oubli, 'unsubscribe()'));
        $this->assertStringContainsString('if (oublie)', $oubli);

        // abonner() — n'est jamais rendu un abonnement revoque.
        $abonner = $this->corpsDe($bibliotheque, 'export const abonner');
        $this->assertLessThan(strpos($abonner, 'return confirmer('), strpos($abonner, 'estRevoque(existant)'));

        // Page appareil — la reconciliation teste la revocation AVANT de reconfirmer.
        $rafraichir = $this->corpsDe($device, 'const rafraichir');
        $this->assertLessThan(strpos($rafraichir, 'await confirmer(abonnement)'), strpos($rafraichir, 'estRevoque(abonnement)'));

        // Page appareil — la reponse `revoque` oublie l'abonnement navigateur.
        $test = substr($device, (int) strpos($device, "corps.resultat === 'revoque'"), 600);
        $this->assertStringContainsString('oublierAbonnementRevoque(abonnement)', $test);
        $this->assertLessThan(strpos($test, "peindre('off'"), strpos($test, 'oublierAbonnementRevoque(abonnement)'));
        // Un oubli rate ne repeint JAMAIS « activees ».
        $this->assertStringContainsString('textTestRevokedCleanupFailed', $test);

        // Premier lancement — la regle 4 (reconfirmation silencieuse) vient APRES.
        $this->assertLessThan(
            strpos($premier, 'await confirmer(url, abonnement'),
            strpos($premier, 'estRevoque(abonnement)'),
        );
    }

    // ── Outils ───────────────────────────────────────────────────────────────

    private function corpsDe(string $source, string $debut): string
    {
        $position = strpos($source, $debut);
        $this->assertNotFalse($position, "introuvable : {$debut}");

        return substr($source, $position, 3000);
    }

    private function b64(string $binaire): string
    {
        return rtrim(strtr(base64_encode($binaire), '+/', '-_'), '=');
    }

    private function clePubliqueEc(): array
    {
        $paire = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $detail = openssl_pkey_get_details($paire);

        return [
            "\x04".str_pad($detail['ec']['x'], 32, "\0", STR_PAD_LEFT).str_pad($detail['ec']['y'], 32, "\0", STR_PAD_LEFT),
            str_pad($detail['ec']['d'], 32, "\0", STR_PAD_LEFT),
        ];
    }

    /** La paire VAPID du SERVEUR, posee une seule fois (lecon T1740). */
    private function poserPaireVapid(): void
    {
        [$publique, $privee] = $this->clePubliqueEc();

        config()->set('webpush.vapid.public_key', $this->b64($publique));
        config()->set('webpush.vapid.private_key', $this->b64($privee));
    }

    /** Un abonnement REEL (p256dh valide) : un test qui ne chiffre pas ne mesure rien. */
    private function appareil(User $proprietaire): PushSubscription
    {
        [$publique] = $this->clePubliqueEc();

        return app(PushSubscriptionStore::class)->revendiquer(
            $proprietaire,
            'https://fcm.googleapis.com/fcm/send/t1877-'.bin2hex(random_bytes(6)),
            $this->b64($publique),
            $this->b64(random_bytes(16)),
            'Android',
        );
    }
}
