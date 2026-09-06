<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopInvitation;
use App\Models\MemberNotification;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopInvitationService;
use App\Support\Notifications\NotificationCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-1374 — l'invitation de Boucle, premier producteur reel.
 *
 * ## Ce que ces tests protegent
 *
 * Une invitation s'adresse a une ADRESSE EMAIL. Connaitre une adresse n'a jamais
 * donne le droit de franchir une frontiere d'Organization : la notification
 * IN_APP n'existe que si cette adresse appartient a un membre **de cette meme
 * Organization**.
 *
 * Inviter un inconnu, ou quelqu'un d'un autre tenant, est un cas metier
 * parfaitement NORMAL. L'email part, rien d'autre ne se passe, **et surtout
 * rien ne leve** : c'est le resolver qui filtre, l'emetteur reste strict.
 */
class TASK1374LoopInvitationNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $senderA;

    private User $membreA;

    private User $membreB;

    private Loop $loop;

    private LoopInvitationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create(['name' => 'T1374 Organization A', 'is_active' => true]);
        $this->orgB = Organization::factory()->create(['name' => 'T1374 Organization B', 'is_active' => true]);

        $this->senderA = User::factory()->create(['organization_id' => $this->orgA->id]);
        $this->membreA = User::factory()->create(['organization_id' => $this->orgA->id]);
        $this->membreB = User::factory()->create(['organization_id' => $this->orgB->id]);

        $this->loop = Loop::factory()->create([
            'organization_id' => $this->orgA->id,
            'created_by' => $this->senderA->id,
            'status' => 'active',
        ]);

        $this->service = app(LoopInvitationService::class);
    }

    protected function tearDown(): void
    {
        Organization::where('is_default', true)->update(['is_default' => false]);

        parent::tearDown();
    }

    // =====================================================================
    // 1-2. L'emission et son idempotence
    // =====================================================================

    /** Inviter un membre de la MEME Organization produit exactement une notification. */
    public function test_inviting_a_member_of_the_same_organization_produces_one_notification(): void
    {
        $this->service->invite($this->loop, $this->senderA, $this->membreA->email);

        $notifications = MemberNotification::query()
            ->forRecipient((string) $this->orgA->id, (string) $this->membreA->id)
            ->get();

        $this->assertCount(1, $notifications);
        $this->assertSame(NotificationCatalogue::LOOP_INVITATION, $notifications->first()->notification_key);
        $this->assertSame(NotificationCatalogue::OBJECT_LOOP_INVITATION, $notifications->first()->object_type);
        $this->assertSame((string) $this->senderA->id, $notifications->first()->actor_id);
    }

    /**
     * **Reinviter la meme adresse ne notifie pas deux fois.**
     *
     * `invite()` reutilise l'invitation en attente plutot que d'en creer une
     * seconde : c'est le meme fait, donc le meme `event_id`, donc la contrainte
     * d'unicite absorbe le rejeu.
     */
    public function test_re_inviting_the_same_address_does_not_notify_twice(): void
    {
        $premiere = $this->service->invite($this->loop, $this->senderA, $this->membreA->email);
        $seconde = $this->service->invite($this->loop, $this->senderA, $this->membreA->email);

        $this->assertSame($premiere->id, $seconde->id, 'invite() doit reutiliser l\'invitation en attente.');
        $this->assertSame(1, MemberNotification::query()->count());
    }

    /**
     * L'`event_id` est DETERMINISTE et DISTINCT de l'objet.
     *
     * Deux proprietes en une : il ne change pas d'un appel a l'autre — ce qui
     * fonde l'idempotence — et il ne se confond pas avec `object_id`, ce qui
     * laisserait un rappel ecraser l'invitation d'origine.
     */
    public function test_the_event_identity_is_deterministic_and_distinct_from_the_object(): void
    {
        $invitation = $this->service->invite($this->loop, $this->senderA, $this->membreA->email);
        $notification = MemberNotification::query()->firstOrFail();

        $this->assertNotSame(
            (string) $invitation->id,
            (string) $notification->event_id,
            'event_id designe le fait « on a prevenu », pas l\'objet dont on parle.'
        );
        $this->assertSame((string) $invitation->id, (string) $notification->object_id);

        // Rejoue : meme evenement, meme ligne.
        $this->service->invite($this->loop, $this->senderA, $this->membreA->email);

        $this->assertSame(1, MemberNotification::query()->count());
        $this->assertSame((string) $notification->event_id, (string) MemberNotification::query()->firstOrFail()->event_id);
    }

    /** Une invitation NOUVELLE est un evenement nouveau. */
    public function test_a_different_invitation_is_a_different_event(): void
    {
        $autreLoop = Loop::factory()->create([
            'organization_id' => $this->orgA->id,
            'created_by' => $this->senderA->id,
            'status' => 'active',
        ]);

        $this->service->invite($this->loop, $this->senderA, $this->membreA->email);
        $this->service->invite($autreLoop, $this->senderA, $this->membreA->email);

        $this->assertSame(2, MemberNotification::query()->count());
    }

    /**
     * **Un SECOND expediteur qui relance la meme personne ne casse rien.**
     *
     * Finding remonte par Fable, prouve par un test AVANT correction : deux
     * animateurs qui relancent la meme adresse pendant que l'invitation est en
     * attente faisaient sortir un `NotificationEmissionConflict` d'`invite()`.
     * L'appelant n'atteignait alors jamais son envoi d'email — un flux metier
     * ordinaire cassait sur une exception d'un module annexe.
     *
     * Semantique arbitree : une invitation en attente reutilisee est **le meme
     * evenement de notification**, meme declenchee par quelqu'un d'autre. Une
     * seule notification, et l'acteur reste celui de la premiere emission — la
     * personne a ete prevenue une fois, par quelqu'un.
     */
    public function test_a_second_sender_re_inviting_breaks_nothing(): void
    {
        $autreExpediteur = User::factory()->create(['organization_id' => $this->orgA->id]);

        $premiere = $this->service->invite($this->loop, $this->senderA, $this->membreA->email);
        $seconde = $this->service->invite($this->loop, $autreExpediteur, $this->membreA->email);

        $this->assertSame($premiere->id, $seconde->id, 'invite() doit rendre normalement l\'invitation reutilisee.');
        $this->assertSame(1, LoopInvitation::query()->count());
        $this->assertSame(1, MemberNotification::query()->count());
        $this->assertSame(
            (string) $this->senderA->id,
            (string) MemberNotification::query()->firstOrFail()->actor_id,
            'L\'acteur reste celui de la premiere emission.'
        );
    }

    /**
     * **Mais un VRAI conflit reste bruyant.**
     *
     * La tolerance ne vaut que pour l'acteur. Une ligne qui partagerait
     * l'`event_id` et le destinataire en designant un AUTRE objet est une
     * confusion d'identite d'evenement — exactement ce que l'emetteur existe
     * pour rendre visible. Sans cette limite, le rattrapage avalerait tout.
     */
    public function test_a_genuine_conflict_stays_loud(): void
    {
        $invitation = $this->service->invite($this->loop, $this->senderA, $this->membreA->email);
        $notification = MemberNotification::query()->firstOrFail();

        // On fait pointer la ligne existante vers un AUTRE objet, en contournant
        // l'immutabilite du modele : c'est l'etat de base qu'on veut simuler,
        // pas un chemin d'ecriture legitime.
        \Illuminate\Support\Facades\DB::table('member_notifications')
            ->where('id', $notification->id)
            ->update(['object_id' => (string) \Illuminate\Support\Str::uuid()]);

        $this->expectException(\App\Support\Notifications\NotificationEmissionConflict::class);

        $this->service->invite($this->loop, $this->senderA, $this->membreA->email);
    }

    /**
     * Une invitation ACCEPTEE puis une nouvelle invitation : nouvel evenement.
     *
     * L'invitation acceptee n'est plus en attente, donc `invite()` en cree une
     * seconde — nouvel identifiant, donc nouvel `event_id`, donc une seconde
     * notification. C'est bien un fait nouveau.
     */
    public function test_a_new_invitation_after_acceptance_is_a_new_event(): void
    {
        $premiere = $this->service->invite($this->loop, $this->senderA, $this->membreA->email);
        $this->service->accept($premiere->token, $this->membreA);

        $seconde = $this->service->invite($this->loop, $this->senderA, $this->membreA->email);

        $this->assertNotSame($premiere->id, $seconde->id, 'Une invitation acceptee ne se reutilise pas.');
        $this->assertSame(2, MemberNotification::query()->count());
    }

    // =====================================================================
    // 3-4. Les cas ou l'on ne notifie PAS — et ou rien ne leve
    // =====================================================================

    /**
     * **Une adresse sans compte est un cas metier normal.**
     *
     * L'invitation est creee, l'email partira, et aucune notification n'existe.
     * Surtout : `invite()` ne leve pas. Un producteur qui planterait sur une
     * invitation externe casserait une fonctionnalite qui marchait.
     */
    public function test_an_email_without_an_account_produces_no_notification_and_no_exception(): void
    {
        $invitation = $this->service->invite($this->loop, $this->senderA, 'inconnu@exemple.test');

        $this->assertNotNull($invitation->id, 'L\'invitation doit exister malgre l\'absence de compte.');
        $this->assertSame(0, MemberNotification::query()->count());
    }

    /**
     * **Une adresse connue d'un AUTRE tenant ne notifie pas davantage.**
     *
     * C'est le coeur du contrat : connaitre l'adresse de quelqu'un n'autorise
     * jamais a lui ecrire dans une Organization dont il n'est pas membre.
     */
    public function test_an_email_belonging_to_another_organization_produces_no_notification(): void
    {
        $invitation = $this->service->invite($this->loop, $this->senderA, $this->membreB->email);

        $this->assertNotNull($invitation->id);
        $this->assertSame(0, MemberNotification::query()->count());
        $this->assertSame(
            0,
            MemberNotification::query()->forRecipient((string) $this->orgB->id, (string) $this->membreB->id)->count(),
            'Rien ne doit non plus etre ecrit dans SON tenant a lui.'
        );
    }

    // =====================================================================
    // 5-6. Qui voit quoi
    // =====================================================================

    /** La notification n'est visible que de son destinataire. */
    public function test_the_notification_is_only_visible_to_its_recipient(): void
    {
        $this->service->invite($this->loop, $this->senderA, $this->membreA->email);
        $notification = MemberNotification::query()->firstOrFail();

        $this->actingAs($this->membreA)->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('data-notification-id="'.$notification->id.'"', false);

        $this->actingAs($this->senderA)->get(route('notifications.index'))
            ->assertOk()
            ->assertDontSee('data-notification-id="'.$notification->id.'"', false);
    }

    /**
     * **Apres un changement d'appartenance, seule l'Organization courante est
     * exposee.**
     *
     * Le seul montage qui mesure vraiment la frontiere : la MEME personne avec
     * un historique dans DEUX Organizations. Un voisin d'un autre tenant porte
     * aussi un autre destinataire — retirer le filtre de tenant ne changerait
     * rien, et le test resterait vert en donnant l'illusion de prouver la
     * frontiere.
     */
    public function test_after_a_membership_change_only_the_current_organization_is_exposed(): void
    {
        $this->service->invite($this->loop, $this->senderA, $this->membreA->email);
        $ancienne = MemberNotification::query()->firstOrFail();

        $this->membreA->forceFill(['organization_id' => $this->orgB->id])->save();

        $this->actingAs($this->membreA->fresh())->get(route('notifications.index'))
            ->assertOk()
            ->assertDontSee('data-notification-id="'.$ancienne->id.'"', false)
            ->assertDontSee('data-nav-badge-notifications', false);
    }

    // =====================================================================
    // 7. Ouvrir : lu d'abord, cible ensuite
    // =====================================================================

    /** Ouvrir conduit a la page ou l'on peut REPONDRE, et marque lu. */
    public function test_opening_leads_to_the_invitation_page_and_marks_read(): void
    {
        $invitation = $this->service->invite($this->loop, $this->senderA, $this->membreA->email);
        $notification = MemberNotification::query()->firstOrFail();

        $this->actingAs($this->membreA)
            ->from(route('notifications.index'))
            ->post(route('notifications.open', ['notification' => $notification->id]))
            ->assertRedirect(route('loop-invitations.show', ['token' => $invitation->token]));

        $this->assertNotNull($notification->fresh()->read_at);
    }

    /**
     * **Cible devenue inaccessible : etat honnete, et la notification est
     * quand meme lue.**
     *
     * Contre-intuitif une seconde, puis evident : le membre a pris connaissance
     * du signal et l'application lui a repondu. La laisser non lue condamnerait
     * le badge a signaler indefiniment quelque chose de deja traite — et un
     * badge qui ment est pire qu'un badge absent, parce qu'on cesse de le
     * regarder.
     */
    public function test_an_unreachable_target_yields_an_honest_state_and_still_marks_read(): void
    {
        $invitation = $this->service->invite($this->loop, $this->senderA, $this->membreA->email);
        $notification = MemberNotification::query()->firstOrFail();

        $this->service->revoke($invitation);

        $this->actingAs($this->membreA)
            ->from(route('notifications.index'))
            ->post(route('notifications.open', ['notification' => $notification->id]))
            ->assertRedirect(route('notifications.index'))
            ->assertSessionHas('notification_unreachable');

        $this->assertNotNull($notification->fresh()->read_at, 'Le signal a ete traite : la ligne est lue.');
    }

    /** Et l'ecran ne laisse filtrer aucun fragment de la cible disparue. */
    public function test_the_unreachable_state_leaks_nothing_about_the_target(): void
    {
        $invitation = $this->service->invite($this->loop, $this->senderA, $this->membreA->email);
        $notification = MemberNotification::query()->firstOrFail();

        $this->service->revoke($invitation);

        // On SUIT la redirection : le message d'etat vit dans la session flash,
        // et une requete separee ne le verrait pas.
        $html = $this->actingAs($this->membreA)
            ->from(route('notifications.index'))
            ->followingRedirects()
            ->post(route('notifications.open', ['notification' => $notification->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-notification-unreachable', $html);
        $this->assertStringNotContainsString((string) $invitation->token, $html, 'Le jeton ne doit jamais apparaitre.');
        $this->assertStringNotContainsString((string) $this->loop->name, $html, 'Le nom de la Boucle non plus.');
    }

    /**
     * **Une reference qui pointe vers un AUTRE tenant ne mene nulle part.**
     *
     * Les invariants d'ecriture verifient que le destinataire appartient a
     * l'Organization, et que `object_id` est un UUID — mais **pas** que l'objet
     * designe appartient au meme tenant. Rien ne le pourrait sans coupler le
     * socle a chaque type d'objet.
     *
     * La verification de tenant du resolver de cible est donc la SEULE garde sur
     * ce chemin. Ce test la mesure en construisant exactement la ligne qu'elle
     * doit arreter : un destinataire legitime d'Organization A, une reference
     * vers une invitation d'Organization B.
     */
    public function test_a_reference_pointing_at_another_tenant_leads_nowhere(): void
    {
        $loopB = Loop::factory()->create([
            'organization_id' => $this->orgB->id,
            'created_by' => $this->membreB->id,
            'status' => 'active',
        ]);
        $invitationB = $this->service->invite($loopB, $this->membreB, 'quelqu-un@exemple.test');

        $piegee = MemberNotification::create([
            'organization_id' => $this->orgA->id,
            'recipient_id' => $this->membreA->id,
            'notification_key' => NotificationCatalogue::LOOP_INVITATION,
            'event_id' => (string) Str::uuid(),
            'object_type' => NotificationCatalogue::OBJECT_LOOP_INVITATION,
            'object_id' => (string) $invitationB->id,
        ]);

        $html = $this->actingAs($this->membreA)
            ->from(route('notifications.index'))
            ->followingRedirects()
            ->post(route('notifications.open', ['notification' => $piegee->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-notification-unreachable', $html);
        $this->assertStringNotContainsString((string) $invitationB->token, $html, 'Le jeton d\'un autre tenant ne doit jamais fuir.');
    }

    // =====================================================================
    // 8. Connaitre l'identifiant n'accorde aucun droit
    // =====================================================================

    /** Un autre membre qui connait l'UUID ne peut ni lire ni muter. */
    public function test_knowing_the_uuid_grants_nothing_to_another_member(): void
    {
        $this->service->invite($this->loop, $this->senderA, $this->membreA->email);
        $notification = MemberNotification::query()->firstOrFail();

        $this->actingAs($this->senderA)
            ->post(route('notifications.open', ['notification' => $notification->id]))
            ->assertNotFound();

        $this->assertNull($notification->fresh()->read_at, 'Rien n\'a ete mute.');
    }

    /** Ni un membre d'un autre tenant. */
    public function test_a_member_of_another_tenant_gets_nothing(): void
    {
        $this->service->invite($this->loop, $this->senderA, $this->membreA->email);
        $notification = MemberNotification::query()->firstOrFail();

        $this->actingAs($this->membreB)
            ->post(route('notifications.open', ['notification' => $notification->id]))
            ->assertNotFound();

        $this->assertNull($notification->fresh()->read_at);
    }

    // =====================================================================
    // 9. L'email metier n'est pas touche
    // =====================================================================

    /**
     * L'invitation reste ecrite exactement comme avant.
     *
     * L'email part chez les APPELANTS d'`invite()`, pas dans `invite()` : cette
     * tranche n'a donc rien pu doubler. Ce test verrouille l'autre moitie — que
     * la ligne d'invitation, elle, n'a pas change de forme.
     */
    public function test_the_business_invitation_is_untouched(): void
    {
        $invitation = $this->service->invite($this->loop, $this->senderA, $this->membreA->email);

        $this->assertSame(LoopInvitation::STATUS_PENDING, $invitation->status);
        $this->assertSame((string) $this->orgA->id, (string) $invitation->organization_id);
        $this->assertSame((string) $this->loop->id, (string) $invitation->loop_id);
        $this->assertNotNull($invitation->token);
        $this->assertSame(1, LoopInvitation::query()->count());
    }
}
