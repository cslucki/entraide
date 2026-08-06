<?php

namespace App\Livewire;

use App\Models\Loop;
use App\Models\LoopMember;
use App\Services\LoopService;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * Ajouter a une Boucle des personnes qui sont deja dans l'Organization.
 *
 * Ce geste n'existait que sur l'ecran d'invitation, en POST + redirection : on
 * ne voyait pas qui venait d'etre ajoute, et la Card Membres comme l'ecran
 * d'edition ne proposaient que l'invitation par e-mail — c'est-a-dire un
 * courriel a quelqu'un qui a deja un compte a cote.
 *
 * Le composant tient les deux listes ensemble : ceux qui sont dans la Boucle et
 * ceux qu'on peut y mettre. Ajouter deplace la personne de la seconde vers la
 * premiere sous les yeux de qui clique, sans rechargement.
 *
 * Aucune regle n'est ecrite ici : les candidats et l'ajout viennent de
 * LoopService, le droit d'agir de la Policy. Le composant ne fait qu'appeler et
 * afficher.
 */
class LoopMemberPicker extends Component
{
    public Loop $loop;

    /** Le filtre local. Une Organization compte au plus quelques centaines de personnes. */
    public string $search = '';

    /** @var array<int, string> */
    public array $selected = [];

    public ?string $flash = null;

    /** Replie la liste tant qu'on ne demande pas a ajouter quelqu'un. */
    public bool $open = false;

    /**
     * La Card Membres affiche deja son trombinoscope de gouvernance, plus riche
     * que cette liste : elle ne veut que la partie « ajouter ».
     */
    public bool $showMembers = true;

    public function mount(Loop $loop, bool $open = false, bool $showMembers = true): void
    {
        $this->loop = $loop;
        $this->open = $open;
        $this->showMembers = $showMembers;
    }

    public function canManage(): bool
    {
        // Une Boucle archivee ne recrute plus. La Policy le dit deja pour
        // l'ecriture ; l'ecran ne doit pas proposer un geste qui sera refuse.
        return ! $this->loop->isArchived()
            && auth()->user()?->can('manageJoinRequests', $this->loop);
    }

    public function toggleOpen(): void
    {
        $this->open = ! $this->open;
        $this->flash = null;
    }

    public function add(LoopService $loops): void
    {
        $this->authorize('manageJoinRequests', $this->loop);

        if ($this->loop->isArchived() || $this->selected === []) {
            return;
        }

        // Re-cadrage serveur : quoi qu'ait envoye le navigateur, seuls les
        // candidats reels de CETTE Organization sont retenus.
        $userIds = $loops->invitableOrganizationMembers($this->loop)
            ->whereIn('id', $this->selected)
            ->pluck('id')
            ->all();

        $result = $loops->addMembersFromOrganization($this->loop, $userIds, auth()->user());

        $this->selected = [];
        $this->search = '';
        $this->flash = trans_choice('loops.invite_members_added', $result['added'], ['count' => $result['added']]);

        // Le reste de l'ecran (le compteur du bandeau, la Card) se rafraichit.
        $this->dispatch('loop-members-changed');
    }

    /** @return Collection<int, LoopMember> */
    public function members(): Collection
    {
        return LoopMember::query()
            ->with('user')
            ->where('loop_id', $this->loop->id)
            ->where('status', 'active')
            ->get()
            ->sortBy(fn (LoopMember $m) => $m->user?->publicDisplayName() ?? '')
            ->values();
    }

    public function render(LoopService $loops)
    {
        $candidates = $this->canManage()
            ? $loops->invitableOrganizationMembers($this->loop)
            : collect();

        $needle = trim(mb_strtolower($this->search));

        if ($needle !== '') {
            $candidates = $candidates->filter(
                fn ($user) => str_contains(mb_strtolower($user->publicDisplayName()), $needle)
            )->values();
        }

        return view('livewire.loop-member-picker', [
            'members' => $this->showMembers ? $this->members() : collect(),
            'candidates' => $candidates,
            'manageable' => $this->canManage(),
        ]);
    }
}
