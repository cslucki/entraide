<?php

namespace Tests\Feature;

use App\Models\EmailLog;
use App\Models\Loop;
use App\Models\LoopEvent;
use App\Models\LoopMember;
use App\Models\LoopMessage;
use App\Models\LoopNotificationPreference;
use App\Models\MemberNotification;
use App\Models\Organization;
use App\Models\User;
use App\Services\Loops\LoopEventService;
use App\Services\LoopMessageService;
use App\Support\Loops\LoopTypeRegistry;
use App\Support\Notifications\NotificationCatalogue;
use App\Support\Notifications\NotificationEmailDeliverer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-1802 (demande Cyril) — « un nouvel évènement a été proposé dans une
 * Boucle » prévient ses membres, email + app + PWA, les trois actifs par
 * défaut.
 *
 * Même doctrine que `TASK1747HelpRequestNotificationTest` — son précédent le
 * plus proche, un fait COLLECTIF — avec une différence assumée : Cyril n'a
 * demandé la notification que pour la CRÉATION, jamais pour une modification
 * ou une annulation.
 */
class TASK1802LoopEventNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Loop $boucle;

    private User $auteur;

    private User $membre;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->organization = Organization::factory()->create(['is_active' => true]);
        $this->auteur = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->membre = User::factory()->create([
            'organization_id' => $this->organization->id,
            'preferred_locale' => 'fr',
        ]);

        $this->boucle = Loop::create([
            'organization_id' => $this->organization->id,
            'name' => 'Boucle Jardin',
            'slug' => Str::slug('Boucle Jardin').'-'.uniqid(),
            'type' => 'general',
            'status' => 'active',
            'visibility' => 'public',
            'created_by' => $this->auteur->id,
        ]);
        app(LoopTypeRegistry::class)->applyPreset($this->boucle);

        foreach ([$this->auteur, $this->membre] as $personne) {
            LoopMember::create([
                'loop_id' => $this->boucle->id,
                'user_id' => $personne->id,
                'organization_id' => $this->organization->id,
                'role' => $personne->is($this->auteur) ? 'owner' : 'member',
                'status' => 'active',
                'joined_at' => now(),
            ]);
        }

        app()->instance('current_organization', $this->organization);
    }

    /** @param array<string, mixed> $overrides */
    private function creerEvenement(array $overrides = []): LoopEvent
    {
        return app(LoopEventService::class)->create($this->auteur, $this->boucle, array_merge([
            'title' => 'Atelier compost',
            'description' => 'On apprend à faire son compost ensemble.',
            'format' => LoopEvent::FORMAT_IN_PERSON,
            'starts_at' => CarbonImmutable::parse('2026-12-01 18:00')->format('Y-m-d\TH:i'),
            'ends_at' => '',
            'timezone' => 'Europe/Paris',
            'location' => '12 rue des Lilas',
            'meeting_url' => '',
            'visibility' => LoopEvent::VISIBILITY_LOOP,
        ], $overrides));
    }

    private function annoncer(LoopEvent $event, string $kind = 'created'): LoopMessage
    {
        return app(LoopMessageService::class)->sendEventMessage($this->boucle, $this->auteur, $event, $kind);
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, MemberNotification> */
    private function notificationsDeLEvenement()
    {
        return MemberNotification::query()
            ->where('notification_key', NotificationCatalogue::LOOP_EVENT_CREATED)
            ->get();
    }

    public function test_un_nouvel_evenement_previent_les_membres_SAUF_son_auteur(): void
    {
        $this->annoncer($this->creerEvenement());

        $notifications = $this->notificationsDeLEvenement();

        $this->assertCount(1, $notifications, 'un seul membre doit être prévenu');
        $this->assertSame($this->membre->id, $notifications->first()->recipient_id);
        $this->assertSame(
            0,
            $this->notificationsDeLEvenement()->where('recipient_id', $this->auteur->id)->count(),
            "l'auteur ne doit pas être prévenu de son propre évènement",
        );
    }

    public function test_une_modification_ou_une_annulation_ne_notifie_personne(): void
    {
        // La garde de cette TASK : Cyril n'a demandé la notification que pour
        // « un nouvel évènement a été proposé », jamais pour une modification
        // ou une annulation.
        $event = $this->creerEvenement();
        $this->annoncer($event, 'created');

        $avant = MemberNotification::query()->count();

        $this->annoncer($event, 'updated');
        $this->annoncer($event, 'cancelled');

        $this->assertSame(
            $avant,
            MemberNotification::query()->count(),
            'une modification ou une annulation ne doit créer aucune notification supplémentaire',
        );
    }

    public function test_les_trois_canaux_sont_declares_ACTIFS_par_defaut(): void
    {
        $cle = NotificationCatalogue::LOOP_EVENT_CREATED;

        $this->assertTrue(NotificationCatalogue::channelDefault($cle, NotificationCatalogue::CHANNEL_IN_APP));
        $this->assertFalse(
            NotificationCatalogue::channelIsConfigurable($cle, NotificationCatalogue::CHANNEL_IN_APP),
            'même régime que les autres faits collectifs par Boucle : la sourdine PAR BOUCLE est le levier de calme.',
        );

        foreach ([NotificationCatalogue::CHANNEL_EMAIL, NotificationCatalogue::CHANNEL_WEB_PUSH] as $canal) {
            $this->assertTrue(NotificationCatalogue::channelDefault($cle, $canal), "[{$canal}] doit être actif par défaut.");
            $this->assertTrue(NotificationCatalogue::channelIsConfigurable($cle, $canal), "[{$canal}] doit rester débrayable.");
        }
    }

    public function test_la_sourdine_PAR_BOUCLE_empeche_toute_notification(): void
    {
        $reglage = new LoopNotificationPreference(['collective_enabled' => false]);
        $reglage->organization_id = (string) $this->organization->id;
        $reglage->loop_id = (string) $this->boucle->id;
        $reglage->user_id = (string) $this->membre->id;
        $reglage->save();

        $this->annoncer($this->creerEvenement());

        $this->assertCount(
            0,
            $this->notificationsDeLEvenement(),
            "la sourdine par Boucle du membre doit s'appliquer, in-app compris",
        );
    }

    public function test_le_message_de_creation_porte_la_date_et_l_heure(): void
    {
        $message = $this->annoncer($this->creerEvenement());

        $this->assertStringContainsString('1 déc. 2026', $this->normaliserDate($message->body));
        $this->assertStringContainsString('18:00', $message->body);
    }

    public function test_le_message_de_creation_porte_le_lien_SI_en_ligne(): void
    {
        $message = $this->annoncer($this->creerEvenement([
            'format' => LoopEvent::FORMAT_ONLINE,
            'location' => '',
            'meeting_url' => 'https://visio.example.org/jardin',
        ]));

        $this->assertStringContainsString('https://visio.example.org/jardin', $message->body);
    }

    public function test_le_message_de_creation_ne_porte_AUCUN_lien_sur_place(): void
    {
        $message = $this->annoncer($this->creerEvenement());

        $this->assertStringNotContainsString('http', $message->body);
    }

    public function test_le_lien_n_apparait_pas_sur_une_modification(): void
    {
        $event = $this->creerEvenement([
            'format' => LoopEvent::FORMAT_ONLINE,
            'location' => '',
            'meeting_url' => 'https://visio.example.org/jardin',
        ]);
        $this->annoncer($event, 'created');

        $message = $this->annoncer($event, 'updated');

        $this->assertStringNotContainsString('https://visio.example.org/jardin', $message->body);
    }

    public function test_le_courriel_administrable_porte_le_titre_la_date_la_boucle_et_l_adresse(): void
    {
        $event = $this->creerEvenement();
        $message = $this->annoncer($event);

        $notification = $this->notificationsDeLEvenement()->firstOrFail();

        app(NotificationEmailDeliverer::class)->deliver((string) $notification->id);

        $journal = EmailLog::query()->whereNotNull('notification_id')->firstOrFail();

        $this->assertStringContainsString($event->title, $journal->subject.$journal->body_html);
        $this->assertStringContainsString($this->boucle->name, $journal->body_html);
        $this->assertStringContainsString('12 rue des Lilas', $journal->body_html);
        // La description est du texte écrit par un membre : archivée EXPURGÉE.
        $this->assertStringNotContainsString('compost ensemble', $journal->body_html);
        $this->assertStringContainsString('[description-redacted]', $journal->body_html);
    }

    public function test_le_courriel_porte_le_lien_de_connexion_SI_en_ligne(): void
    {
        $event = $this->creerEvenement([
            'format' => LoopEvent::FORMAT_ONLINE,
            'location' => '',
            'meeting_url' => 'https://visio.example.org/jardin',
        ]);
        $this->annoncer($event);

        $notification = $this->notificationsDeLEvenement()->firstOrFail();
        app(NotificationEmailDeliverer::class)->deliver((string) $notification->id);

        $journal = EmailLog::query()->whereNotNull('notification_id')->firstOrFail();

        $this->assertStringContainsString('https://visio.example.org/jardin', $journal->body_html);
    }

    /** `isoFormat` rend parfois « déc. » avec une espace insécable : on la normalise pour comparer. */
    private function normaliserDate(string $valeur): string
    {
        return str_replace("\u{a0}", ' ', $valeur);
    }
}
