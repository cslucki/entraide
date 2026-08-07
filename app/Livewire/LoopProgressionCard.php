<?php

namespace App\Livewire;

use App\Models\CourseSequence;
use App\Models\CourseSetting;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\User;
use App\Services\Loops\CourseProgressService;
use App\Support\Loops\LoopPermissionResolver;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * La Card Progression : **une seule Card a deux visages**.
 *
 * « Ma progression » pour le stagiaire, la matrice pour l'Animateur. Deux Cards
 * obligeraient a choisir laquelle montrer selon le role — ce que le socle ne
 * sait pas faire — et laisseraient une case vide a qui n'a pas le bon role.
 *
 * Aucune regle n'est tenue ici : `CourseProgressService` a les quatre regles
 * d'ouverture, les transactions et les verrous. Ce composant garde
 * l'autorisation, l'onglet courant et l'affichage.
 *
 * **L'IA n'apparait nulle part.** Aucun service d'IA n'est importe, et la
 * decision d'ouvrir une etape appartient a l'Animateur, sans exception.
 */
class LoopProgressionCard extends Component
{
    public const FACE_MINE = 'mine';

    public const FACE_EVERYONE = 'everyone';

    public Loop $loop;

    /** Le visage affiche. L'etat vit **cote serveur** : changer d'onglet doit se
     *  voir dans la meme reponse que le rendu qui suit. */
    public string $face = self::FACE_MINE;

    /** Le Module deplie dans la matrice, et la personne concernee. */
    public ?string $openModuleId = null;

    public ?string $openUserId = null;

    public string $flash = '';

    public function mount(Loop $loop): void
    {
        $this->loop = $loop;
    }

    // ── Droits ──────────────────────────────────────────────────────────────

    public function canView(): bool
    {
        return $this->resolver()->can(auth()->user(), $this->loop, 'progression.view');
    }

    /** L'Animateur : voit tout le monde, valide, demande une reprise, debloque. */
    public function canManage(): bool
    {
        return $this->resolver()->can(auth()->user(), $this->loop, 'progression.manage');
    }

    // ── Gestes du stagiaire ─────────────────────────────────────────────────

    public function open(string $sequenceId): void
    {
        $this->authorizeView();

        $sequence = $this->resolveSequence($sequenceId);

        try {
            $this->progress()->start(auth()->user(), $sequence);
        } catch (ValidationException $e) {
            $this->flash = $e->getMessage();

            return;
        }

        $this->openModuleId = $sequence->course_module_id;
    }

    public function markDone(string $sequenceId): void
    {
        $this->authorizeView();

        try {
            $this->progress()->markCompleted(auth()->user(), $this->resolveSequence($sequenceId));
        } catch (ValidationException $e) {
            $this->flash = $e->getMessage();

            return;
        }

        $this->flash = __('loops.cards.progression.status_completed');
    }

    // ── Gestes de l'Animateur ───────────────────────────────────────────────

    public function validateFor(string $sequenceId, string $userId): void
    {
        $this->authorizeManage();

        $this->progress()->validate(
            auth()->user(),
            $this->resolveMemberUser($userId),
            $this->resolveSequence($sequenceId),
        );

        $this->flash = __('loops.cards.progression.status_validated');
    }

    public function requestRedoFor(string $sequenceId, string $userId): void
    {
        $this->authorizeManage();

        $this->progress()->requestRedo(
            auth()->user(),
            $this->resolveMemberUser($userId),
            $this->resolveSequence($sequenceId),
        );

        $this->flash = __('loops.cards.progression.status_redo');
    }

    public function unlockFor(string $sequenceId, string $userId): void
    {
        $this->authorizeManage();

        $this->progress()->unlock(
            auth()->user(),
            $this->resolveMemberUser($userId),
            $this->resolveSequence($sequenceId),
        );

        $this->flash = __('loops.cards.progression.unlocked_note');
    }

    /**
     * Deplier le detail d'une personne sur un Module.
     *
     * La matrice donne l'etat par Module ; valider ou debloquer se fait sur une
     * **Sequence**. Sans ce depliage, les trois gestes existaient dans le
     * composant et n'etaient atteignables depuis aucun ecran.
     */
    public function toggleCell(string $moduleId, string $userId): void
    {
        $this->authorizeManage();

        $memeCellule = $this->openModuleId === $moduleId && $this->openUserId === $userId;

        $this->openModuleId = $memeCellule ? null : $moduleId;
        $this->openUserId = $memeCellule ? null : $userId;
    }

    /**
     * Le detail d'une cellule depliee : chaque Sequence et son etat.
     *
     * @return Collection<int, array{sequence: CourseSequence, status: string, number: string}>
     */
    public function openCellDetail(): Collection
    {
        if (! $this->openModuleId || ! $this->openUserId || ! $this->canManage()) {
            return collect();
        }

        $module = \App\Models\CourseModule::where('loop_id', $this->loop->id)
            ->whereKey($this->openModuleId)
            ->first();

        if (! $module) {
            return collect();
        }

        $personne = LoopMember::where('loop_id', $this->loop->id)
            ->where('user_id', $this->openUserId)
            ->where('status', 'active')
            ->first()?->user;

        if (! $personne) {
            return collect();
        }

        return $module->sequences()->whereNull('archived_at')->get()->values()->map(
            fn (CourseSequence $sequence, int $index) => [
                'sequence' => $sequence,
                'status' => $this->progress()->statusFor($personne, $sequence),
                'number' => str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
            ]
        );
    }

    public function setPathMode(string $mode): void
    {
        $this->authorizeManage();

        try {
            $this->progress()->setPathMode($this->loop, $mode);
        } catch (ValidationException $e) {
            $this->flash = $e->getMessage();
        }
    }

    // ── Gardes ──────────────────────────────────────────────────────────────

    private function authorizeView(): void
    {
        abort_unless($this->canView(), 403);
    }

    private function authorizeManage(): void
    {
        abort_unless($this->canManage(), 403);
    }

    /**
     * Une Sequence de **cette** Boucle, ou 404.
     *
     * La contrainte remonte jusqu'a `loop_id` : un identifiant forge ne traverse
     * ni la Boucle ni l'Organization, quel que soit le droit de la personne dans
     * la sienne.
     */
    private function resolveSequence(string $sequenceId): CourseSequence
    {
        $sequence = CourseSequence::whereKey($sequenceId)
            ->whereHas('module', fn ($q) => $q->where('loop_id', $this->loop->id))
            ->first();

        abort_unless((bool) $sequence, 404);

        return $sequence;
    }

    /**
     * Un membre de **cette** Boucle, ou 404.
     *
     * L'Animateur agit sur les gens de sa Boucle, pas sur un identifiant qu'on
     * lui glisse. Le refus est un 404 : il n'apprend rien a qui le forge.
     */
    private function resolveMemberUser(string $userId): User
    {
        // `status = 'active'` : une demande d'adhesion en attente n'est pas un
        // stagiaire. Sans ce filtre, elle apparaissait dans la matrice et
        // l'Animateur pouvait la valider — alors que le resolveur de
        // permissions lui refuse tout acces, faute d'adhesion active. Deux
        // ecrans qui ne disent pas la meme chose.
        $membre = LoopMember::where('loop_id', $this->loop->id)
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->first();

        abort_unless((bool) $membre, 404);

        return $membre->user;
    }

    // ── Rendu ───────────────────────────────────────────────────────────────

    /** @return Collection<int, User> */
    private function students(): Collection
    {
        return LoopMember::where('loop_id', $this->loop->id)
            ->where('status', 'active')
            ->with('user:id,first_name,name,email,organization_id,banned_at')
            ->get()
            ->pluck('user')
            // Un compte banni ne suit plus rien : il sort de la matrice comme
            // il sort du reste du produit.
            ->filter(fn (?User $u) => $u && $u->banned_at === null)
            ->values();
    }

    private function progress(): CourseProgressService
    {
        return app(CourseProgressService::class);
    }

    private function resolver(): LoopPermissionResolver
    {
        return app(LoopPermissionResolver::class);
    }

    public function render()
    {
        $canView = $this->canView();
        $canManage = $canView && $this->canManage();

        // Sans droit de gestion, le visage « tout le monde » n'existe pas : le
        // demander ne l'ouvre pas.
        $face = $canManage ? $this->face : self::FACE_MINE;

        // Une requete au lieu de mille : sans cela, afficher la matrice d'une
        // classe de vingt personnes en declenchait plus de vingt mille.
        if ($canView) {
            $this->progress()->warmUp(
                $this->loop,
                $face === self::FACE_EVERYONE ? $this->students() : collect([auth()->user()]),
            );
        }

        return view('livewire.loop-progression-card', [
            'canView' => $canView,
            'openModuleId' => $this->openModuleId,
            'openUserId' => $this->openUserId,
            'cellDetail' => $this->openCellDetail(),
            'canManage' => $canManage,
            'face' => $face,
            'pathMode' => $canView ? $this->progress()->pathMode($this->loop) : CourseSetting::PATH_SEQUENTIAL,
            'overview' => $canView && $face === self::FACE_MINE
                ? $this->progress()->overviewFor(auth()->user(), $this->loop)
                : collect(),
            'matrix' => $canManage && $face === self::FACE_EVERYONE
                ? $this->progress()->matrixFor($this->loop, $this->students())
                : collect(),
        ]);
    }
}
