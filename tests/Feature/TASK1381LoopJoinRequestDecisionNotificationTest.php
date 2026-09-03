<?php

namespace Tests\Feature;

use App\Livewire\LoopMembersCard;
use App\Models\Loop;
use App\Models\LoopJoinRequest;
use App\Models\LoopMember;
use App\Models\MemberNotification;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopService;
use App\Support\Notifications\NotificationCatalogue;
use App\Support\Notifications\NotificationTargetResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * TASK-1381 — la decision sur une demande d'adhesion previent le demandeur.
 *
 * Ce qui est mesure ici n'est pas « une ligne est ecrite » : c'est que le fait
 * metier reste souverain. Une notification qui echoue ne doit pas defaire une
 * adhesion, ne doit pas se transformer en message d'erreur pour l'animateur, et
 * ne doit pas se dupliquer parce qu'un autre chemin a resolu la meme demande.
 */
class TASK1381LoopJoinRequestDecisionNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // `phpunit.xml` fixe QUEUE_CONNECTION=sync : sans cela, toute livraison
        // planifiee partirait au milieu de l'appel qu'on est en train de
        // mesurer. Aucune cle de cette tranche ne declare EMAIL, mais le test
        // doit le PROUVER, pas le supposer — d'ou la fausse file.
        Queue::fake();
    }

    private function org(): Organization
    {
        return Organization::factory()->create(['loops_enabled' => true]);
    }

    private function withCurrentOrganization(Organization $organization): void
    {
        app()->instance('current_organization', $organization);
    }

    /**
     * Une Boucle a acces sur demande, son animateur, et un demandeur en attente.
     *
     * @return array{0: Organization, 1: Loop, 2: User, 3: User, 4: LoopJoinRequest}
     */
    private function scenario(): array
    {
        $org = $this->org();
        $loop = Loop::factory()->requestAccess()->create(['organization_id' => $org->id]);
        $owner = User::factory()->create(['organization_id' => $org->id]);
        LoopMember::factory()->owner()->create(['loop_id' => $loop->id, 'user_id' => $owner->id]);
        $applicant = User::factory()->create(['organization_id' => $org->id]);
        $joinRequest = LoopJoinRequest::factory()->create([
            'loop_id' => $loop->id,
            'organization_id' => $org->id,
            'user_id' => $applicant->id,
        ]);

        return [$org, $loop, $owner, $applicant, $joinRequest];
    }

    // =====================================================================
    // Le fait nominal
    // =====================================================================

    public function test_accepting_notifies_the_applicant(): void
    {
        [$org, $loop, $owner, $applicant, $joinRequest] = $this->scenario();
        $this->withCurrentOrganization($org);

        $this->actingAs($owner)
            ->post(route('loop-join-requests.accept', $joinRequest))
            ->assertRedirect();

        $notification = MemberNotification::query()
            ->where('recipient_id', $applicant->id)
            ->firstOrFail();

        $this->assertSame(NotificationCatalogue::LOOP_JOIN_REQUEST_ACCEPTED, $notification->notification_key);
        $this->assertSame(NotificationCatalogue::OBJECT_LOOP_JOIN_REQUEST, $notification->object_type);
        $this->assertSame($joinRequest->id, $notification->object_id);
        $this->assertSame($org->id, $notification->organization_id);
        $this->assertSame($owner->id, $notification->actor_id);
    }

    public function test_rejecting_notifies_the_applicant_with_the_other_key(): void
    {
        [$org, $loop, $owner, $applicant, $joinRequest] = $this->scenario();
        $this->withCurrentOrganization($org);

        $this->actingAs($owner)
            ->post(route('loop-join-requests.reject', $joinRequest))
            ->assertRedirect();

        $notification = MemberNotification::query()
            ->where('recipient_id', $applicant->id)
            ->firstOrFail();

        $this->assertSame(NotificationCatalogue::LOOP_JOIN_REQUEST_REJECTED, $notification->notification_key);
    }

    /**
     * Le decideur n'est PAS prevenu — il vient d'agir, il sait.
     */
    public function test_the_decider_receives_nothing(): void
    {
        [$org, $loop, $owner, $applicant, $joinRequest] = $this->scenario();
        $this->withCurrentOrganization($org);

        $this->actingAs($owner)->post(route('loop-join-requests.accept', $joinRequest));

        $this->assertSame(0, MemberNotification::where('recipient_id', $owner->id)->count());
        $this->assertSame(1, MemberNotification::count());
    }

    // =====================================================================
    // Deux faits, deux identites
    // =====================================================================

    /**
     * L'identite de l'evenement derive de LA CLE ET de la demande.
     *
     * Mesure sur UNE SEULE ligne, a dessein. Le test fonctionnel ci-dessous
     * utilise deux demandes distinctes : leurs identifiants differeraient de
     * toute facon, meme si la cle disparaissait de la derivation. Il resterait
     * donc vert pour une raison qui n'est pas celle qu'on croit mesurer.
     *
     * Ici, le seul discriminant possible est la cle.
     */
    public function test_the_two_keys_derive_distinct_event_ids_from_the_same_request(): void
    {
        [$org, $loop, $owner, $applicant, $joinRequest] = $this->scenario();

        $methode = new \ReflectionMethod(LoopService::class, 'identifiantEvenementDecision');

        $this->assertNotSame(
            $methode->invoke(app(LoopService::class), $joinRequest, NotificationCatalogue::LOOP_JOIN_REQUEST_ACCEPTED),
            $methode->invoke(app(LoopService::class), $joinRequest, NotificationCatalogue::LOOP_JOIN_REQUEST_REJECTED),
        );
    }

    /**
     * `accepted` et `rejected` portent le MEME objet et doivent pourtant
     * coexister.
     *
     * Le scenario est reel : la politique autorise une nouvelle demande apres un
     * refus. Si `event_id` derivait de la seule demande, la contrainte
     * `UNIQUE(event_id, recipient_id)` ferait echouer le second fait — ou pire,
     * l'avalerait.
     */
    public function test_accepted_and_rejected_do_not_share_an_event_id(): void
    {
        [$org, $loop, $owner, $applicant, $premiereDemande] = $this->scenario();
        $this->withCurrentOrganization($org);

        $this->actingAs($owner)->post(route('loop-join-requests.reject', $premiereDemande));

        $secondeDemande = LoopJoinRequest::factory()->create([
            'loop_id' => $loop->id,
            'organization_id' => $org->id,
            'user_id' => $applicant->id,
        ]);

        $this->actingAs($owner)
            ->post(route('loop-join-requests.accept', $secondeDemande))
            ->assertRedirect();

        $notifications = MemberNotification::where('recipient_id', $applicant->id)->get();

        $this->assertCount(2, $notifications);
        $this->assertCount(2, $notifications->pluck('event_id')->unique());
        $this->assertEqualsCanonicalizing(
            [
                NotificationCatalogue::LOOP_JOIN_REQUEST_REJECTED,
                NotificationCatalogue::LOOP_JOIN_REQUEST_ACCEPTED,
            ],
            $notifications->pluck('notification_key')->all(),
        );
    }

    /**
     * Rejouer le meme fait ne cree pas une seconde notification.
     *
     * On appelle le SERVICE deux fois, pas la route : la route refuserait au
     * second coup sur `isPending()`, et le test ne mesurerait alors que la
     * machine a etats, pas l'idempotence de l'emission.
     */
    public function test_replaying_the_producer_creates_a_single_notification(): void
    {
        [$org, $loop, $owner, $applicant, $joinRequest] = $this->scenario();
        $service = app(LoopService::class);

        $service->acceptJoinRequest($joinRequest, $owner);

        // Une seconde decision est impossible metier ; on rejoue donc
        // directement l'emission sur le meme fait.
        $service->acceptJoinRequest(
            tap($joinRequest->fresh(), fn ($d) => $d->update(['status' => LoopJoinRequest::STATUS_PENDING])),
            $owner,
        );

        $this->assertSame(1, MemberNotification::where('recipient_id', $applicant->id)->count());
    }

    // =====================================================================
    // Le fait metier reste souverain
    // =====================================================================

    /**
     * LE test central de cette tranche.
     *
     * Le demandeur a quitte l'Organization entre sa demande et la decision. Les
     * invariants du module refusent alors la notification. Si l'emission vivait
     * dans la transaction metier, ce refus annulerait l'adhesion : le membre
     * n'entrerait jamais dans la Boucle, la demande resterait `pending`, et le
     * bouton echouerait indefiniment.
     */
    public function test_a_refused_notification_does_not_undo_the_acceptance(): void
    {
        [$org, $loop, $owner, $applicant, $joinRequest] = $this->scenario();
        $autreOrg = $this->org();
        $this->withCurrentOrganization($org);

        $applicant->forceFill(['organization_id' => $autreOrg->id])->saveQuietly();

        $this->actingAs($owner)
            ->post(route('loop-join-requests.accept', $joinRequest))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('accepted', $joinRequest->fresh()->status);
        $this->assertDatabaseHas('loop_members', [
            'loop_id' => $loop->id,
            'user_id' => $applicant->id,
            'status' => 'active',
        ]);

        // Aucune notification : le destinataire n'appartient plus au tenant.
        // L'absence est le comportement correct, pas un defaut.
        $this->assertSame(0, MemberNotification::count());
    }

    /**
     * Un conflit d'emission ne doit pas se transformer en message d'erreur.
     *
     * `NotificationEmissionConflict` descend de `RuntimeException`, et le
     * controleur attrape `\RuntimeException` pour afficher « cette demande a
     * deja ete tranchee ». Sans le `catch` du producteur, l'animateur verrait
     * une chaine technique anglaise apres une acceptation REUSSIE.
     *
     * Le journal est mesure LUI AUSSI, et c'est ce qui distingue les deux
     * `catch` du producteur. Un rejeu est un flux metier ordinaire : le tracer
     * en avertissement remplirait les journaux de bruit et rendrait le cockpit
     * de supervision aveugle aux vrais incidents, noyes dedans. Sans cette
     * assertion, le `catch` specifique serait mort — celui de `\Throwable`
     * rattraperait le conflit et le test resterait vert sans rien prouver.
     */
    public function test_an_emission_conflict_is_not_shown_as_an_error(): void
    {
        [$org, $loop, $owner, $applicant, $joinRequest] = $this->scenario();
        $this->withCurrentOrganization($org);
        Log::spy();

        // Meme `event_id`, meme destinataire, autre fait : le conflit exact que
        // l'emetteur signale.
        MemberNotification::create([
            'organization_id' => $org->id,
            'recipient_id' => $applicant->id,
            'notification_key' => NotificationCatalogue::LOOP_INVITATION,
            'event_id' => $this->eventIdFor($joinRequest, NotificationCatalogue::LOOP_JOIN_REQUEST_ACCEPTED),
            'object_type' => NotificationCatalogue::OBJECT_LOOP_INVITATION,
            'object_id' => null,
        ]);

        $reponse = $this->actingAs($owner)
            ->post(route('loop-join-requests.accept', $joinRequest))
            ->assertRedirect();

        $reponse->assertSessionMissing('error');
        $this->assertSame('accepted', $joinRequest->fresh()->status);
        $this->assertDatabaseHas('loop_members', [
            'loop_id' => $loop->id,
            'user_id' => $applicant->id,
            'status' => 'active',
        ]);

        Log::shouldNotHaveReceived('warning');
    }

    /**
     * Un echec REEL, lui, laisse une trace.
     *
     * Sans cette contrepartie, « aucun avertissement » se satisferait d'un
     * producteur qui ne journalise jamais rien — et une emission cassee
     * disparaitrait en silence. Les deux `catch` doivent donc se distinguer par
     * la mesure, pas seulement par l'intention.
     */
    public function test_a_real_emission_failure_is_logged(): void
    {
        [$org, $loop, $owner, $applicant, $joinRequest] = $this->scenario();
        $autreOrg = $this->org();
        $this->withCurrentOrganization($org);
        Log::spy();

        $applicant->forceFill(['organization_id' => $autreOrg->id])->saveQuietly();

        $this->actingAs($owner)->post(route('loop-join-requests.accept', $joinRequest));

        Log::shouldHaveReceived('warning')->once();
    }

    // =====================================================================
    // Le chemin que la cartographie initiale avait rate
    // =====================================================================

    /**
     * L'ajout en masse depuis la Card Membres resout de vraies demandes.
     *
     * Il le faisait par un `update()` de query builder — donc sans aucun
     * evenement Eloquent. Pour le demandeur, c'est pourtant exactement le meme
     * fait que depuis l'ecran de decision.
     */
    public function test_bulk_adding_an_applicant_notifies_them_too(): void
    {
        [$org, $loop, $owner, $applicant, $joinRequest] = $this->scenario();
        $this->withCurrentOrganization($org);

        Livewire::actingAs($owner)
            ->test(LoopMembersCard::class, ['loop' => $loop])
            ->set('selected', [$applicant->id])
            ->call('add');

        $this->assertSame('accepted', $joinRequest->fresh()->status);

        $notification = MemberNotification::query()
            ->where('recipient_id', $applicant->id)
            ->firstOrFail();

        $this->assertSame(NotificationCatalogue::LOOP_JOIN_REQUEST_ACCEPTED, $notification->notification_key);
        $this->assertSame($joinRequest->id, $notification->object_id);
    }

    /**
     * Ajouter quelqu'un qui n'avait RIEN demande ne notifie pas.
     *
     * La cle dit « votre demande a ete acceptee ». Sans demande, elle mentirait.
     */
    public function test_bulk_adding_someone_without_a_pending_request_notifies_nobody(): void
    {
        $org = $this->org();
        $loop = Loop::factory()->requestAccess()->create(['organization_id' => $org->id]);
        $owner = User::factory()->create(['organization_id' => $org->id]);
        LoopMember::factory()->owner()->create(['loop_id' => $loop->id, 'user_id' => $owner->id]);
        $nouveau = User::factory()->create(['organization_id' => $org->id]);
        $this->withCurrentOrganization($org);

        Livewire::actingAs($owner)
            ->test(LoopMembersCard::class, ['loop' => $loop])
            ->set('selected', [$nouveau->id])
            ->call('add');

        $this->assertDatabaseHas('loop_members', ['loop_id' => $loop->id, 'user_id' => $nouveau->id]);
        $this->assertSame(0, MemberNotification::count());
    }

    // =====================================================================
    // Ce que le membre lit reellement
    // =====================================================================

    /**
     * Les deux libelles arrivent jusqu'a l'ecran, et ils DIFFERENT.
     *
     * Le Centre resout `keys.<cle avec des tirets bas>`. Une cle mal orthographiee
     * ne casse rien : elle retombe silencieusement sur « Notification », le repli
     * generique. Les deux faits deviendraient alors indistinguables pour le
     * membre — sans qu'aucune erreur ne soit levee nulle part.
     *
     * On mesure donc les DEUX libelles ensemble : verifier une seule ligne
     * laisserait passer une paire identique.
     */
    public function test_the_center_shows_a_distinct_label_for_each_decision(): void
    {
        [$org, $loop, $owner, $applicant, $premiereDemande] = $this->scenario();
        $this->withCurrentOrganization($org);

        $this->actingAs($owner)->post(route('loop-join-requests.reject', $premiereDemande));

        $secondeDemande = LoopJoinRequest::factory()->create([
            'loop_id' => $loop->id,
            'organization_id' => $org->id,
            'user_id' => $applicant->id,
        ]);
        $this->actingAs($owner)->post(route('loop-join-requests.accept', $secondeDemande));

        // Les textes sont ecrits EN DUR, pas relus par `__()`.
        //
        // Les comparer a `__()` reviendrait a interroger le meme oracle que la
        // vue : une cle manquante rendrait la meme chaine des deux cotes et le
        // test resterait vert en constatant sa propre panne. Mesure faite : la
        // version `__()` de ce test ne rougissait PAS avec la cle cassee.
        //
        // La locale est FIXEE sur le destinataire, jamais empruntee a
        // l'environnement. Mesure faite egalement : la locale d'une requete de
        // test est `en`, pas celle de la CLI — supposer l'une pour l'autre
        // aurait teste un fichier de langue au lieu de l'autre.
        $attendus = [
            'fr' => ['Votre demande d’adhésion a été acceptée', 'Votre demande d’adhésion n’a pas été retenue'],
            'en' => ['Your request to join was accepted', 'Your request to join was not accepted'],
        ];

        foreach ($attendus as $locale => [$accepte, $refuse]) {
            $applicant->forceFill(['preferred_locale' => $locale])->saveQuietly();

            $this->actingAs($applicant->fresh())
                ->get(route('notifications.index'))
                ->assertOk()
                ->assertSee($accepte, escape: false)
                ->assertSee($refuse, escape: false);
        }
    }

    // =====================================================================
    // Le lien profond — B1, sans aucun contexte ambiant
    // =====================================================================

    /**
     * Le resolveur tourne SOUS WORKER : ni session, ni Organization courante.
     *
     * Le test le prouve en retirant les deux avant de resoudre. Toute lecture
     * de `auth()`, de `current_organization` ou de `request()->route()` ferait
     * echouer ou devier ce cas.
     */
    public function test_the_deep_link_is_built_without_any_ambient_context(): void
    {
        [$org, $loop, $owner, $applicant, $joinRequest] = $this->scenario();
        $this->withCurrentOrganization($org);

        $this->actingAs($owner)->post(route('loop-join-requests.accept', $joinRequest));

        $notification = MemberNotification::where('recipient_id', $applicant->id)->firstOrFail();

        Auth::logout();
        app()->forgetInstance('current_organization');

        $cible = app(NotificationTargetResolver::class)->resolve($notification);

        $this->assertNotNull($cible);
        $this->assertStringContainsString('/'.$org->slug.'/loops/'.$loop->id, $cible);
    }

    /**
     * Un refus mene a la meme Boucle — et ce n'est pas une fuite.
     *
     * Depuis TASK-1075, « privee » ne veut pas dire cachee : un non-membre y
     * recoit la carte de presentation. C'est la page elle-meme qui adapte ce
     * qu'elle montre ; dupliquer cette regle dans le resolveur creerait une
     * seconde verite.
     */
    public function test_a_rejected_applicant_still_reaches_the_loop_presentation(): void
    {
        [$org, $loop, $owner, $applicant, $joinRequest] = $this->scenario();
        $this->withCurrentOrganization($org);

        $this->actingAs($owner)->post(route('loop-join-requests.reject', $joinRequest));

        $notification = MemberNotification::where('recipient_id', $applicant->id)->firstOrFail();
        $cible = app(NotificationTargetResolver::class)->resolve($notification);

        $this->assertNotNull($cible);

        $this->actingAs($applicant)->get($cible)->assertOk();
    }

    /**
     * Une Boucle archivee ferme la porte a un non-membre : le resolveur le dit.
     */
    public function test_an_archived_loop_makes_the_target_unreachable_for_a_non_member(): void
    {
        [$org, $loop, $owner, $applicant, $joinRequest] = $this->scenario();
        $this->withCurrentOrganization($org);

        $this->actingAs($owner)->post(route('loop-join-requests.reject', $joinRequest));
        $loop->update(['status' => 'archived']);

        $notification = MemberNotification::where('recipient_id', $applicant->id)->firstOrFail();

        $this->assertNull(app(NotificationTargetResolver::class)->resolve($notification));
    }

    /**
     * Un destinataire qui a quitte l'Organization ne recoit plus de lien.
     */
    public function test_a_recipient_who_left_the_organization_gets_no_target(): void
    {
        [$org, $loop, $owner, $applicant, $joinRequest] = $this->scenario();
        $autreOrg = $this->org();
        $this->withCurrentOrganization($org);

        $this->actingAs($owner)->post(route('loop-join-requests.accept', $joinRequest));

        $notification = MemberNotification::where('recipient_id', $applicant->id)->firstOrFail();
        $applicant->forceFill(['organization_id' => $autreOrg->id])->saveQuietly();

        $this->assertNull(app(NotificationTargetResolver::class)->resolve($notification));
    }

    // =====================================================================
    // Aucun email, et rien sur la file `default`
    // =====================================================================

    /**
     * Le catalogue ne declare pas EMAIL sur ces cles : rien ne doit partir.
     *
     * L'absence de la ligne suffit — aucun garde d'appel n'est necessaire, et
     * c'est precisement ce qu'on mesure. Aucun job, sur aucune file.
     */
    public function test_no_email_is_planned_for_these_keys(): void
    {
        [$org, $loop, $owner, $applicant, $joinRequest] = $this->scenario();
        $this->withCurrentOrganization($org);

        $this->actingAs($owner)->post(route('loop-join-requests.accept', $joinRequest));

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('member_notification_deliveries', 0);
        $this->assertFalse(NotificationCatalogue::allowsChannel(
            NotificationCatalogue::LOOP_JOIN_REQUEST_ACCEPTED,
            NotificationCatalogue::CHANNEL_EMAIL,
        ));
        $this->assertFalse(NotificationCatalogue::allowsChannel(
            NotificationCatalogue::LOOP_JOIN_REQUEST_REJECTED,
            NotificationCatalogue::CHANNEL_EMAIL,
        ));
    }

    // =====================================================================
    // Frontiere de tenant
    // =====================================================================

    /**
     * L'Organization inscrite est celle de la DEMANDE, pas celle du contexte.
     *
     * On force volontairement une Organization courante differente : si le
     * producteur lisait l'ambiant plutot que l'objet, la notification changerait
     * de tenant.
     */
    public function test_the_notification_carries_the_tenant_of_the_request(): void
    {
        [$org, $loop, $owner, $applicant, $joinRequest] = $this->scenario();
        $autreOrg = $this->org();
        $this->withCurrentOrganization($autreOrg);

        app(LoopService::class)->acceptJoinRequest($joinRequest, $owner);

        $notification = MemberNotification::where('recipient_id', $applicant->id)->firstOrFail();

        $this->assertSame($org->id, $notification->organization_id);
        $this->assertNotSame($autreOrg->id, $notification->organization_id);
    }

    /**
     * Un decideur hors tenant rend la notification anonyme, il ne la fait pas
     * echouer.
     */
    public function test_an_out_of_tenant_decider_is_recorded_as_no_actor(): void
    {
        [$org, $loop, $owner, $applicant, $joinRequest] = $this->scenario();
        $autreOrg = $this->org();
        $etranger = User::factory()->create(['organization_id' => $autreOrg->id]);

        app(LoopService::class)->acceptJoinRequest($joinRequest, $etranger);

        $notification = MemberNotification::where('recipient_id', $applicant->id)->firstOrFail();

        $this->assertNull($notification->actor_id);
        $this->assertSame('accepted', $joinRequest->fresh()->status);
    }

    private function eventIdFor(LoopJoinRequest $joinRequest, string $notificationKey): string
    {
        return (string) Uuid::uuid5(
            Uuid::NAMESPACE_URL,
            'bouclepro:notification:'.$notificationKey.':'.$joinRequest->id,
        );
    }
}
