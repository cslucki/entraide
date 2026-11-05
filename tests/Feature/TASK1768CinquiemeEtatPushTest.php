<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1768 — « Non prises en charge par ce navigateur » accusait le NAVIGATEUR
 * d'une panne de SERVEUR.
 *
 * Le bloc « Notifications sur cet appareil » n'avait que quatre etats. Quand le
 * produit avait le Push eteint — interrupteur a faux ou clef VAPID absente —
 * l'ecran rendait l'etat « non prises en charge », le meme que pour un
 * navigateur reellement incapable. Mesure du 05/11/2026 en PRODUCTION : tous
 * les navigateurs voyaient ce message, Chrome de bureau compris, et le
 * diagnostic est parti chercher une panne iOS qui n'existait pas.
 *
 * Ce qui est defendu ici : les deux causes ont deux messages, l'ecran porte de
 * quoi les rendre toutes les deux, et les deux textes ne se confondent pas.
 */
class TASK1768CinquiemeEtatPushTest extends TestCase
{
    use RefreshDatabase;

    private User $membre;

    protected function setUp(): void
    {
        parent::setUp();

        $organization = Organization::factory()->create(['is_active' => true]);
        $this->membre = User::factory()->create(['organization_id' => $organization->id]);
    }

    /**
     * Le Push ETEINT cote serveur : l'ecran doit pouvoir dire « indisponible »,
     * et il doit annoncer au script que le produit ne le propose pas.
     */
    public function test_le_bloc_porte_le_cinquieme_etat_quand_le_serveur_a_le_push_eteint(): void
    {
        config()->set('webpush.enabled', false);
        config()->set('webpush.vapid.public_key', null);

        $reponse = $this->actingAs($this->membre)->get(route('notifications.preferences.edit'));

        $reponse->assertOk();
        $reponse->assertSee('data-push-enabled="0"', false);
        $reponse->assertSee('data-text-unavailable="'.__('push.state_unavailable').'"', false);
        $reponse->assertSee('data-text-unavailable-help="', false);
    }

    /**
     * Le Push ALLUME : les textes restent servis. Le script choisit l'etat, pas
     * le serveur — lui ne fait que fournir les libelles traduits.
     */
    public function test_le_bloc_porte_le_cinquieme_etat_meme_quand_le_push_est_allume(): void
    {
        config()->set('webpush.enabled', true);
        config()->set('webpush.vapid.public_key', str_repeat('B', 87));

        $reponse = $this->actingAs($this->membre)->get(route('notifications.preferences.edit'));

        $reponse->assertOk();
        $reponse->assertSee('data-push-enabled="1"', false);
        $reponse->assertSee('data-text-unavailable="'.__('push.state_unavailable').'"', false);
        $reponse->assertSee('data-text-unsupported="'.__('push.state_unsupported').'"', false);
    }

    /**
     * Deux etats qui diraient la meme chose ne distingueraient rien : c'est la
     * CONFUSION des deux causes que cette TASK corrige, pas leur nombre.
     */
    public function test_les_deux_messages_ne_se_confondent_pas(): void
    {
        foreach (['fr', 'en'] as $langue) {
            app()->setLocale($langue);

            $this->assertNotSame(
                __('push.state_unsupported'),
                __('push.state_unavailable'),
                "Les deux etats disent la meme chose en [$langue]."
            );

            $this->assertNotSame(
                __('push.unsupported_help'),
                __('push.unavailable_help'),
                "Les deux aides disent la meme chose en [$langue]."
            );

            // Une cle absente se rend par son propre nom : c'est le symptome a
            // refuser, parce qu'il s'afficherait tel quel devant un membre.
            $this->assertNotSame('push.state_unavailable', __('push.state_unavailable'));
            $this->assertNotSame('push.unavailable_help', __('push.unavailable_help'));
        }
    }

    /**
     * Le navigateur et le produit sont deux questions distinctes, et la seconde
     * ne doit plus pouvoir se faire passer pour la premiere : `supporte()`
     * REUTILISE `supporteNavigateur()` au lieu de reecrire la condition.
     * Un test qui re-derive la condition finit par diverger d'elle (T1693).
     */
    public function test_la_capacite_du_navigateur_se_lit_a_un_seul_endroit(): void
    {
        $source = file_get_contents(resource_path('js/push-abonnement.js'));

        $this->assertStringContainsString('export const supporteNavigateur = () =>', $source);
        $this->assertStringContainsString(
            'export const supporte = (actif, vapid) => actif && vapid !== \'\' && supporteNavigateur();',
            $source,
            'supporte() doit composer supporteNavigateur(), jamais recopier ses trois conditions.'
        );

        $device = file_get_contents(resource_path('js/push-device.js'));

        $this->assertStringContainsString(
            "peindre(supporteNavigateur() ? 'unavailable' : 'unsupported');",
            $device
        );
    }
}
