<?php

namespace App\Services\Loops;

use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\LoopPoll;
use App\Models\LoopPollOption;
use App\Models\LoopPollVote;
use App\Models\LoopPollVoteOption;
use App\Models\User;
use App\Support\Loops\LoopPermissionResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tout ce qu'on peut faire d'un Sondage — et rien d'autre.
 *
 * Le composant Livewire n'ecrit jamais dans les tables : il appelle ici. Les
 * regles qui comptent — une voix par personne, plus de modification apres le
 * premier vote, rien de supprimable une fois vote — ne peuvent donc pas etre
 * contournees par un second appelant, ni par une route directe.
 *
 * Trois verifications precedent chaque ecriture, dans cet ordre : la permission
 * (qui refuse deja les Boucles archivees et les Cards desactivees, depuis
 * TASK-1086), l'appartenance a la Boucle, et l'etat du Sondage.
 */
class LoopPollService
{
    public function __construct(private LoopPermissionResolver $permissions) {}

    // ── Lectures d'autorisation ─────────────────────────────────────────────

    public function canView(User $user, Loop $loop): bool
    {
        return $this->permissions->can($user, $loop, 'polls.view');
    }

    public function canCreate(User $user, Loop $loop): bool
    {
        return $this->permissions->can($user, $loop, 'polls.create');
    }

    public function canVote(User $user, Loop $loop): bool
    {
        return $this->permissions->can($user, $loop, 'polls.vote');
    }

    /**
     * Piloter *ce* Sondage : le modifier, le clore, le supprimer.
     *
     * Son auteur peut le faire — c'est sa question. Les personnes qui portent
     * `polls.manage` le peuvent sur tous les Sondages de la Boucle.
     */
    public function canManagePoll(User $user, LoopPoll $poll, Loop $loop): bool
    {
        if ($this->permissions->can($user, $loop, 'polls.manage')) {
            return true;
        }

        return $poll->created_by === $user->id
            && $this->permissions->can($user, $loop, 'polls.create');
    }

    /**
     * Qui voit les resultats, et quand.
     *
     * Un membre ordinaire les decouvre apres avoir vote : sinon le premier
     * resultat affiche oriente tous les suivants. Ceux qui pilotent le Sondage
     * les voient en direct, parce qu'ils doivent decider quand clore. Une fois
     * clos, il n'y a plus rien a influencer : tout le monde voit.
     */
    public function canSeeResults(User $user, LoopPoll $poll, Loop $loop): bool
    {
        if (! $this->canView($user, $loop)) {
            return false;
        }

        if ($poll->isClosed() || $this->canManagePoll($user, $poll, $loop)) {
            return true;
        }

        return $this->voteOf($user, $poll) !== null;
    }

    // ── Ecritures ───────────────────────────────────────────────────────────

    /**
     * @param  array<int, string>  $labels
     *
     * @throws PollException
     */
    public function create(User $user, Loop $loop, string $question, ?string $description, string $selectionType, array $labels): LoopPoll
    {
        $this->assert($this->canCreate($user, $loop), 'polls.error_not_allowed');
        $this->assert($this->isActiveMember($user, $loop), 'polls.error_not_member');

        $question = trim($question);
        $this->assert($question !== '', 'polls.error_question_required');
        $this->assert(in_array($selectionType, LoopPoll::TYPES, true), 'polls.error_selection_type');

        $labels = $this->cleanLabels($labels);

        return DB::transaction(function () use ($user, $loop, $question, $description, $selectionType, $labels) {
            $poll = LoopPoll::create([
                'organization_id' => $loop->organization_id,
                'loop_id' => $loop->id,
                'created_by' => $user->id,
                'question' => Str::limit($question, 500, ''),
                'description' => $description !== null && trim($description) !== '' ? trim($description) : null,
                'selection_type' => $selectionType,
                'status' => LoopPoll::STATUS_OPEN,
            ]);

            $this->writeOptions($poll, $labels);

            return $poll->fresh(['options']);
        });
    }

    /**
     * Modifier un Sondage tant que personne n'a vote.
     *
     * @param  array<int, string>  $labels
     *
     * @throws PollException
     */
    public function update(User $user, LoopPoll $poll, Loop $loop, string $question, ?string $description, string $selectionType, array $labels): LoopPoll
    {
        $this->assert($this->canManagePoll($user, $poll, $loop), 'polls.error_not_allowed');
        $this->assert($poll->isOpen(), 'polls.error_closed');

        $question = trim($question);
        $this->assert($question !== '', 'polls.error_question_required');
        $this->assert(in_array($selectionType, LoopPoll::TYPES, true), 'polls.error_selection_type');

        $labels = $this->cleanLabels($labels);

        return DB::transaction(function () use ($poll, $question, $description, $selectionType, $labels) {
            // Relu sous verrou, et le compte des voix avec lui : sans cela, un
            // vote qui arrive pendant la modification passerait sur une question
            // qui n'est deja plus la sienne.
            $fresh = LoopPoll::whereKey($poll->id)->lockForUpdate()->firstOrFail();

            $this->assert($fresh->isOpen(), 'polls.error_closed');
            $this->assert(! $fresh->hasVotes(), 'polls.error_already_voted');

            $fresh->update([
                'question' => Str::limit($question, 500, ''),
                'description' => $description !== null && trim($description) !== '' ? trim($description) : null,
                'selection_type' => $selectionType,
            ]);

            // Aucune voix n'existe : remplacer les options ne detruit rien.
            $fresh->options()->delete();
            $this->writeOptions($fresh, $labels);

            return $fresh->fresh(['options']);
        });
    }

    /**
     * Voter, ou changer d'avis.
     *
     * @param  array<int, string>  $optionIds
     *
     * @throws PollException
     */
    public function vote(User $user, LoopPoll $poll, Loop $loop, array $optionIds): LoopPollVote
    {
        $this->assert($this->canVote($user, $loop), 'polls.error_not_allowed');
        $this->assert($this->isActiveMember($user, $loop), 'polls.error_not_member');

        $optionIds = array_values(array_unique(array_filter($optionIds)));
        $this->assert($optionIds !== [], 'polls.error_no_choice');

        return DB::transaction(function () use ($user, $poll, $optionIds) {
            $fresh = LoopPoll::whereKey($poll->id)->lockForUpdate()->firstOrFail();

            // Relu sous verrou : un vote qui part au moment de la cloture ne
            // doit pas se glisser entre la lecture du statut et l'ecriture.
            $this->assert($fresh->isOpen(), 'polls.error_closed');
            $this->assert(
                $fresh->allowsMultiple() || count($optionIds) === 1,
                'polls.error_single_choice',
            );

            // Les options doivent etre celles de CE Sondage : un identifiant
            // force renvoie ici, pas dans le depouillement.
            $valid = LoopPollOption::where('poll_id', $fresh->id)
                ->whereIn('id', $optionIds)
                ->pluck('id')
                ->all();

            $this->assert(count($valid) === count($optionIds), 'polls.error_unknown_option');

            // `firstOrCreate` sur la contrainte d'unicite : deux votes
            // simultanes de la meme personne convergent vers un seul objet.
            $vote = LoopPollVote::firstOrCreate(
                ['poll_id' => $fresh->id, 'user_id' => $user->id],
                ['organization_id' => $fresh->organization_id],
            );

            // Remplacer, pas ajouter : changer d'avis, c'est repartir de zero.
            LoopPollVoteOption::where('vote_id', $vote->id)->delete();

            foreach ($valid as $optionId) {
                LoopPollVoteOption::create(['vote_id' => $vote->id, 'option_id' => $optionId]);
            }

            return $vote->fresh(['options']);
        });
    }

    /** @throws PollException */
    public function close(User $user, LoopPoll $poll, Loop $loop): LoopPoll
    {
        $this->assert($this->canManagePoll($user, $poll, $loop), 'polls.error_not_allowed');

        return DB::transaction(function () use ($user, $poll) {
            $fresh = LoopPoll::whereKey($poll->id)->lockForUpdate()->firstOrFail();

            // Une seconde cloture n'est pas une erreur a signaler : le Sondage
            // est deja dans l'etat voulu.
            if ($fresh->isClosed()) {
                return $fresh;
            }

            $fresh->update([
                'status' => LoopPoll::STATUS_CLOSED,
                'closed_at' => now(),
                'closed_by' => $user->id,
            ]);

            return $fresh;
        });
    }

    /**
     * Supprimer un Sondage que personne n'a encore vote.
     *
     * Des qu'une voix existe, le Sondage se clot et se conserve : effacer
     * effacerait le vote de quelqu'un d'autre.
     *
     * @throws PollException
     */
    public function delete(User $user, LoopPoll $poll, Loop $loop): void
    {
        $this->assert($this->canManagePoll($user, $poll, $loop), 'polls.error_not_allowed');

        DB::transaction(function () use ($poll) {
            $fresh = LoopPoll::whereKey($poll->id)->lockForUpdate()->firstOrFail();

            $this->assert(! $fresh->hasVotes(), 'polls.error_delete_voted');

            $fresh->delete();
        });
    }

    // ── Depouillement ───────────────────────────────────────────────────────

    /**
     * Le resultat tel qu'on l'affiche.
     *
     * `participants` est le nombre de **votants uniques**, pas la somme des
     * choix : en choix multiple les deux different, et c'est le premier qui
     * repond a « combien de personnes se sont prononcees ». Les pourcentages
     * s'y rapportent, donc ils peuvent depasser 100 % cumules — c'est correct.
     *
     * @return array{participants: int, options: array<int, array<string, mixed>>}
     */
    public function results(LoopPoll $poll): array
    {
        $participants = LoopPollVote::where('poll_id', $poll->id)->count();

        $counts = LoopPollVoteOption::query()
            ->join('loop_poll_votes', 'loop_poll_votes.id', '=', 'loop_poll_vote_options.vote_id')
            ->where('loop_poll_votes.poll_id', $poll->id)
            ->selectRaw('loop_poll_vote_options.option_id, count(*) as total')
            ->groupBy('loop_poll_vote_options.option_id')
            ->pluck('total', 'option_id');

        $options = $poll->options()->get()->map(function (LoopPollOption $option) use ($counts, $participants) {
            $votes = (int) ($counts[$option->id] ?? 0);

            return [
                'id' => $option->id,
                'label' => $option->label,
                'votes' => $votes,
                'percentage' => $participants > 0 ? (int) round($votes * 100 / $participants) : 0,
            ];
        })->all();

        return ['participants' => $participants, 'options' => $options];
    }

    /**
     * Qui a vote quoi.
     *
     * Charge a la demande, jamais a l'ouverture de la Card : une Boucle de deux
     * cents personnes n'a pas a payer ce chargement pour lire une question.
     *
     * @return array<int, array{name: string, options: array<int, string>}>
     */
    public function voterDetail(LoopPoll $poll): array
    {
        return LoopPollVote::where('poll_id', $poll->id)
            ->with(['user', 'options'])
            ->get()
            ->map(fn (LoopPollVote $vote) => [
                'name' => $vote->user?->publicDisplayName() ?? __('polls.unknown_voter'),
                'options' => $vote->options->pluck('label')->all(),
            ])
            ->sortBy('name')
            ->values()
            ->all();
    }

    public function voteOf(User $user, LoopPoll $poll): ?LoopPollVote
    {
        return LoopPollVote::where('poll_id', $poll->id)
            ->where('user_id', $user->id)
            ->with('options')
            ->first();
    }

    // ── Interne ─────────────────────────────────────────────────────────────

    private function isActiveMember(User $user, Loop $loop): bool
    {
        return LoopMember::where('loop_id', $loop->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->exists();
    }

    /**
     * Nettoyer les reponses proposees : ni vide, ni deux fois la meme.
     *
     * La comparaison ignore la casse et les espaces de bord — « Oui » et
     * « oui » sont la meme reponse, et laisser les deux rendrait le
     * depouillement incomprehensible.
     *
     * @param  array<int, mixed>  $labels
     * @return array<int, string>
     *
     * @throws PollException
     */
    private function cleanLabels(array $labels): array
    {
        $seen = [];
        $clean = [];

        foreach ($labels as $label) {
            if (! is_string($label)) {
                continue;
            }

            $label = trim($label);

            if ($label === '') {
                continue;
            }

            $key = mb_strtolower($label);

            if (isset($seen[$key])) {
                throw new PollException(__('polls.error_duplicate_option'));
            }

            $seen[$key] = true;
            $clean[] = Str::limit($label, 255, '');
        }

        $this->assert(count($clean) >= LoopPoll::MIN_OPTIONS, 'polls.error_min_options');
        $this->assert(count($clean) <= LoopPoll::MAX_OPTIONS, 'polls.error_max_options');

        return $clean;
    }

    /** @param array<int, string> $labels */
    private function writeOptions(LoopPoll $poll, array $labels): void
    {
        foreach ($labels as $index => $label) {
            LoopPollOption::create([
                'poll_id' => $poll->id,
                'label' => $label,
                'position' => $index,
            ]);
        }
    }

    /** @throws PollException */
    private function assert(bool $condition, string $translationKey): void
    {
        if (! $condition) {
            throw new PollException(__($translationKey));
        }
    }
}
