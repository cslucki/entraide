<?php

namespace App\Livewire;

use App\Models\Loop;
use App\Models\LoopDecision;
use App\Models\LoopEvent;
use App\Models\LoopEventResponse;
use App\Models\LoopMember;
use App\Models\LoopPoll;
use App\Models\LoopRoadmapItem;
use App\Models\User;
use App\Services\ChatLoop\ChatLoopAiService;
use App\Services\Loops\LoopDecisionService;
use App\Services\Loops\LoopEventService;
use App\Services\Loops\LoopPollService;
use App\Support\Ai\AiRefusedException;
use App\Support\Loops\LoopCardRegistry;
use App\Support\Loops\LoopPermissionResolver;
use Illuminate\Support\Collection;
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
        $user = auth()->user();
        $visibleKeys = ($user && $this->userCanGenerate())
            ? $this->visibleCardKeys($user)
            : null;

        return view('livewire.loop-ai-summary-card', [
            'pulse' => $visibleKeys ? $this->loopPulse($visibleKeys) : null,
            'nba' => ($visibleKeys && $user) ? $this->nextBestActions($visibleKeys, $user) : [],
        ]);
    }

    /**
     * Composition active de cette Boucle + view_permission de cette personne,
     * calculees UNE SEULE FOIS par rendu (registre rejoue via
     * `LoopPermissionResolver` en interne) et partagees entre Pulse et NBA —
     * jamais un second appel identique au registre.
     *
     * @return Collection<string, int>
     */
    private function visibleCardKeys(User $user): Collection
    {
        $registry = app(LoopCardRegistry::class);

        return $registry->workspaceCardsFor($this->loop, $user)
            ->concat($registry->frameCardsFor($this->loop, $user))
            ->pluck('key')
            ->flip();
    }

    /**
     * Etat metier partage par les Cards de la Boucle, sans inference IA.
     *
     * @param  Collection<string, int>  $visibleKeys
     * @return array<string, int>
     */
    private function loopPulse(Collection $visibleKeys): array
    {
        $pulse = [];

        if ($visibleKeys->has('core.members')) {
            $pulse['members'] = LoopMember::where('loop_id', $this->loop->id)
                ->where('status', 'active')
                ->count();
        }

        if ($visibleKeys->has('core.roadmap')) {
            $pulse['roadmap'] = LoopRoadmapItem::where('loop_id', $this->loop->id)
                ->open()
                ->count();
        }

        if ($visibleKeys->has('core.decisions')) {
            $pulse['decisions'] = LoopDecision::where('loop_id', $this->loop->id)->count();
        }

        if ($visibleKeys->has('core.polls')) {
            // Meme regle que LoopPollsCard::present() -> LoopPoll::isOpen().
            $pulse['polls'] = LoopPoll::where('loop_id', $this->loop->id)
                ->where('status', LoopPoll::STATUS_OPEN)
                ->count();
        }

        if ($visibleKeys->has('core.events')) {
            // Reprendre la semantique de LoopEventsCard est volontaire : un
            // Evenement en cours reste vivant, un Evenement annule en sort.
            $pulse['events'] = app(LoopEventService::class)
                ->forLoop($this->loop)
                ->filter(fn (LoopEvent $event) => ! $event->isPast() && ! $event->isCancelled())
                ->count();
        }

        return $pulse;
    }

    /**
     * TASK-1339 : « Qu'est-ce qu'il serait utile de faire maintenant dans
     * cette Boucle ? ». Deterministe, zero appel IA, zero score — un item
     * au plus par famille, ordre fixe decision -> sondage -> evenement ->
     * roadmap (mandat). Chaque item ne s'affiche que si l'action est REELLEMENT
     * possible pour cet utilisateur : jamais un CTA mort.
     *
     * Quand une famille a plusieurs faits eligibles, le plus ancien en
     * attente est retenu (l'evenement le plus proche dans le temps) — regle
     * constante, jamais un choix arbitraire d'affichage.
     *
     * @param  Collection<string, int>  $visibleKeys
     * @return array<int, array{key: string, card: string, label: string, cta: string}>
     */
    private function nextBestActions(Collection $visibleKeys, User $user): array
    {
        $resolver = app(LoopPermissionResolver::class);
        $items = [];

        if ($visibleKeys->has('core.decisions')
            && $resolver->can($user, $this->loop, 'decisions.record')
            && $resolver->can($user, $this->loop, 'roadmap.manage')) {
            $decision = app(LoopDecisionService::class)->decisionsFor($this->loop)
                ->filter(fn (LoopDecision $d) => ! $d->isSuperseded() && $d->actions->isEmpty())
                ->sortBy('created_at')
                ->first();

            if ($decision) {
                $items[] = [
                    'key' => 'decision',
                    'card' => 'core.decisions',
                    'label' => __('loops.nba.decision', ['title' => $decision->title]),
                    'cta' => __('loops.nba.decision_cta'),
                ];
            }
        }

        if ($visibleKeys->has('core.polls') && app(LoopPollService::class)->canVote($user, $this->loop)) {
            $poll = LoopPoll::where('loop_id', $this->loop->id)
                ->where('status', LoopPoll::STATUS_OPEN)
                ->whereDoesntHave('votes', fn ($q) => $q->where('user_id', $user->id))
                ->orderBy('created_at')
                ->first();

            if ($poll) {
                $items[] = [
                    'key' => 'poll',
                    'card' => 'core.polls',
                    'label' => __('loops.nba.poll', ['question' => $poll->question]),
                    'cta' => __('loops.nba.poll_cta'),
                ];
            }
        }

        if ($visibleKeys->has('core.events')) {
            $eventService = app(LoopEventService::class);

            $event = $eventService->forLoop($this->loop)
                ->filter(fn (LoopEvent $e) => ! $e->isPast() && ! $e->isCancelled())
                ->filter(fn (LoopEvent $e) => $eventService->canRespondTo($user, $e, $this->loop))
                ->reject(fn (LoopEvent $e) => LoopEventResponse::where('event_id', $e->id)
                    ->where('user_id', $user->id)
                    ->exists())
                ->sortBy(fn (LoopEvent $e) => $e->starts_at)
                ->first();

            if ($event) {
                $items[] = [
                    'key' => 'event',
                    'card' => 'core.events',
                    'label' => __('loops.nba.event', [
                        'title' => $event->title,
                        'date' => $event->starts_at->isoFormat('LL'),
                    ]),
                    'cta' => __('loops.nba.event_cta'),
                ];
            }
        }

        if ($visibleKeys->has('core.roadmap')) {
            $roadmapItem = LoopRoadmapItem::where('loop_id', $this->loop->id)
                ->open()
                ->whereHas('assignees', fn ($q) => $q->where('users.id', $user->id))
                ->orderBy('created_at')
                ->first();

            if ($roadmapItem) {
                $items[] = [
                    'key' => 'roadmap',
                    'card' => 'core.roadmap',
                    'label' => __('loops.nba.roadmap', [
                        'title' => $roadmapItem->title,
                        'status' => __('loops.roadmap_status_'.$roadmapItem->status),
                    ]),
                    'cta' => __('loops.nba.roadmap_cta'),
                ];
            }
        }

        return $items;
    }
}
