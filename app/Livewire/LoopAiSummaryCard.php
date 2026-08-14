<?php

namespace App\Livewire;

use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\User;
use App\Services\ChatLoop\ChatLoopAiService;
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
        return view('livewire.loop-ai-summary-card');
    }
}
