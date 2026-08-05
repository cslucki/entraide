<?php

namespace App\Livewire;

use App\Models\Loop;
use App\Models\LoopPoll;
use App\Services\Loops\LoopPollService;
use App\Services\Loops\PollException;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * La Card Sondages d'une Boucle.
 *
 * Le composant ne connait aucune regle metier : il appelle LoopPollService et
 * affiche ce qu'il repond, y compris ses refus. C'est deliberé — les regles qui
 * comptent doivent tenir aussi bien pour une route appelee directement que pour
 * un clic, et elles ne peuvent le faire que si elles vivent d'un seul cote.
 *
 * Le detail nominatif n'est charge que lorsqu'on le demande : une Boucle de deux
 * cents personnes n'a pas a payer ce chargement pour lire une question.
 */
class LoopPollsCard extends Component
{
    public Loop $loop;

    // ── Creation et modification ────────────────────────────────────────────

    public bool $showForm = false;

    /** Nul en creation, l'identifiant du Sondage en modification. */
    public ?string $editingId = null;

    public string $question = '';

    public string $description = '';

    public string $selectionType = LoopPoll::TYPE_SINGLE;

    /** @var array<int, string> */
    public array $options = ['', ''];

    // ── Vote ────────────────────────────────────────────────────────────────

    /**
     * Le brouillon de vote, par Sondage.
     *
     * Toujours un tableau d'identifiants, meme en choix unique : cela evite deux
     * formes a gerer dans la vue et dans le service.
     *
     * @var array<string, array<int, string>>
     */
    public array $draftVotes = [];

    /** @var array<string, bool> Sondages dont on a ouvert le mode vote. */
    public array $editingVote = [];

    /** @var array<string, bool> Sondages dont on a deplie le detail nominatif. */
    public array $showDetail = [];

    public ?string $confirmingCloseId = null;

    public ?string $confirmingDeleteId = null;

    public ?string $errorMessage = null;

    public ?string $successMessage = null;

    private function service(): LoopPollService
    {
        return app(LoopPollService::class);
    }

    private function user()
    {
        return auth()->user();
    }

    // ── Formulaire ──────────────────────────────────────────────────────────

    public function openCreateForm(): void
    {
        $this->resetMessages();

        if (! $this->service()->canCreate($this->user(), $this->loop)) {
            $this->errorMessage = __('polls.error_not_allowed');

            return;
        }

        $this->editingId = null;
        $this->question = '';
        $this->description = '';
        $this->selectionType = LoopPoll::TYPE_SINGLE;
        $this->options = ['', ''];
        $this->showForm = true;
    }

    public function openEditForm(string $pollId): void
    {
        $this->resetMessages();

        $poll = $this->resolvePoll($pollId);

        if (! $poll || ! $this->service()->canManagePoll($this->user(), $poll, $this->loop)) {
            $this->errorMessage = __('polls.error_not_allowed');

            return;
        }

        if ($poll->hasVotes()) {
            $this->errorMessage = __('polls.error_already_voted');

            return;
        }

        $this->editingId = $poll->id;
        $this->question = $poll->question;
        $this->description = (string) $poll->description;
        $this->selectionType = $poll->selection_type;
        $this->options = $poll->options->pluck('label')->all();
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->editingId = null;
        $this->resetMessages();
    }

    public function addOption(): void
    {
        if (count($this->options) >= LoopPoll::MAX_OPTIONS) {
            $this->errorMessage = __('polls.error_max_options');

            return;
        }

        $this->options[] = '';
    }

    public function removeOption(int $index): void
    {
        if (count($this->options) <= LoopPoll::MIN_OPTIONS) {
            $this->errorMessage = __('polls.error_min_options');

            return;
        }

        unset($this->options[$index]);
        $this->options = array_values($this->options);
    }

    public function save(): void
    {
        $this->resetMessages();

        try {
            if ($this->editingId !== null) {
                $poll = $this->resolvePoll($this->editingId);

                if (! $poll) {
                    $this->errorMessage = __('polls.error_not_allowed');

                    return;
                }

                $this->service()->update(
                    $this->user(), $poll, $this->loop,
                    $this->question, $this->description, $this->selectionType, $this->options,
                );
            } else {
                $poll = $this->service()->create(
                    $this->user(), $this->loop,
                    $this->question, $this->description, $this->selectionType, $this->options,
                );

                $this->announceCreation($poll);
            }
        } catch (PollException $e) {
            // Le message du service est deja traduit et destine a etre lu.
            $this->errorMessage = $e->getMessage();

            return;
        }

        $this->showForm = false;
        $this->editingId = null;
    }

    // ── Vote ────────────────────────────────────────────────────────────────

    public function startVote(string $pollId): void
    {
        $this->resetMessages();

        $poll = $this->resolvePoll($pollId);

        if (! $poll) {
            return;
        }

        // Le vote en cours devient le brouillon : on modifie plutot que de
        // repartir d'une ardoise vide.
        $existing = $this->service()->voteOf($this->user(), $poll);

        $this->draftVotes[$pollId] = $existing
            ? $existing->options->pluck('id')->all()
            : [];

        $this->editingVote[$pollId] = true;
    }

    public function cancelVote(string $pollId): void
    {
        unset($this->editingVote[$pollId], $this->draftVotes[$pollId]);
        $this->resetMessages();
    }

    /**
     * En choix unique, selectionner remplace ; en choix multiple, cela bascule.
     */
    public function toggleChoice(string $pollId, string $optionId): void
    {
        $poll = $this->resolvePoll($pollId);

        if (! $poll) {
            return;
        }

        if (! $poll->allowsMultiple()) {
            $this->draftVotes[$pollId] = [$optionId];

            return;
        }

        $current = $this->draftVotes[$pollId] ?? [];

        $this->draftVotes[$pollId] = in_array($optionId, $current, true)
            ? array_values(array_diff($current, [$optionId]))
            : array_values(array_merge($current, [$optionId]));
    }

    public function submitVote(string $pollId): void
    {
        $this->resetMessages();

        $poll = $this->resolvePoll($pollId);

        if (! $poll) {
            return;
        }

        try {
            $this->service()->vote($this->user(), $poll, $this->loop, $this->draftVotes[$pollId] ?? []);
        } catch (PollException $e) {
            $this->errorMessage = $e->getMessage();

            return;
        }

        unset($this->editingVote[$pollId]);
        $this->successMessage = __('polls.voted_confirmation');
    }

    // ── Cloture et suppression ──────────────────────────────────────────────

    public function confirmClose(string $pollId): void
    {
        $this->resetMessages();
        $this->confirmingCloseId = $pollId;
    }

    public function confirmDelete(string $pollId): void
    {
        $this->resetMessages();
        $this->confirmingDeleteId = $pollId;
    }

    public function cancelConfirmation(): void
    {
        $this->confirmingCloseId = null;
        $this->confirmingDeleteId = null;
    }

    public function close(string $pollId): void
    {
        $this->resetMessages();
        $this->confirmingCloseId = null;

        $poll = $this->resolvePoll($pollId);

        if (! $poll) {
            return;
        }

        try {
            $wasOpen = $poll->isOpen();
            $this->service()->close($this->user(), $poll, $this->loop);

            if ($wasOpen) {
                $this->announceClosure($poll);
            }
        } catch (PollException $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    public function delete(string $pollId): void
    {
        $this->resetMessages();
        $this->confirmingDeleteId = null;

        $poll = $this->resolvePoll($pollId);

        if (! $poll) {
            return;
        }

        try {
            $this->service()->delete($this->user(), $poll, $this->loop);
            $this->successMessage = __('polls.deleted');
        } catch (PollException $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    // ── Detail nominatif ────────────────────────────────────────────────────

    public function toggleDetail(string $pollId): void
    {
        $this->showDetail[$pollId] = ! ($this->showDetail[$pollId] ?? false);
    }

    // ── Rendu ───────────────────────────────────────────────────────────────

    public function render()
    {
        $user = $this->user();
        $service = $this->service();

        if (! $user || ! $service->canView($user, $this->loop)) {
            return view('livewire.loop-polls-card', [
                'polls' => collect(),
                'canCreate' => false,
                'canVote' => false,
                'readOnly' => true,
            ]);
        }

        $polls = LoopPoll::where('loop_id', $this->loop->id)
            ->with(['options', 'creator'])
            ->withCount('votes')
            // Les ouverts d'abord, les plus recents en tete de chaque groupe.
            ->orderByRaw("case when status = 'open' then 0 else 1 end")
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (LoopPoll $poll) => $this->present($poll, $user, $service));

        return view('livewire.loop-polls-card', [
            'polls' => $polls,
            'canCreate' => $service->canCreate($user, $this->loop),
            'canVote' => $service->canVote($user, $this->loop),
            // Une Boucle archivee refuse `polls.create` et `polls.vote` : la
            // lecture seule se deduit du resolveur, elle n'est pas recalculee.
            'readOnly' => ! $service->canCreate($user, $this->loop) && ! $service->canVote($user, $this->loop),
        ]);
    }

    /**
     * Ce dont la vue a besoin pour un Sondage, et rien de plus.
     *
     * @return array<string, mixed>
     */
    private function present(LoopPoll $poll, $user, LoopPollService $service): array
    {
        $seesResults = $service->canSeeResults($user, $poll, $this->loop);
        $myVote = $service->voteOf($user, $poll);

        return [
            'model' => $poll,
            'id' => $poll->id,
            'question' => $poll->question,
            'description' => $poll->description,
            'is_open' => $poll->isOpen(),
            'multiple' => $poll->allowsMultiple(),
            'author' => $poll->creator?->publicDisplayName() ?? __('polls.unknown_author'),
            'created_at' => $poll->created_at,
            'closed_at' => $poll->closed_at,
            'participants' => $poll->votes_count,
            'options' => $poll->options,
            'can_manage' => $service->canManagePoll($user, $poll, $this->loop),
            'can_edit' => $service->canManagePoll($user, $poll, $this->loop)
                && $poll->isOpen() && $poll->votes_count === 0,
            'can_delete' => $service->canManagePoll($user, $poll, $this->loop) && $poll->votes_count === 0,
            'sees_results' => $seesResults,
            'results' => $seesResults ? $service->results($poll) : null,
            'my_option_ids' => $myVote ? $myVote->options->pluck('id')->all() : [],
            'my_option_labels' => $myVote ? $myVote->options->pluck('label')->all() : [],
            // Charge seulement si le detail est deplie, et seulement pour qui a
            // le droit de voir les resultats.
            'detail' => ($this->showDetail[$poll->id] ?? false) && $seesResults
                ? $service->voterDetail($poll)
                : null,
        ];
    }

    private function resolvePoll(string $pollId): ?LoopPoll
    {
        // Re-porte sur la Boucle et l'Organization : jamais de confiance dans un
        // identifiant venu du navigateur.
        return LoopPoll::where('id', $pollId)
            ->where('loop_id', $this->loop->id)
            ->where('organization_id', $this->loop->organization_id)
            ->with('options')
            ->first();
    }

    private function resetMessages(): void
    {
        $this->errorMessage = null;
        $this->successMessage = null;
    }

    /**
     * Annoncer dans ChatLoop, sans jamais empecher le Sondage d'exister.
     *
     * Le message est un accessoire : s'il echoue, le Sondage reste. On le laisse
     * donc hors de la transaction du service, et on avale l'erreur.
     */
    private function announceCreation(LoopPoll $poll): void
    {
        try {
            app(\App\Services\LoopMessageService::class)
                ->sendPollEventMessage($this->loop, $this->user(), $poll, 'created');
        } catch (\Throwable) {
            // Silencieux : l'utilisateur a pose sa question, c'est l'essentiel.
        }
    }

    private function announceClosure(LoopPoll $poll): void
    {
        try {
            app(\App\Services\LoopMessageService::class)
                ->sendPollEventMessage($this->loop, $this->user(), $poll, 'closed');
        } catch (\Throwable) {
        }
    }
}
