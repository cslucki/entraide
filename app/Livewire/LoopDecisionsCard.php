<?php

namespace App\Livewire;

use App\Models\Loop;
use App\Models\LoopDecision;
use App\Models\LoopMessage;
use App\Services\Loops\LoopDecisionService;
use App\Support\Loops\LoopPermissionResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * La Card Decisions : ce qui a ete tranche, quand, et pourquoi.
 *
 * La matrice produit la donne au Projet, aux cotes de la Roadmap et des
 * Dossiers.
 *
 * Les cinq invariants de la serie sont tenus des la conception :
 *
 * 1. **Consigner est une ecriture** : `decisions.record`, sans `read`. Une
 *    Boucle archivee la refuse.
 * 2. **Une permission ne suffit pas — la transition doit etre valide.** Quatre
 *    remplacements sont refuses par le service, pour quatre raisons qui se
 *    lisent.
 * 3. **Le cout ne croit pas** : la lecture est bornee, les relations chargees
 *    en une fois.
 * 4. **Chaque geste a un chemin**, teste au refus, au succes **et** a l'ecran.
 * 5. **Rien ne se fige** : retirer une Decision n'emporte pas les actions
 *    engagees.
 *
 * Le droit de corriger suit la regle du Journal : chacun corrige les siennes,
 * l'animation corrige tout.
 *
 * **`$editingId` et `$supersedingId` sont publics** — donc poses depuis le
 * client. Chaque geste qui les consomme revalide donc le droit au moment de
 * l'ecriture, et non seulement quand on ouvre le formulaire. C'est le defaut
 * exact trouve dans le Journal : la garde etait sur `startEditing()`, et poser
 * la propriete suffisait a reecrire l'entree de n'importe qui.
 */
class LoopDecisionsCard extends Component
{
    public Loop $loop;

    public bool $showForm = false;

    public ?string $editingId = null;

    public string $title = '';

    public string $rationale = '';

    public string $decidedOn = '';

    /** La liste des messages proposes a la promotion, quand elle est ouverte. */
    public bool $showPicker = false;

    /** La Decision dont on choisit ce qu'elle remplace. */
    public ?string $supersedingId = null;

    /** La Decision pour laquelle on saisit une action. */
    public ?string $actionForId = null;

    public string $actionTitle = '';

    public string $flash = '';

    public string $problem = '';

    public function mount(Loop $loop): void
    {
        $this->loop = $loop;
    }

    // ── Droits ──────────────────────────────────────────────────────────────

    /**
     * Les trois droits, lus **une fois par requete**.
     *
     * `LoopPermissionResolver::can()` interroge `loop_members` a chaque appel :
     * il n'a pas de cache, et n'est pas lie en singleton. Or `supersedable()`
     * appelle `canEdit()` **par ligne**, qui appelle deux de ces droits.
     * Mesure, selecteur de remplacement ouvert : 14 lectures pour 2 Decisions,
     * **90 pour 40**.
     *
     * Ma sonde de croissance de TASK-1106 ne l'avait pas vu : elle n'ouvrait
     * jamais le selecteur, et mesurait donc un chemin ou le defaut n'existe
     * pas.
     *
     * Le cache est `private` : il ne voyage pas dans le snapshot Livewire, qui
     * n'a ni nonce ni expiration, et se reconstruit a chaque requete. Un droit
     * retire est visible au rendu suivant.
     *
     * @var array<string, bool>
     */
    private array $droitsMemo = [];

    private function droit(string $capacite): bool
    {
        return $this->droitsMemo[$capacite] ??= $this->resolver()->can(auth()->user(), $this->loop, $capacite);
    }

    /**
     * Oublier les droits appris.
     *
     * Appele a l'entree **et** a la sortie de `render()` : le cache ne doit pas
     * survivre au rendu. Une propriete privee ne voyage pas dans le snapshot,
     * mais l'unite de vie d'une instance Livewire n'est pas la requete, c'est
     * le **commit** — et un commit porte plusieurs `calls`. Deux gestes partis
     * dans le meme tick partagent donc une instance, et un droit retire entre
     * les deux par une requete concurrente ne serait plus honore.
     */
    private function forgetDroits(): void
    {
        $this->droitsMemo = [];
    }

    public function canView(): bool
    {
        return $this->droit('decisions.view');
    }

    public function canRecord(): bool
    {
        return $this->droit('decisions.record');
    }

    public function canManage(): bool
    {
        return $this->droit('decisions.manage');
    }

    /**
     * Chacun corrige les siennes ; l'animation corrige tout.
     *
     * **`protected` et non `public`** : Livewire expose toute methode publique
     * comme action et resout son argument par liaison implicite — donc **sans**
     * le `where('loop_id', …)` de `resolveDecision()`. Elle repondait sur une
     * Decision d'une autre Organization, et distinguait l'inexistant (404) de
     * l'existant (`true`) : un oracle. La Card Demande-Offre avait deja recu ce
     * correctif ; celle-ci etait restee en arriere.
     */
    protected function canEdit(LoopDecision $decision): bool
    {
        if (! $this->canRecord()) {
            return false;
        }

        return $this->canManage() || $decision->author_id === auth()->id();
    }

    // ── Gestes ──────────────────────────────────────────────────────────────

    public function save(): void
    {
        $this->authorizeRecord();

        try {
            if ($this->editingId) {
                $decision = $this->resolveDecision($this->editingId);

                // Revalide **ici**, pas seulement a l'ouverture du formulaire :
                // `$editingId` est public.
                abort_unless($this->canEdit($decision), 403);

                $this->service()->update($decision, $this->title, $this->rationale ?: null, $this->decidedOn ?: null);
            } else {
                $this->service()->record(
                    $this->loop,
                    auth()->user(),
                    $this->title,
                    $this->rationale ?: null,
                    $this->decidedOn ?: null,
                );
            }
        } catch (ValidationException $e) {
            $this->reportProblem($e);

            return;
        }

        $this->reset(['title', 'rationale', 'decidedOn', 'showForm', 'editingId']);
        $this->problem = '';
        $this->flash = __('loops.cards.decisions.recorded');
    }

    public function startEditing(string $decisionId): void
    {
        $this->authorizeRecord();

        $decision = $this->resolveDecision($decisionId);
        abort_unless($this->canEdit($decision), 403);

        // Une Decision remplacee est de l'histoire : ouvrir son formulaire
        // menerait a un `save()` que le service refuse de toute façon.
        abort_if($decision->isSuperseded(), 403);

        $this->editingId = $decision->id;
        $this->title = (string) $decision->title;
        $this->rationale = (string) $decision->rationale;
        $this->decidedOn = $decision->decided_on?->toDateString() ?? '';
        $this->showForm = true;
        $this->flash = '';
        $this->problem = '';
    }

    public function remove(string $decisionId): void
    {
        $this->authorizeRecord();

        $decision = $this->resolveDecision($decisionId);
        abort_unless($this->canEdit($decision), 403);

        $this->service()->delete($decision);

        $this->problem = '';
        $this->flash = __('loops.cards.decisions.removed');
    }

    /**
     * Fermer le formulaire **et** relacher tout ce qu'il tenait.
     *
     * `$set('showForm', false)` ne suffit pas : `editingId` resterait pose, et
     * la Decision suivante — ecrite en croyant en commencer une — ecraserait
     * silencieusement celle qu'on venait de renoncer a corriger. Le Journal a
     * paye ce defaut ; il ne se represente pas ici.
     */
    public function cancel(): void
    {
        $this->reset([
            'title', 'rationale', 'decidedOn', 'showForm', 'editingId',
            'supersedingId', 'actionForId', 'actionTitle', 'showPicker',
        ]);
        $this->problem = '';
    }

    public function promote(string $messageId): void
    {
        $this->authorizeRecord();

        if (trim($this->title) === '') {
            // Le titre est saisi **avant** de choisir le message : le corps
            // d'un message n'est pas un titre, et le laisser en tenir lieu
            // donnerait une liste de Decisions illisible.
            $this->problem = __('loops.cards.decisions.title_required');

            return;
        }

        try {
            $this->service()->promote(
                $this->loop,
                auth()->user(),
                $this->resolveMessage($messageId),
                $this->title,
                $this->rationale ?: null,
                $this->decidedOn ?: null,
            );
        } catch (ValidationException $e) {
            $this->reportProblem($e);

            return;
        }

        $this->reset(['title', 'rationale', 'decidedOn', 'showPicker', 'showForm']);
        $this->problem = '';
        $this->flash = __('loops.cards.decisions.recorded');
    }

    /** Ouvrir le choix de ce que cette Decision remplace. */
    public function startSuperseding(string $decisionId): void
    {
        $this->authorizeRecord();

        $decision = $this->resolveDecision($decisionId);
        abort_unless($this->canEdit($decision), 403);

        // Une Decision deja remplacee n'en remplace plus aucune : le lecteur
        // suivrait un renvoi mort, et c'est la brique dont se font les cycles.
        abort_if($decision->isSuperseded(), 403);

        $this->supersedingId = $decision->id;
        $this->flash = '';
        $this->problem = '';
    }

    public function supersede(string $oldId): void
    {
        $this->authorizeRecord();

        // La **nouvelle** est celle qu'on a ouverte ; l'ancienne est celle
        // qu'on designe. Les deux sont resolues dans cette Boucle.
        abort_unless((bool) $this->supersedingId, 403);

        $nouvelle = $this->resolveDecision($this->supersedingId);
        abort_unless($this->canEdit($nouvelle), 403);

        // **C'est l'ancienne qu'on modifie** : son droit doit etre verifie
        // aussi. Sans cela, quiconque pouvait consigner barrait la Decision de
        // n'importe qui — « chacun corrige les siennes, l'animation corrige
        // tout » ne tenait plus, et le chemin etait a l'ecran, le selecteur
        // proposant toutes les Decisions sans distinction d'auteur.
        $ancienne = $this->resolveDecision($oldId);
        abort_unless($this->canEdit($ancienne), 403);

        try {
            $this->service()->supersede($ancienne, $nouvelle);
        } catch (ValidationException $e) {
            $this->reportProblem($e);

            return;
        }

        $this->supersedingId = null;
        $this->problem = '';
        $this->flash = __('loops.cards.decisions.superseded_flash');
    }

    /** Ouvrir la saisie d'une action pour cette Decision. */
    public function startAction(string $decisionId): void
    {
        $this->authorizeRecord();

        $decision = $this->resolveDecision($decisionId);
        abort_unless($this->canEdit($decision), 403);
        abort_if($decision->isSuperseded(), 403);

        $this->actionForId = $decision->id;
        $this->actionTitle = '';
        $this->flash = '';
        $this->problem = '';
    }

    public function saveAction(): void
    {
        $this->authorizeRecord();

        abort_unless((bool) $this->actionForId, 403);

        $decision = $this->resolveDecision($this->actionForId);
        abort_unless($this->canEdit($decision), 403);

        // Lancer une action, c'est ecrire dans la Roadmap : le droit d'y ecrire
        // est **aussi** exige. Sans lui, la Card Decisions serait une porte
        // laterale pour poser des taches a qui n'a pas `roadmap.manage`.
        abort_unless($this->droit('roadmap.manage'), 403);

        try {
            $this->service()->startAction($decision, auth()->user(), $this->actionTitle);
        } catch (ValidationException $e) {
            $this->reportProblem($e);

            return;
        }

        $this->reset(['actionForId', 'actionTitle']);
        $this->problem = '';
        $this->flash = __('loops.cards.decisions.action_started');
    }

    // ── Gardes ──────────────────────────────────────────────────────────────

    private function authorizeRecord(): void
    {
        // `canView()` **aussi** : sans lui, une personne privee de lecture
        // consignerait quand meme, dans une Card que l'ecran lui declare
        // inaccessible.
        abort_unless($this->canView() && $this->canRecord(), 403);
    }

    /**
     * Une Decision de **cette** Boucle, ou 404.
     *
     * Le controle de forme vient **avant** la requete : sous PostgreSQL la
     * colonne est un `uuid` natif, et une chaine qui n'en est pas un rend
     * `SQLSTATE 22P02` — un 500 — la ou SQLite se contente d'un 404. Neuf
     * gestes arrivent ici, dont plusieurs par une propriete publique posee
     * depuis le client : il suffisait d'y ecrire n'importe quoi.
     */
    private function resolveDecision(string $decisionId): LoopDecision
    {
        abort_unless(Str::isUuid($decisionId), 404);

        $decision = LoopDecision::where('loop_id', $this->loop->id)->whereKey($decisionId)->first();

        abort_unless((bool) $decision, 404);

        return $decision;
    }

    /** Un message de **cette** Boucle, ou 404. Meme garde de forme. */
    private function resolveMessage(string $messageId): LoopMessage
    {
        abort_unless(Str::isUuid($messageId), 404);

        $message = LoopMessage::where('loop_id', $this->loop->id)
            // **Un message retire du ChatLoop ne se promeut plus.** Le
            // selecteur le filtrait deja ; l'appel direct, non — et la
            // moderation doit etre terminale pour tous les gestes en aval, pas
            // seulement pour l'affichage.
            ->whereNull('deleted_at')
            ->whereKey($messageId)
            ->first();

        abort_unless((bool) $message, 404);

        return $message;
    }

    /** Les erreurs de validation vont au bon champ, ou au bandeau. */
    private function reportProblem(ValidationException $e): void
    {
        $champ = array_key_first($e->errors());

        match ($champ) {
            'title' => $this->addError('title', $e->getMessage()),
            'decided_on' => $this->addError('decidedOn', $e->getMessage()),
            'action_title' => $this->addError('actionTitle', $e->getMessage()),
            default => $this->problem = $e->getMessage(),
        };
    }

    /**
     * Les messages qu'on peut encore consigner.
     *
     * @return Collection<int, LoopMessage>
     */
    private function promotable(): Collection
    {
        // Consigner un message suppose de pouvoir le lire. Sans ce controle, la
        // Card devenait une porte laterale sur la conversation pour qui n'avait
        // pas `chatloop.view` — un reglage de distance, la matrice etant
        // administrable.
        if (! $this->droit('chatloop.view')) {
            return collect();
        }

        $deja = LoopDecision::where('loop_id', $this->loop->id)
            ->whereNotNull('loop_message_id')
            ->pluck('loop_message_id');

        return LoopMessage::where('loop_id', $this->loop->id)
            ->whereNull('deleted_at')
            ->whereNotIn('id', $deja)
            ->with('sender:id,first_name,name,email,organization_id,banned_at')
            ->orderByDesc('created_at')
            // Departage : `loop_messages.created_at` est a la seconde sous
            // PostgreSQL, et **quels** dix messages apparaissent serait sinon
            // indetermine — un test en depend deja.
            ->orderByDesc('id')
            ->limit(10)
            ->get();
    }

    /**
     * Les Decisions que celle en cours peut remplacer.
     *
     * @return Collection<int, LoopDecision>
     */
    private function supersedable(): Collection
    {
        // **`render()` ne passe pas par `resolveDecision()`** : la propriete
        // publique arrive ici brute, et `whereKeyNot()` l'envoyait telle quelle
        // dans un `where` sur une colonne `uuid` native — troisieme chemin vers
        // le meme 500 sous PostgreSQL, celui-ci atteint au simple rendu.
        if (! Str::isUuid($this->supersedingId)) {
            return collect();
        }

        return LoopDecision::where('loop_id', $this->loop->id)
            ->whereKeyNot($this->supersedingId)
            ->whereNull('superseded_by_id')
            ->orderByDesc('decided_on')
            // `decided_on` est une **date** : l'egalite y est la norme, pas
            // l'exception, et l'ordre du selecteur serait indetermine.
            ->orderByDesc('id')
            ->get()
            ->filter(fn (LoopDecision $d) => $this->canEdit($d))
            ->values();
    }

    private function service(): LoopDecisionService
    {
        return app(LoopDecisionService::class);
    }

    private function resolver(): LoopPermissionResolver
    {
        return app(LoopPermissionResolver::class);
    }

    public function render()
    {
        // Le cache d'autorisation ne vit **que** dans ce rendu.
        $this->forgetDroits();

        $canView = $this->canView();
        $canRecord = $canView && $this->canRecord();

        $decisions = $canView ? $this->service()->decisionsFor($this->loop) : collect();

        $vue = view('livewire.loop-decisions-card', [
            'canView' => $canView,
            'canRecord' => $canRecord,
            'canManage' => $canView && $this->canManage(),
            'canAct' => $canRecord && $this->droit('roadmap.manage'),
            'decisions' => $decisions,
            // Sans `chatloop.view`, `promotable()` rend toujours vide : offrir
            // le bouton menait a un selecteur qui annonce « aucun message ».
            'canPromote' => $canRecord && $this->droit('chatloop.view'),
            'promotable' => $canRecord && $this->showPicker ? $this->promotable() : collect(),
            // Ce qu'une Decision peut remplacer : les autres, non deja
            // remplacees, **et qu'on a le droit de barrer**.
            //
            // Lu par sa propre requete et non filtre depuis la page affichee :
            // celle-ci est bornee a 30, et une Decision plus ancienne devenait
            // alors impossible a remplacer depuis l'ecran — sans un mot.
            'supersedable' => $this->supersedingId ? $this->supersedable() : collect(),
        ]);

        // Les gestes d'ecriture qui suivront dans le meme commit reliront l'etat.
        $this->forgetDroits();

        return $vue;
    }
}
