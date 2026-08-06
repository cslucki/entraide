<?php

namespace App\Livewire;

use App\Models\Loop;
use App\Models\LoopInvitation;
use App\Models\LoopJoinRequest;
use App\Models\LoopMember;
use App\Models\User;
use App\Services\LoopInvitationService;
use App\Services\Loops\LoopInvitationMailer;
use App\Services\LoopService;
use App\Support\Loops\LoopPermissionResolver;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * La Card Membres d'une Boucle.
 *
 * Tout ce qui touche aux personnes d'une Boucle tient ici : qui en fait partie,
 * qui peut y entrer, ce qui a ete envoye. Le trombinoscope est dans le composant
 * et non a cote, sinon ajouter quelqu'un ne se voit qu'au rechargement suivant —
 * c'etait le reproche principal.
 *
 * Un seul champ de recherche sert les deux gestes : on tape un nom, on trouve
 * quelqu'un de l'Organization ; on tape une adresse inconnue, on se voit
 * proposer de l'inviter. La personne qui cherche n'a pas a savoir d'avance
 * laquelle des deux mecaniques s'applique.
 *
 * Aucune regle n'est ecrite ici. Les candidats et l'ajout viennent de
 * LoopService, l'invitation de LoopInvitationService et de son mailer, les
 * droits du resolveur et de la Policy.
 */
class LoopMembersCard extends Component
{
    public const TAB_MEMBERS = 'members';

    public const TAB_INVITATIONS = 'invitations';

    public Loop $loop;

    public string $tab = self::TAB_MEMBERS;

    /**
     * Tout d'un coup, sans onglets.
     *
     * Les onglets servent le panneau lateral du workspace, ou la place manque.
     * L'ecran qui suit la creation d'une Boucle est une page entiere dont le
     * sujet est justement « qui va la rejoindre » : y cacher les invitations
     * derriere un onglet ferait perdre ce qu'on vient d'y faire.
     */
    public bool $flat = false;

    /** Le champ unique : un nom a filtrer, ou une adresse a inviter. */
    public string $search = '';

    /** @var array<int, string> */
    public array $selected = [];

    /**
     * L'ajout est le geste courant : il est ouvert d'entree et pose en premier.
     * L'invitation par courriel, plus rare et plus lente, attend qu'on la
     * demande.
     */
    public bool $openPicker = true;

    public bool $openEmail = false;

    public string $inviteEmail = '';

    public string $inviteName = '';

    /**
     * Les personnes ajoutees au dernier clic, nommees.
     *
     * « 1 personne ajoutee » ne dit pas laquelle : quand on en coche trois d'un
     * coup, c'est precisement ce qu'on veut relire.
     *
     * @var array<int, array{id: string, name: string}>
     */
    public array $justAdded = [];

    public ?string $errorMessage = null;

    public ?string $noticeMessage = null;

    /**
     * L'adresse a partager, figee au montage.
     *
     * Les requetes suivantes de Livewire passent par /livewire/update : le
     * parametre d'Organization de la route d'origine n'y est plus, et la
     * reconstruire a chaque rendu produirait une URL hors tenant.
     */
    public string $shareUrl = '';

    public function mount(Loop $loop, bool $flat = false): void
    {
        $this->loop = $loop;
        $this->flat = $flat;

        $organization = request()->route('organization');

        $this->shareUrl = ($organization && \Illuminate\Support\Facades\Route::has('organization.loops.show'))
            ? route('organization.loops.show', ['organization' => $organization, 'loop' => $loop])
            : route('loops.show', $loop);
    }

    // ── Droits ──────────────────────────────────────────────────────────────

    public function canManage(): bool
    {
        // Une Boucle archivee ne recrute plus. La Policy le dit deja pour
        // l'ecriture ; l'ecran ne doit pas proposer un geste qui sera refuse.
        return ! $this->loop->isArchived()
            && (bool) auth()->user()?->can('manageJoinRequests', $this->loop);
    }

    public function canInviteByEmail(): bool
    {
        return ! $this->loop->isArchived()
            && (bool) auth()->user()?->can('update', $this->loop);
    }

    // ── Navigation ──────────────────────────────────────────────────────────

    public function selectTab(string $tab): void
    {
        $this->tab = $tab === self::TAB_INVITATIONS ? self::TAB_INVITATIONS : self::TAB_MEMBERS;
        $this->clearMessages();
    }

    public function togglePicker(): void
    {
        $this->openPicker = ! $this->openPicker;
        $this->selected = [];
    }

    /**
     * Une coche ne survit pas au filtre qui l'a fait disparaitre.
     *
     * Sans cela, cocher quelqu'un puis retaper la recherche laisse une
     * selection invisible : le prochain « Ajouter » ferait entrer une personne
     * que plus rien a l'ecran ne designait.
     */
    public function updatedSearch(): void
    {
        $this->selected = [];
    }

    public function toggleEmail(): void
    {
        $this->openEmail = ! $this->openEmail;
    }

    /**
     * Le pont entre les deux gestes : l'adresse tapee dans la recherche part
     * dans le formulaire d'invitation, deplie et pret a partir.
     */
    public function inviteTyped(): void
    {
        $this->inviteEmail = trim($this->search);
        $this->openEmail = true;
        $this->search = '';
        $this->clearMessages();
    }

    // ── Ajouter depuis l'Organization ───────────────────────────────────────

    public function add(LoopService $loops): void
    {
        $this->authorize('manageJoinRequests', $this->loop);
        $this->clearMessages();

        if ($this->loop->isArchived() || $this->selected === []) {
            return;
        }

        // Re-cadrage serveur : quoi qu'ait envoye le navigateur, seuls les
        // candidats reels de CETTE Organization sont retenus.
        $candidates = $loops->invitableOrganizationMembers($this->loop)
            ->whereIn('id', $this->selected);

        $result = $loops->addMembersFromOrganization(
            $this->loop,
            $candidates->pluck('id')->all(),
            auth()->user(),
        );

        // Nommer avant d'oublier : une fois ajoutees, ces personnes ne sont
        // plus des candidates et la liste ne saurait plus les retrouver.
        $this->justAdded = $candidates
            ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->publicDisplayName()])
            ->values()
            ->all();

        if ($result['added'] === 0) {
            $this->justAdded = [];
            $this->errorMessage = __('loops.members_add_none');
        }

        $this->selected = [];
        $this->search = '';

        $this->dispatch('loop-members-changed');
    }

    // ── Inviter par courriel ────────────────────────────────────────────────

    public function sendInvitation(LoopInvitationService $invitations, LoopInvitationMailer $mailer): void
    {
        $this->authorize('update', $this->loop);
        $this->clearMessages();

        $data = $this->validate([
            'inviteEmail' => ['required', 'email', 'max:255'],
            'inviteName' => ['nullable', 'string', 'max:255'],
        ]);

        $invitation = $invitations->invite(
            $this->loop,
            auth()->user(),
            $data['inviteEmail'],
            $data['inviteName'] ?: null,
            null,
        );

        $mailer->send($invitation);

        $this->noticeMessage = __('loops.invitation_sent', ['email' => $invitation->recipient_email]);
        $this->inviteEmail = '';
        $this->inviteName = '';
        $this->openEmail = false;
        $this->tab = self::TAB_INVITATIONS;
    }

    // ── Lectures ────────────────────────────────────────────────────────────

    /** @return Collection<int, LoopMember> */
    public function members(): Collection
    {
        return LoopMember::query()
            ->with('user')
            ->where('loop_id', $this->loop->id)
            ->where('status', 'active')
            ->get();
    }

    /** @return Collection<int, LoopInvitation> */
    public function invitations(): Collection
    {
        if (! $this->canManage()) {
            return collect();
        }

        return LoopInvitation::visibleTo(auth()->user())
            ->where('loop_id', $this->loop->id)
            ->latest()
            ->get();
    }

    /** @return Collection<int, LoopJoinRequest> */
    public function joinRequests(): Collection
    {
        if (! $this->canManage()) {
            return collect();
        }

        return LoopJoinRequest::query()
            ->where('loop_id', $this->loop->id)
            ->where('status', LoopJoinRequest::STATUS_PENDING)
            ->with('user')
            ->oldest()
            ->get();
    }

    private function clearMessages(): void
    {
        $this->justAdded = [];
        $this->errorMessage = null;
        $this->noticeMessage = null;
    }

    public function render(LoopService $loops)
    {
        $needle = trim($this->search);

        $candidates = $this->canManage()
            ? $loops->invitableOrganizationMembers($this->loop)
            : collect();

        if ($needle !== '') {
            $lower = mb_strtolower($needle);
            $candidates = $candidates->filter(
                fn (User $user) => str_contains(mb_strtolower($user->publicDisplayName()), $lower)
                    || str_contains(mb_strtolower((string) $user->email), $lower)
            )->values();
        }

        // Proposer l'invitation quand ce qui est tape ressemble a une adresse et
        // ne designe personne d'ici. Sans le second test, on proposerait
        // d'inviter par courriel quelqu'un qui figure dans la liste juste en
        // dessous.
        $offerEmailInvite = $needle !== ''
            && $candidates->isEmpty()
            && $this->canInviteByEmail()
            && filter_var($needle, FILTER_VALIDATE_EMAIL) !== false;

        // La gouvernance suit les permissions resolues, jamais un libelle de
        // role lu dans Blade (TASK-1079 CP5ter).
        $resolver = app(LoopPermissionResolver::class);
        $user = auth()->user();

        return view('livewire.loop-members-card', [
            'members' => $this->members(),
            'candidates' => $candidates,
            'invitations' => $this->invitations(),
            'joinRequests' => $this->joinRequests(),
            'manageable' => $this->canManage(),
            'emailInvitable' => $this->canInviteByEmail(),
            'offerEmailInvite' => $offerEmailInvite,
            'governance' => [
                'owners' => $resolver->can($user, $this->loop, 'loops.manage_owners'),
                'facilitators' => $resolver->can($user, $this->loop, 'loops.manage_facilitators'),
                'remove' => $resolver->can($user, $this->loop, 'loop_members.remove'),
            ],
        ]);
    }
}
