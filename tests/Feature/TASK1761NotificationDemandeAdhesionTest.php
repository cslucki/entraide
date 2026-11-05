<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\MemberNotification;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopService;
use App\Support\Notifications\NotificationCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1761 point 1 — « quand un utilisateur souhaite rejoindre une boucle, il
 * faut que le propriétaire reçoive une notification dans l'application et par
 * email ».
 *
 * ## Le trou
 *
 * `LoopService::requestToJoin()` ecrivait la `LoopJoinRequest` et **n'emettait
 * RIEN**. Le catalogue portait `loop.join_request.accepted` et `.rejected` — la
 * DECISION, qui va au demandeur — mais rien pour la DEMANDE, qui doit aller au
 * PROPRIETAIRE. C'est exactement la forme de T1751, ou
 * `TransactionController::store()` n'emettait rien non plus.
 *
 * ## Ce que ce fichier garde
 *
 * Le destinataire. C'est le defaut le plus probable et le plus silencieux : la
 * notification partirait, simplement a la mauvaise personne.
 */
class TASK1761NotificationDemandeAdhesionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Loop $boucle;

    private User $proprietaire;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1761-n', 'is_active' => true, 'locale' => 'fr',
        ]);
        app()->instance('current_organization', $this->organization);

        $this->proprietaire = User::factory()->create(['organization_id' => $this->organization->id]);

        $this->boucle = Loop::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by' => $this->proprietaire->id,
            'visibility' => 'private',
        ]);

        LoopMember::create([
            'loop_id' => $this->boucle->id,
            'user_id' => $this->proprietaire->id,
            'role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
            'organization_id' => $this->organization->id,
        ]);
    }

    private function demander(User $demandeur): void
    {
        app(LoopService::class)->requestToJoin($this->boucle, $demandeur, 'Je voudrais participer.');
    }

    private function notificationsDe(User $u)
    {
        return MemberNotification::query()->withoutGlobalScopes()
            ->where('recipient_id', $u->id)
            ->where('notification_key', NotificationCatalogue::LOOP_JOIN_REQUEST_CREATED)
            ->get();
    }

    /** 🔴 LE DESTINATAIRE : le proprietaire, pas le demandeur. */
    public function test_le_proprietaire_est_prevenu_et_le_demandeur_ne_l_est_pas(): void
    {
        $demandeur = User::factory()->create(['organization_id' => $this->organization->id]);

        $this->demander($demandeur);

        $this->assertCount(1, $this->notificationsDe($this->proprietaire),
            'Le proprietaire n\'a pas ete prevenu de la demande.');

        $this->assertCount(0, $this->notificationsDe($demandeur),
            'Le DEMANDEUR a ete notifie de sa propre demande.');
    }

    /**
     * 🔴 TOUS les proprietaires, pas « le » proprietaire.
     *
     * `Loop::owners()` est un `HasMany` : une Boucle peut en avoir plusieurs —
     * T1693 bug O a livre le transfert de propriete, qui passe par un instant a
     * deux. N'en prevenir qu'un laisserait la demande invisible pour l'autre,
     * et le choix de celui qu'on previent serait arbitraire.
     */
    public function test_tous_les_proprietaires_sont_prevenus(): void
    {
        $second = User::factory()->create(['organization_id' => $this->organization->id]);

        LoopMember::create([
            'loop_id' => $this->boucle->id,
            'user_id' => $second->id,
            'role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
            'organization_id' => $this->organization->id,
        ]);

        $this->demander(User::factory()->create(['organization_id' => $this->organization->id]));

        $this->assertCount(1, $this->notificationsDe($this->proprietaire));
        $this->assertCount(1, $this->notificationsDe($second),
            'Le second proprietaire n\'a rien recu : la demande lui serait invisible.');
    }

    /** Un simple MEMBRE n'est pas prevenu — seuls les proprietaires decident. */
    public function test_un_membre_ordinaire_de_la_boucle_n_est_pas_prevenu(): void
    {
        $membre = User::factory()->create(['organization_id' => $this->organization->id]);

        LoopMember::create([
            'loop_id' => $this->boucle->id,
            'user_id' => $membre->id,
            'role' => 'member',
            'status' => 'active',
            'joined_at' => now(),
            'organization_id' => $this->organization->id,
        ]);

        $this->demander(User::factory()->create(['organization_id' => $this->organization->id]));

        $this->assertCount(0, $this->notificationsDe($membre));
    }

    /**
     * 🔴 L'EMAIL est declare, actif par defaut — c'est la demande explicite.
     *
     * « une notification dans l'application ET par email ». Sans ce canal au
     * catalogue, la notification existerait en base et personne ne recevrait
     * rien dans sa boite.
     */
    public function test_les_deux_canaux_demandes_sont_declares(): void
    {
        $canaux = NotificationCatalogue::channelsFor(NotificationCatalogue::LOOP_JOIN_REQUEST_CREATED);

        $this->assertContains(NotificationCatalogue::CHANNEL_IN_APP, $canaux);
        $this->assertContains(NotificationCatalogue::CHANNEL_EMAIL, $canaux);

        $this->assertTrue(NotificationCatalogue::channelDefault(
            NotificationCatalogue::LOOP_JOIN_REQUEST_CREATED, NotificationCatalogue::CHANNEL_EMAIL
        ), 'L\'email doit etre ACTIF par defaut : c\'est la demande explicite.');

        // L'avis dans l'application ne se coupe pas : une demande attend une
        // decision, et se taire laisserait quelqu'un a la porte.
        $this->assertFalse(NotificationCatalogue::channelIsConfigurable(
            NotificationCatalogue::LOOP_JOIN_REQUEST_CREATED, NotificationCatalogue::CHANNEL_IN_APP
        ));
    }

    /**
     * Le proprietaire n'est pas SPAMME — et la garde est en AMONT.
     *
     * Ma premiere version de ce test rejouait `requestToJoin()` et attendait
     * que l'identifiant d'evenement deterministe absorbe le doublon. Le service
     * l'a refuse : une demande en attente existe deja, il leve. **La garde est
     * donc posee plus tot que je ne le croyais**, et elle est meilleure — rien
     * n'est ecrit du tout, au lieu d'ecrire puis de dedupliquer.
     *
     * Ce test fixe ce comportement reel : une seconde demande est refusee, donc
     * le proprietaire ne recoit qu'un avis.
     */
    public function test_une_seconde_demande_en_attente_est_refusee_et_n_avertit_pas_deux_fois(): void
    {
        $demandeur = User::factory()->create(['organization_id' => $this->organization->id]);

        $this->demander($demandeur);
        $this->assertCount(1, $this->notificationsDe($this->proprietaire));

        $this->expectException(\RuntimeException::class);

        try {
            $this->demander($demandeur);
        } finally {
            // Meme apres la levee : toujours UN seul avis.
            $this->assertCount(1, $this->notificationsDe($this->proprietaire),
                'Une demande refusee a quand meme notifie le proprietaire.');
        }
    }
}
