<?php

namespace App\Livewire;

use App\Models\Loop;
use App\Models\LoopDecision;
use App\Models\LoopEvent;
use App\Models\LoopMember;
use App\Models\LoopRoadmapItem;
use App\Models\User;
use App\Services\ChatLoop\ChatLoopAiService;
use App\Services\Loops\LoopEventService;
use App\Support\Ai\AiRefusedException;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Component;

class LoopAiSummaryCard extends Component
{
    public Loop $loop;

    public bool $canGenerate = false;

    public bool $hasSummary = false;

    public ?string $summaryBody = null;

    public ?string $summaryCreatedAtIso = null;

    public ?string $summaryAuthor = null;

    /** Trigger a one-time automatic generation on the first real open of the card. */
    public bool $autoGenerate = false;

    public ?string $errorMessage = null;

    /**
     * TASK-1229 : code stable du refus (credit utilisateur epuise / budget
     * Organization / IA non configuree) — la carte propose « Voir les offres »
     * uniquement pour le credit.
     */
    public ?string $errorCode = null;

    /** TASK-1229 : URL « Voir les offres », seulement si la plateforme le propose. */
    public ?string $offersUrl = null;

    public function mount(ChatLoopAiService $service): void
    {
        $this->canGenerate = $this->userCanGenerate();

        $this->loadSummary($service);

        $this->autoGenerate = $this->canGenerate
            && ! $this->hasSummary
            && $service->loopHasEnoughContent($this->loop);
    }

    public function generate(): void
    {
        // A single wire:init attempt at most; never loop.
        $this->autoGenerate = false;
        $this->errorMessage = null;
        $this->errorCode = null;
        $this->offersUrl = null;

        if (! $this->userCanGenerate()) {
            return;
        }

        $user = auth()->user();

        $rateKey = 'chatloop-summarize:'.$this->loop->id.':'.$user->id;

        if (RateLimiter::tooManyAttempts($rateKey, 5)) {
            $this->errorMessage = __('loops.ai_summary_rate_limited');

            return;
        }

        RateLimiter::hit($rateKey, 60);

        $service = app(ChatLoopAiService::class);

        try {
            $service->summarize($this->loop, $user);
            $this->loadSummary($service);
        } catch (AiRefusedException $e) {
            // Non-blocking, avec son code : le message dit QUEL etat refuse.
            $this->errorMessage = $e->getMessage();
            $this->errorCode = $e->refusalCode;
            $this->offersUrl = $e->offersUrl($this->loop->organization);
        } catch (\RuntimeException $e) {
            // Non-blocking: keep the previous summary visible if any.
            $this->errorMessage = $e->getMessage();
        }
    }

    /**
     * TASK-1207 : le dernier resume est relu depuis sa trace technique
     * (`ai_interactions`) et non plus depuis un `LoopMessage` publie dans la
     * Boucle — `loop_summary` est une capability `can_write=false`. La surface
     * affichee est strictement la meme : corps, date, demandeur.
     */
    private function loadSummary(ChatLoopAiService $service): void
    {
        $summary = $service->latestSummary($this->loop);

        if (! $summary) {
            $this->hasSummary = false;
            $this->summaryBody = null;
            $this->summaryCreatedAtIso = null;
            $this->summaryAuthor = null;

            return;
        }

        $this->hasSummary = true;
        $this->summaryBody = $summary->body;
        $this->summaryCreatedAtIso = $summary->createdAt?->toIso8601String();

        $this->summaryAuthor = $summary->requestedById
            ? User::find($summary->requestedById)?->publicDisplayName()
            : null;
    }

    private function userCanGenerate(): bool
    {
        $user = auth()->user();

        if (! $user || $user->isDeactivated()) {
            return false;
        }

        if ($user->is_admin) {
            return true;
        }

        if ($this->loop->organization_id !== $user->organization_id) {
            return false;
        }

        return LoopMember::where('loop_id', $this->loop->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->exists();
    }

    public function placeholder()
    {
        return view('livewire.loop-ai-summary-card-placeholder');
    }

    public function render()
    {
        return view('livewire.loop-ai-summary-card', [
            'pulse' => $this->loopPulse(),
        ]);
    }

    /**
     * Etat metier partage par les Cards de la Boucle, sans inference IA.
     *
     * @return array{members: int, roadmap: int, decisions: int, events: int}|null
     */
    private function loopPulse(): ?array
    {
        // La Card Resume IA et son bandeau ont exactement la meme garde :
        // membre actif de la bonne Organization, ou super-admin plateforme.
        if (! $this->userCanGenerate()) {
            return null;
        }

        // Reprendre la semantique de LoopEventsCard est volontaire : un
        // Evenement en cours reste vivant, un Evenement annule en sort.
        $livingEvents = app(LoopEventService::class)
            ->forLoop($this->loop)
            ->filter(fn (LoopEvent $event) => ! $event->isPast() && ! $event->isCancelled())
            ->count();

        return [
            'members' => LoopMember::where('loop_id', $this->loop->id)
                ->where('status', 'active')
                ->count(),
            'roadmap' => LoopRoadmapItem::where('loop_id', $this->loop->id)
                ->open()
                ->count(),
            'decisions' => LoopDecision::where('loop_id', $this->loop->id)->count(),
            'events' => $livingEvents,
        ];
    }
}
