<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1745 (recette Cyril, 03/11) — retirer UN appareil Push depuis
 * l'outil d'administration.
 *
 * ## Pourquoi cette action existe
 *
 * Recetter le parcours d'abonnement demande de pouvoir revenir à l'état
 * « aucun appareil ». Il fallait jusqu'ici passer par `tinker`, ce qui n'est
 * pas un parcours.
 *
 * ## Ce qu'elle ne fait PAS, et c'est le point délicat
 *
 * Elle retire la LIGNE SERVEUR. Elle ne désabonne pas le navigateur :
 * `pushManager` garde son abonnement, et le prochain affichage de l'écran
 * des réglages le reconfirmera — c'est la réconciliation de T1739, et elle
 * est voulue.
 *
 * Aucune mécanique T1739 n'est dupliquée : ni endpoint fabriqué, ni
 * désabonnement simulé. Le serveur **ne sait pas** désabonner un navigateur,
 * et prétendre le contraire créerait un état qu'aucune des deux moitiés ne
 * pourrait expliquer. L'écran dit donc l'ordre des gestes plutôt que de le
 * supposer connu.
 */
class TASK1745ForgetPushDeviceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $recette;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('webpush.enabled', true);

        // L'action est reservee a `local` — meme garde que le panneau de
        // diagnostic de T1740, et pour la meme raison : c'est un outil de
        // recette, pas une fonctionnalite.
        //
        // Le banc tourne en `testing` (`bin/test`). On declare donc
        // l'environnement plutot que d'assouplir la garde en « hors
        // production » : une garde elargie pour le confort des tests ne
        // garde plus ce qu'elle dit garder, et c'est l'environnement de
        // RECETTE qui compte ici, pas celui du banc.
        app()->detectEnvironment(fn () => 'local');

        $this->org = Organization::factory()->create(['is_active' => true]);
        $this->recette = User::factory()->create([
            'organization_id' => $this->org->id,
            'email' => 't1745.recette@bouclepro.test',
        ]);
    }

    public function test_un_administrateur_retire_un_appareil_de_recette(): void
    {
        $appareil = $this->appareil($this->recette);

        $this->actingAs($this->admin())
            ->post(route('admin.outils.test-notification.forget'), [
                'user' => $this->recette->id,
                'subscription' => $appareil->id,
            ])
            ->assertRedirect();

        $this->assertSame(0, PushSubscription::query()->count());
    }

    public function test_un_compte_REEL_ne_peut_pas_etre_vide_de_ses_appareils(): void
    {
        // LA garde. Le jumeau `bouclepro_prod_work` porte de VRAIES adresses :
        // retirer l'appareil de quelqu'un depuis un environnement de travail
        // le priverait de ses notifications sans qu'il l'ait demandé.
        //
        // La politique est celle de l'envoi (`PushTestPolicy`), pas une
        // seconde copie : deux règles de sécurité écrites deux fois
        // divergent, et c'est la plus laxiste qui gagne.
        $reel = User::factory()->create([
            'organization_id' => $this->org->id,
            'email' => 'personne.reelle@gmail.com',
        ]);
        $appareil = $this->appareil($reel);

        $this->actingAs($this->admin())
            ->post(route('admin.outils.test-notification.forget'), [
                'user' => $reel->id,
                'subscription' => $appareil->id,
            ])
            ->assertRedirect();

        $this->assertSame(1, PushSubscription::query()->count(), "l'appareil d'un compte réel reste");
    }

    public function test_on_ne_retire_pas_l_appareil_d_un_AUTRE_compte(): void
    {
        // L'identifiant reçu ne fait pas autorité : la requête part de la
        // politique, bornée au propriétaire déclaré. Croiser les deux champs
        // ne doit atteindre personne.
        $autre = User::factory()->create([
            'organization_id' => $this->org->id,
            'email' => 't1745.autre@bouclepro.test',
        ]);
        $appareilDeLAutre = $this->appareil($autre);

        $this->actingAs($this->admin())
            ->post(route('admin.outils.test-notification.forget'), [
                'user' => $this->recette->id,
                'subscription' => $appareilDeLAutre->id,
            ])
            ->assertRedirect();

        $this->assertSame(1, PushSubscription::query()->count());
    }

    public function test_l_action_est_refusee_a_qui_n_est_pas_administrateur(): void
    {
        $appareil = $this->appareil($this->recette);

        $this->actingAs($this->recette)
            ->post(route('admin.outils.test-notification.forget'), [
                'user' => $this->recette->id,
                'subscription' => $appareil->id,
            ])
            ->assertForbidden();

        $this->assertSame(1, PushSubscription::query()->count());
    }

    public function test_l_ecran_DIT_que_le_navigateur_garde_son_abonnement(): void
    {
        // Le piège signalé par Cyril : « supprimer la ligne serveur ne
        // désabonne pas nécessairement le navigateur ». Un outil qui laisse
        // croire l'inverse fabrique une recette fausse — la ligne réapparaît
        // seule, et on cherche un bug qui n'existe pas.
        $this->appareil($this->recette);

        $html = $this->actingAs($this->admin())
            ->get(route('admin.outils.test-notification', ['user' => $this->recette->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-push-forget-help', $html);
        $this->assertStringContainsString(__('admin.push_test_forget_help'), $html);

        // Et la confirmation est un dépliant, pas un `confirm()` natif : une
        // modale du navigateur bloque la page et rend l'écran intestable.
        $this->assertStringContainsString('data-push-forget-confirm', $html);
        $this->assertStringNotContainsString('onclick="return confirm', $html);
    }

    private function admin(): User
    {
        return User::factory()->create([
            'organization_id' => $this->org->id,
            'is_admin' => true,
        ]);
    }

    private function appareil(User $proprietaire): PushSubscription
    {
        return app(\App\Services\Push\PushSubscriptionStore::class)->revendiquer(
            $proprietaire,
            'https://fcm.googleapis.com/fcm/send/t1745-'.bin2hex(random_bytes(6)),
            str_repeat('A', 87),
            str_repeat('B', 22),
            'Android',
        );
    }
}
