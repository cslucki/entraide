<?php

namespace Tests\Feature;

use App\Livewire\LoopChat;
use App\Models\Loop;
use App\Models\LoopEvent;
use App\Models\LoopMember;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\User;
use App\Services\Loops\LoopEventService;
use App\Services\LoopMessageService;
use App\Support\Loops\LoopTypeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1890 (Cyril, 23/11) — epingler la tuile d'un evenement dans ChatLoop.
 *
 * Epinglee, elle devient un bandeau d'UNE ligne « titre — date et heure » en
 * tete du chat. Epingle/desepingle qui peut gerer l'evenement : son auteur
 * (meme simple membre) et qui a `events.manage` — pas les autres membres.
 */
class TASK1890EpinglerUnEvenementDansChatLoopTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Loop $loop;

    private User $owner;

    private User $auteur;

    private User $autre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['is_active' => true, 'loops_enabled' => true]);
        $this->owner = User::factory()->create(['organization_id' => $this->org->id]);
        $this->auteur = User::factory()->create(['organization_id' => $this->org->id]);
        $this->autre = User::factory()->create(['organization_id' => $this->org->id]);

        $this->loop = Loop::create([
            'organization_id' => $this->org->id,
            'name' => 'Boucle Agenda',
            'slug' => Str::slug('Boucle Agenda').'-'.uniqid(),
            'type' => 'general',
            'status' => 'active',
            'visibility' => 'public',
            'created_by' => $this->owner->id,
        ]);
        app(LoopTypeRegistry::class)->applyPreset($this->loop);

        foreach ([[$this->owner, 'owner'], [$this->auteur, 'member'], [$this->autre, 'member']] as [$user, $role]) {
            LoopMember::create([
                'organization_id' => $this->org->id,
                'loop_id' => $this->loop->id,
                'user_id' => $user->id,
                'role' => $role,
                'status' => 'active',
            ]);
        }

        app()->instance('current_organization', $this->org);
    }

    public function test_l_auteur_membre_epingle_et_le_bandeau_montre_titre_date_et_heure(): void
    {
        [$event, $tuile] = $this->evenement('Atelier couture', now()->addDays(3)->setTime(14, 30));
        $attendu = 'Atelier couture — '.$event->startsAtLocal()->locale(app()->getLocale())->isoFormat('ddd D MMM, HH:mm');

        $this->chat($this->auteur)
            ->assertDontSeeHtml('data-pinned-event-ticker')
            ->assertSeeHtml('data-action="pin-event"')
            ->call('toggleEventPin', $tuile->id)
            ->assertSeeHtml('data-pinned-event-ticker')
            ->assertSee($attendu)
            // Le ticker du telephone rend le texte deux fois : la copie qui
            // boucle le defilement est muette pour les lecteurs d'ecran.
            ->assertSeeHtml('bp-ticker-copie" aria-hidden="true">'.e($attendu).'</span>')
            ->assertSee(__('events.chat_unpin'))
            ->assertSeeHtml('data-action="unpin-event"');

        $this->assertNotNull($tuile->fresh()->pinned_at);
    }

    public function test_un_autre_membre_voit_le_bandeau_sans_pouvoir_epingler_ni_retirer(): void
    {
        [, $tuile] = $this->evenement('Atelier', now()->addDay());

        $this->chat($this->autre)
            ->assertDontSeeHtml('data-action="pin-event"')
            ->call('toggleEventPin', $tuile->id);
        $this->assertNull($tuile->fresh()->pinned_at);

        $this->chat($this->auteur)->call('toggleEventPin', $tuile->id);

        $this->chat($this->autre)
            ->assertSeeHtml('data-pinned-event-ticker')
            ->assertDontSeeHtml('data-action="unpin-event"')
            ->call('unpinEvent');
        $this->assertNotNull($tuile->fresh()->pinned_at);
    }

    public function test_le_proprietaire_peut_retirer_l_epingle_d_un_evenement_qu_il_n_a_pas_cree(): void
    {
        [, $tuile] = $this->evenement('Atelier', now()->addDay());
        $this->chat($this->auteur)->call('toggleEventPin', $tuile->id);

        $this->chat($this->owner)
            ->call('unpinEvent')
            ->assertDontSeeHtml('data-pinned-event-ticker');

        $this->assertNull($tuile->fresh()->pinned_at);
    }

    public function test_l_evenement_epingle_coexiste_avec_le_message_epingle(): void
    {
        [, $tuile] = $this->evenement('Atelier', now()->addDay());
        $message = app(LoopMessageService::class)->sendUserMessage($this->loop, $this->autre, 'Bonjour a tous');

        $this->chat($this->auteur)->call('toggleEventPin', $tuile->id);
        $this->chat($this->autre)
            ->call('pinMessage', $message->id)
            ->call('pinMessage', $tuile->id)
            ->call('unpinMessage');

        $this->assertNotNull($tuile->fresh()->pinned_at, 'Le message ordinaire ne touche pas a l\'evenement epingle.');
        $this->assertNull($message->fresh()->pinned_at);

        $this->chat($this->auteur)
            ->call('toggleEventPin', $tuile->id)
            ->assertDontSeeHtml('data-pinned-event-ticker');
    }

    public function test_un_evenement_annule_ou_passe_disparait_du_bandeau(): void
    {
        [$event, $tuile] = $this->evenement('Atelier', now()->addDay());
        $this->chat($this->auteur)->call('toggleEventPin', $tuile->id);

        $event->update(['status' => LoopEvent::STATUS_CANCELLED, 'cancelled_at' => now()]);
        $this->chat($this->autre)->assertDontSeeHtml('data-pinned-event-ticker');

        [$passe, $tuilePasse] = $this->evenement('Hier', now()->addDay());
        $this->chat($this->auteur)->call('toggleEventPin', $tuilePasse->id);
        $this->travelTo(now()->addDays(3));
        $this->chat($this->autre)->assertDontSeeHtml('data-pinned-event-ticker');
    }

    public function test_le_bandeau_suit_la_date_actuelle_de_l_evenement(): void
    {
        [$event, $tuile] = $this->evenement('Atelier', now()->addDay()->setTime(10, 0));
        $this->chat($this->auteur)->call('toggleEventPin', $tuile->id);

        $event->update(['title' => 'Atelier deplace', 'starts_at' => now()->addDays(5)->setTime(18, 15)]);
        $attendu = 'Atelier deplace — '.$event->fresh()->startsAtLocal()->locale(app()->getLocale())->isoFormat('ddd D MMM, HH:mm');

        $this->chat($this->autre)->assertSee($attendu);
    }

    /** @return array{0: LoopEvent, 1: LoopMessage} */
    private function evenement(string $titre, \DateTimeInterface $debut): array
    {
        $event = app(LoopEventService::class)->create($this->auteur, $this->loop, [
            'title' => $titre,
            'description' => null,
            'format' => LoopEvent::FORMAT_IN_PERSON,
            'starts_at' => $debut->format('Y-m-d\TH:i'),
            'ends_at' => '',
            'timezone' => 'Europe/Paris',
            'location' => 'Salle A',
            'meeting_url' => '',
            'visibility' => LoopEvent::VISIBILITY_LOOP,
        ]);

        $tuile = LoopMessage::query()->where('loop_id', $this->loop->id)->where('type', 'loop_event')
            ->where('metadata->event_id', $event->id)->latest()->first()
            ?? app(LoopMessageService::class)->sendEventMessage($this->loop, $this->auteur, $event, 'created');

        return [$event, $tuile];
    }

    private function chat(User $lecteur)
    {
        return Livewire::actingAs($lecteur)->test(LoopChat::class, ['loop' => $this->loop->fresh()]);
    }
}
