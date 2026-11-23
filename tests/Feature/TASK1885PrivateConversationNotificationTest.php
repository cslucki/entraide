<?php

namespace Tests\Feature;

use App\Jobs\SendNewMessageReceivedEmail;
use App\Models\MemberNotification;
use App\Models\MemberNotificationDelivery;
use App\Models\MemberNotificationPreference;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Service;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\NewMessageReceived;
use App\Support\Notifications\NotificationCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * TASK-1885 C5 (demande Cyril, 22/11) — « Recevoir un message prive » :
 * notification en application + PWA, email, les trois ACTIFS par defaut et
 * reglables a l'ecran des preferences.
 *
 * Gardes de sens :
 * - un message prive cree UNE notification (app + push planifie), et
 *   l'email part UNE fois — par le job dedie, jamais par le planificateur ;
 * - couper l'email a l'ecran l'arrete vraiment ; l'application reste ;
 * - rejouer le job ne cree pas de seconde notification ;
 * - un message d'ECHANGE d'entraide garde son comportement d'avant.
 */
class TASK1885PrivateConversationNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $expediteur;

    private User $destinataire;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('webpush.enabled', true);
        config()->set('webpush.vapid.subject', 'mailto:contact@exemple.test');

        $this->org = Organization::factory()->create(['slug' => 'org-1885-c5', 'is_active' => true, 'locale' => 'fr']);
        app()->instance('current_organization', $this->org);

        $this->expediteur = User::factory()->complete()->create(['organization_id' => $this->org->id, 'first_name' => 'Camille']);
        $this->destinataire = User::factory()->complete()->create(['organization_id' => $this->org->id, 'preferred_locale' => 'fr']);

        Queue::fake();
        Notification::fake();
    }

    public function test_a_private_message_notifies_in_app_with_push_and_one_email(): void
    {
        $conversation = $this->conversationPrivee();
        $this->traiter($conversation, $this->message($conversation));

        $notification = MemberNotification::query()
            ->where('recipient_id', $this->destinataire->id)
            ->where('notification_key', NotificationCatalogue::CONVERSATION_PRIVATE_RECEIVED)
            ->sole();

        $this->assertSame(NotificationCatalogue::OBJECT_TRANSACTION, $notification->object_type);
        $this->assertSame((string) $conversation->id, (string) $notification->object_id);
        $this->assertSame((string) $this->expediteur->id, (string) $notification->actor_id);

        $canaux = MemberNotificationDelivery::query()->where('notification_id', $notification->id)->pluck('channel')->all();
        $this->assertContains(NotificationCatalogue::CHANNEL_WEB_PUSH, $canaux);
        $this->assertNotContains(NotificationCatalogue::CHANNEL_EMAIL, $canaux, 'Email planifie par le catalogue = double envoi.');

        Notification::assertSentToTimes($this->destinataire, NewMessageReceived::class, 1);
        $this->assertSame(0, MemberNotification::query()->where('recipient_id', $this->expediteur->id)->count());
    }

    public function test_turning_email_off_stops_the_email_but_keeps_the_app_notification(): void
    {
        $this->actingAs($this->destinataire)
            ->post(route('notifications.preferences.update'), ['canaux' => [
                NotificationCatalogue::CONVERSATION_PRIVATE_RECEIVED => [
                    NotificationCatalogue::CHANNEL_IN_APP => '1',
                    NotificationCatalogue::CHANNEL_WEB_PUSH => '1',
                ],
            ]])->assertRedirect();

        $this->assertTrue(MemberNotificationPreference::query()
            ->forOwner((string) $this->destinataire->id)
            ->where('notification_key', NotificationCatalogue::CONVERSATION_PRIVATE_RECEIVED)
            ->where('channel', NotificationCatalogue::CHANNEL_EMAIL)
            ->where('enabled', false)
            ->exists());

        $conversation = $this->conversationPrivee();
        $this->traiter($conversation, $this->message($conversation));

        Notification::assertNotSentTo($this->destinataire, NewMessageReceived::class);
        $this->assertSame(1, MemberNotification::query()->where('recipient_id', $this->destinataire->id)->count());
    }

    public function test_replaying_the_job_does_not_duplicate_the_notification(): void
    {
        $conversation = $this->conversationPrivee();
        $message = $this->message($conversation);

        $this->traiter($conversation, $message);
        $this->traiter($conversation, $message);

        $this->assertSame(1, MemberNotification::query()
            ->where('recipient_id', $this->destinataire->id)
            ->where('notification_key', NotificationCatalogue::CONVERSATION_PRIVATE_RECEIVED)
            ->count());
    }

    public function test_an_exchange_message_keeps_its_previous_behaviour(): void
    {
        $service = Service::factory()->forUser($this->destinataire)->create(['organization_id' => $this->org->id, 'status' => 'active']);
        $echange = Transaction::factory()->create([
            'organization_id' => $this->org->id,
            'buyer_id' => $this->expediteur->id,
            'seller_id' => $this->destinataire->id,
            'service_id' => $service->id,
            'request_id' => null,
            'points_proposed' => 30,
            'status' => 'accepted',
        ]);

        $this->traiter($echange, $this->message($echange));

        $this->assertSame(0, MemberNotification::query()
            ->where('notification_key', NotificationCatalogue::CONVERSATION_PRIVATE_RECEIVED)->count());
        Notification::assertSentToTimes($this->destinataire, NewMessageReceived::class, 1);
    }

    public function test_the_center_names_the_sender_and_the_preferences_offer_the_setting(): void
    {
        $conversation = $this->conversationPrivee();
        $this->traiter($conversation, $this->message($conversation));

        $this->actingAs($this->destinataire)
            ->get(route('organization.notifications.index', ['organization' => $this->org->slug]))
            ->assertOk()
            ->assertSee('Camille vous a envoyé un message privé');

        $this->actingAs($this->destinataire)
            ->get(route('organization.notifications.preferences.edit', ['organization' => $this->org->slug]))
            ->assertOk()
            ->assertSee('Recevoir un message privé');
    }

    private function conversationPrivee(): Transaction
    {
        $conversation = Transaction::factory()->create([
            'organization_id' => $this->org->id,
            'buyer_id' => $this->expediteur->id,
            'seller_id' => $this->destinataire->id,
            'service_id' => null,
            'request_id' => null,
            'points_proposed' => 0,
            'status' => 'pending',
        ]);
        $this->assertTrue($conversation->isDirectConversation(), 'precondition : discussion privee');

        return $conversation;
    }

    private function message(Transaction $transaction): Message
    {
        return Message::create([
            'transaction_id' => $transaction->id,
            'sender_id' => $this->expediteur->id,
            'body' => 'Bonjour, une question rapide.',
            'type' => 'user',
            'organization_id' => $this->org->id,
        ]);
    }

    private function traiter(Transaction $transaction, Message $message): void
    {
        (new SendNewMessageReceivedEmail((string) $transaction->id, (string) $message->id, (string) $this->destinataire->id))->handle();
    }
}
