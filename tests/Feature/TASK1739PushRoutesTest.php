<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1739 — les routes d'abonnement, et l'ecran qui les appelle.
 *
 * Ce qui est defendu ici : un anonyme n'approche pas, personne ne desabonne
 * l'appareil d'un autre, une entree malformee n'entre pas en base, et l'ecran
 * ne promet pas une reception qui n'est pas livree.
 */
class TASK1739PushRoutesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $alice;

    private User $bob;

    private const ENDPOINT = 'https://fcm.googleapis.com/fcm/send/cEgRtYu-Xw4:APA91bHq_exemple';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('webpush.enabled', true);
        config()->set('webpush.vapid.public_key', str_repeat('B', 87));
        config()->set('webpush.vapid.private_key', str_repeat('p', 43));

        $this->org = Organization::factory()->create(['is_active' => true]);
        $this->alice = User::factory()->create(['organization_id' => $this->org->id]);
        $this->bob = User::factory()->create(['organization_id' => $this->org->id]);
    }

    /** @return array<string, mixed> */
    private function charge(?string $endpoint = null): array
    {
        return [
            'endpoint' => $endpoint ?? self::ENDPOINT,
            'keys' => ['p256dh' => 'cle-p256dh', 'auth' => 'cle-auth'],
        ];
    }

    // ── Acces ────────────────────────────────────────────────────────────────

    public function test_un_anonyme_n_approche_aucune_des_trois_routes(): void
    {
        $this->postJson(route('notifications.push.store'), $this->charge())->assertUnauthorized();
        $this->deleteJson(route('notifications.push.destroy'), ['endpoint' => self::ENDPOINT])->assertUnauthorized();
        $this->getJson(route('notifications.push.show'))->assertUnauthorized();

        $this->assertSame(0, PushSubscription::count());
    }

    // ── Abonnement ───────────────────────────────────────────────────────────

    public function test_un_membre_abonne_son_appareil(): void
    {
        $reponse = $this->actingAs($this->alice)
            ->postJson(route('notifications.push.store'), $this->charge() + ['device_label' => 'Android']);

        $reponse->assertOk()->assertJson(['subscribed' => true, 'device_count' => 1]);

        $abonnement = PushSubscription::sole();
        $this->assertSame($this->alice->id, $abonnement->user_id);
        $this->assertSame('Android', $abonnement->device_label);
        $this->assertNotNull($abonnement->last_seen_at);
    }

    public function test_le_proprietaire_vient_de_la_session_jamais_de_la_requete(): void
    {
        // Tentative d'abonner l'appareil au nom de quelqu'un d'autre.
        $this->actingAs($this->alice)
            ->postJson(route('notifications.push.store'), $this->charge() + ['user_id' => $this->bob->id])
            ->assertOk();

        $this->assertSame($this->alice->id, PushSubscription::sole()->user_id);
    }

    public function test_un_endpoint_non_https_est_refuse(): void
    {
        foreach (['http://exemple.test/push', 'javascript:alert(1)', 'pas-une-url', ''] as $mauvais) {
            $this->actingAs($this->alice)
                ->postJson(route('notifications.push.store'), $this->charge($mauvais))
                ->assertStatus(422);
        }

        $this->assertSame(0, PushSubscription::count());
    }

    public function test_des_clefs_manquantes_sont_refusees(): void
    {
        $this->actingAs($this->alice)
            ->postJson(route('notifications.push.store'), ['endpoint' => self::ENDPOINT])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['keys.p256dh', 'keys.auth']);

        $this->assertSame(0, PushSubscription::count());
    }

    public function test_un_libelle_d_appareil_trop_long_est_refuse(): void
    {
        $this->actingAs($this->alice)
            ->postJson(route('notifications.push.store'), $this->charge() + ['device_label' => str_repeat('x', 61)])
            ->assertStatus(422);
    }

    public function test_abonner_deux_fois_reste_un_seul_appareil(): void
    {
        // La reconciliation appelle cette route a chaque affichage de l'ecran.
        $this->actingAs($this->alice)->postJson(route('notifications.push.store'), $this->charge())->assertOk();
        $this->actingAs($this->alice)->postJson(route('notifications.push.store'), $this->charge())->assertOk();

        $this->assertSame(1, PushSubscription::count());
    }

    public function test_la_route_est_fermee_quand_le_push_est_eteint(): void
    {
        // Interrupteur d'exploitation : on peut retirer le Push de l'interface
        // sans toucher aux clefs.
        config()->set('webpush.enabled', false);

        $this->actingAs($this->alice)
            ->postJson(route('notifications.push.store'), $this->charge())
            ->assertNotFound();

        $this->assertSame(0, PushSubscription::count());
    }

    // ── Revocation ───────────────────────────────────────────────────────────

    public function test_un_membre_desabonne_son_appareil(): void
    {
        $this->actingAs($this->alice)->postJson(route('notifications.push.store'), $this->charge());

        $this->actingAs($this->alice)
            ->deleteJson(route('notifications.push.destroy'), ['endpoint' => self::ENDPOINT])
            ->assertOk()
            ->assertJson(['subscribed' => false, 'removed' => 1, 'device_count' => 0]);

        $this->assertSame(0, PushSubscription::count());
    }

    public function test_personne_ne_desabonne_l_appareil_d_un_autre(): void
    {
        // Un endpoint n'est pas un secret : il voyage dans les requetes.
        $this->actingAs($this->alice)->postJson(route('notifications.push.store'), $this->charge());

        $this->actingAs($this->bob)
            ->deleteJson(route('notifications.push.destroy'), ['endpoint' => self::ENDPOINT])
            ->assertOk()
            ->assertJson(['removed' => 0]);

        $this->assertSame(1, PushSubscription::count());
        $this->assertSame($this->alice->id, PushSubscription::sole()->user_id);
    }

    // ── Etat ─────────────────────────────────────────────────────────────────

    public function test_l_etat_compose_les_deux_moities(): void
    {
        $this->actingAs($this->alice)->postJson(route('notifications.push.store'), $this->charge());

        // Cet endpoint-ci est a Alice.
        $this->actingAs($this->alice)
            ->getJson(route('notifications.push.show', ['endpoint' => self::ENDPOINT]))
            ->assertOk()->assertJson(['enabled' => true, 'subscribed' => true, 'device_count' => 1]);

        // Le MEME endpoint n'est pas a Bob — c'est ce qui permet a l'ecran
        // d'etre honnete apres une bascule de compte.
        $this->actingAs($this->bob)
            ->getJson(route('notifications.push.show', ['endpoint' => self::ENDPOINT]))
            ->assertOk()->assertJson(['subscribed' => false, 'device_count' => 0]);
    }

    // ── L'ecran ──────────────────────────────────────────────────────────────

    public function test_l_ecran_porte_le_bloc_et_la_clef_PUBLIQUE_seulement(): void
    {
        $html = $this->actingAs($this->alice)->get(route('notifications.preferences.edit'))
            ->assertOk()->getContent();

        $this->assertStringContainsString('data-push-device', $html);
        $this->assertStringContainsString(str_repeat('B', 87), $html, 'la clef publique doit etre servie');

        // LA garde qui compte : la clef PRIVEE ne doit jamais atteindre le
        // navigateur. Une divulgation permettrait d'emettre sous notre
        // identite VAPID.
        $this->assertStringNotContainsString(str_repeat('p', 43), $html);
    }

    /**
     * TASK-1744 — PORTE au nouveau contrat, meme intention.
     *
     * CDC 3.2 : ne pas promettre une capacite qui n'est pas operationnelle.
     * T1739 l'honorait en affichant « l'envoi sera active prochainement » :
     * c'etait vrai, aucun canal `web_push` n'existait au catalogue.
     *
     * T1744 ouvre le canal, et cette phrase est devenue l'INVERSE de la
     * verite — elle a coute la premiere recette Android, en disant a la
     * personne qui venait d'activer son appareil qu'elle ne recevrait rien,
     * juste au-dessus des cases qu'il fallait cocher.
     *
     * L'exigence ne change pas, sa formulation si : l'ecran doit dire ce qui
     * est vrai POUR CETTE PERSONNE. Alice n'a coche aucun evenement, elle
     * doit donc lire qu'elle ne recevra rien ET ce qu'il faut faire.
     */
    public function test_l_ecran_dit_la_verite_sur_ce_que_la_personne_recevra(): void
    {
        $html = $this->actingAs($this->alice)->get(route('notifications.preferences.edit'))
            ->assertOk()->getContent();

        // TASK-1744, arbitrage du 03/11 : les quatre cles sont ON par defaut.
        // Alice n'a donc rien a cocher, et l'ecran doit lui dire ce qu'elle
        // recevra plutot que ce qui lui manque. L'exigence est inchangee —
        // dire ce qui est VRAI pour cette personne-la — seule la reponse a
        // change avec le defaut produit.
        $this->assertStringContainsString(__('push.ready_some'), $html);

        // Et la promesse morte ne doit subsister nulle part.
        $this->assertStringNotContainsString('sera activé prochainement', $html);
    }

    /**
     * TASK-1744 — la premisse de `test_le_catalogue_n_a_PAS_ete_touche` est
     * morte, et ce test ne pouvait DE TOUTE FACON pas echouer.
     *
     * Il ecrivait `array_keys(NotificationCatalogue::channelsFor($cle))`.
     * Mais `channelsFor()` rend DEJA la liste des noms de canaux : le second
     * `array_keys()` la transformait en `[0, 1]`, et l'assertion comparait
     * donc `'web_push'` a des entiers. Elle serait restee verte meme si
     * T1739 avait declare le canal — c'est-a-dire exactement dans le cas
     * qu'elle existait pour interdire.
     *
     * Le perimetre de T1739 est aujourd'hui un fait d'HISTOIRE, pas une
     * contrainte : T1744 declare le canal a dessein. Ce qui reste a defendre
     * est la raison qui justifiait l'interdiction — qu'un canal ne mette en
     * file QUE son propre job — et cette garde vit dans
     * `TASK1740WebPushChannelTest::test_un_canal_ne_met_en_file_que_SON_job`.
     *
     * On garde donc une mesure ici, mais ECRITE CORRECTEMENT, et sur ce qui
     * est vrai maintenant.
     */
    public function test_le_canal_est_desormais_declare_et_l_assertion_le_voit(): void
    {
        $canaux = \App\Support\Notifications\NotificationCatalogue::channelsFor(
            \App\Support\Notifications\NotificationCatalogue::LOOP_INVITATION
        );

        // Sans le `array_keys()` superflu : on compare des chaines a des
        // chaines. C'est la forme que l'ancienne assertion aurait du avoir.
        $this->assertContains('web_push', $canaux);
        $this->assertContains('in_app', $canaux);
    }

    // ── L'ACCES a l'ecran, qui manquait ────────────────────────────────────

    public function test_le_centre_de_notifications_mene_aux_reglages(): void
    {
        // Defaut trouve par Cyril en recette le 01/11 : l'ecran de reglages
        // existait depuis T1375 mais AUCUNE vue ne pointait dessus — ni le
        // menu avatar, ni ce centre, ni la barre laterale. Il n'etait
        // atteignable qu'en tapant son URL, alors que la page de reglages
        // porte un lien de RETOUR vers le centre : le chemin n'existait que
        // dans un sens.
        //
        // Sans ce lien, le bloc « Notifications sur cet appareil » est
        // inatteignable, donc la fonctionnalite n'existe pas pour la personne.
        $html = $this->actingAs($this->alice)->get(route('notifications.index'))
            ->assertOk()->getContent();

        $this->assertStringContainsString('data-notifications-preferences', $html);
        $this->assertStringContainsString(route('notifications.preferences.edit'), $html);
    }

    public function test_l_acces_aux_reglages_ne_depend_pas_des_non_lues(): void
    {
        // Le bouton voisin « Tout marquer comme lu » dispara\u00eet quand tout est
        // lu. Accrocher les reglages a la meme condition les ferait
        // dispara\u00eetre precisement quand on vient les regler.
        $this->assertSame(0, $this->alice->unreadNotificationsCount((string) $this->org->id));

        $this->actingAs($this->alice)->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('data-notifications-preferences', false);
    }
}
