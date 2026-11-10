<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopEvent;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use App\Services\Loops\LoopEventService;
use App\Services\LoopMessageService;
use App\Support\Loops\LoopTypeRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-1802 (suite, demande Cyril, 10/11) — sur la Card de l'évènement
 * diffusée dans la Boucle :
 *
 * 1. « On ne voit pas la date de fin de l'évènement (ou la durée si c'est
 *    moins d'un jour d'écart). »
 * 2. « Si c'est un évènement qui contient une URL (en ligne ou hybride), il
 *    faut que l'URL soit cliquable. »
 *
 * Le lien vivait auparavant concaténé dans `$message->body`, un texte
 * échappé par `{{ }}` — jamais cliquable. Il vit désormais dans
 * `metadata.meeting_url`, et c'est `loop-chat.blade.php` qui le rend en
 * `<a href>`.
 */
class TASK1802EventChatLinkTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $owner;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['is_active' => true]);
        $this->owner = User::factory()->create(['organization_id' => $this->org->id]);

        $this->loop = Loop::create([
            'organization_id' => $this->org->id,
            'name' => 'Boucle Lien',
            'slug' => Str::slug('Boucle Lien').'-'.uniqid(),
            'type' => 'general',
            'status' => 'active',
            'visibility' => 'public',
            'created_by' => $this->owner->id,
        ]);
        app(LoopTypeRegistry::class)->applyPreset($this->loop);

        LoopMember::create([
            'organization_id' => $this->org->id,
            'loop_id' => $this->loop->id,
            'user_id' => $this->owner->id,
            'role' => 'owner',
            'status' => 'active',
        ]);

        app()->instance('current_organization', $this->org);
    }

    /** @param array<string, mixed> $overrides */
    private function creerEtAnnoncer(array $overrides = []): \App\Models\LoopMessage
    {
        $event = app(LoopEventService::class)->create($this->owner, $this->loop, array_merge([
            'title' => 'Atelier',
            'description' => null,
            'format' => LoopEvent::FORMAT_IN_PERSON,
            'starts_at' => CarbonImmutable::parse('2026-12-01 10:00')->format('Y-m-d\TH:i'),
            'ends_at' => '',
            'timezone' => 'Europe/Paris',
            'location' => 'Salle A',
            'meeting_url' => '',
            'visibility' => LoopEvent::VISIBILITY_LOOP,
        ], $overrides));

        return app(LoopMessageService::class)->sendEventMessage($this->loop, $this->owner, $event, 'created');
    }

    public function test_a_same_day_end_shows_the_duration_not_a_second_date(): void
    {
        $message = $this->creerEtAnnoncer(['ends_at' => CarbonImmutable::parse('2026-12-01 11:30')->format('Y-m-d\TH:i')]);

        $this->assertStringContainsString('durée 1 h 30', $message->body);
        $this->assertStringNotContainsString('au 1 déc', $message->body);
    }

    public function test_an_end_on_another_day_shows_the_full_end_date(): void
    {
        $message = $this->creerEtAnnoncer(['ends_at' => CarbonImmutable::parse('2026-12-02 09:00')->format('Y-m-d\TH:i')]);

        $this->assertStringContainsString('du 1 déc. 2026, 10:00 au 2 déc. 2026, 09:00', $this->normaliser($message->body));
    }

    public function test_no_end_at_all_keeps_the_historical_body(): void
    {
        $message = $this->creerEtAnnoncer();

        $this->assertSame(
            'Un nouvel événement a été proposé : Atelier (1 déc. 2026, 10:00)',
            $this->normaliser($message->body),
        );
    }

    public function test_an_online_event_renders_a_real_clickable_link(): void
    {
        $this->creerEtAnnoncer([
            'format' => LoopEvent::FORMAT_ONLINE,
            'location' => '',
            'meeting_url' => 'https://visio.example.org/atelier',
        ]);

        $html = $this->actingAs($this->owner)->get(route('loops.show', $this->loop))->assertOk()->getContent();

        $this->assertStringContainsString(
            '<a href="https://visio.example.org/atelier" target="_blank" rel="noopener noreferrer"',
            $html,
        );
        // Le corps du message ne doit PLUS porter l'URL en texte brut : seul
        // le marqueur historique de la concatenation (« — Lien : ») prouve
        // son absence — compter les occurrences brutes de l'URL compterait
        // aussi celle serialisee par Livewire dans l'etat du composant
        // (`wire:snapshot`), qui n'est pas ce que ce test verifie.
        $this->assertStringNotContainsString('— Lien :', $html);
    }

    public function test_an_in_person_event_renders_no_link_at_all(): void
    {
        $this->creerEtAnnoncer();

        $html = $this->actingAs($this->owner)->get(route('loops.show', $this->loop))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-event-chat-meeting-link', $html);
    }

    /**
     * TASK-1802 (suite, demande Cyril, 10/11, image montrant le lien deux
     * fois) — un message cree AVANT ce correctif porte encore l'ancien corps
     * concatene (« — Lien : :url »). La vue doit retirer ce suffixe figE a
     * l'affichage, sinon le lien apparait deux fois : une fois en texte brut
     * historique, une fois en <a> cliquable.
     */
    public function test_a_legacy_message_with_the_old_concatenated_link_shows_it_only_once(): void
    {
        $event = app(LoopEventService::class)->create($this->owner, $this->loop, [
            'title' => 'Reunion ouverte a tous',
            'description' => null,
            'format' => LoopEvent::FORMAT_ONLINE,
            'starts_at' => CarbonImmutable::parse('2026-12-01 10:00')->format('Y-m-d\TH:i'),
            'ends_at' => '',
            'timezone' => 'Europe/Paris',
            'location' => '',
            'meeting_url' => 'https://meet.google.com/acx-mqxe-cgi',
            'visibility' => LoopEvent::VISIBILITY_LOOP,
        ]);

        \App\Models\LoopMessage::create([
            'loop_id' => $this->loop->id,
            'sender_id' => $this->owner->id,
            'body' => 'Un nouvel événement a été proposé : Reunion ouverte a tous (1 déc. 2026, 10:00) — Lien : https://meet.google.com/acx-mqxe-cgi',
            'type' => 'loop_event',
            'metadata' => [
                'event' => 'created',
                'event_id' => $event->id,
                'title' => $event->title,
                'starts_at' => $event->starts_at?->toIso8601String(),
                'ends_at' => $event->ends_at?->toIso8601String(),
                'timezone' => $event->timezone,
                'format' => $event->format,
                'description' => $event->description,
                'location' => $event->location,
                'meeting_url' => $event->meeting_url,
            ],
            'organization_id' => $this->org->id,
        ]);

        $html = $this->actingAs($this->owner)->get(route('loops.show', $this->loop))->assertOk()->getContent();

        $this->assertStringNotContainsString('— Lien :', $html);
        $this->assertStringContainsString(
            'Un nouvel événement a été proposé : Reunion ouverte a tous (1 déc. 2026, 10:00)',
            $html,
        );
        $this->assertStringContainsString(
            '<a href="https://meet.google.com/acx-mqxe-cgi" target="_blank" rel="noopener noreferrer"',
            $html,
        );
    }

    /** Normalise l'espace insecable qu'`isoFormat` peut inserer avant les unites. */
    private function normaliser(string $valeur): string
    {
        return str_replace("\u{a0}", ' ', $valeur);
    }
}
