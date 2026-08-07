<?php

namespace App\Livewire;

use App\Models\Loop;
use App\Models\LoopJournalEntry;
use App\Models\LoopMessage;
use App\Services\Loops\LoopJournalService;
use App\Support\Loops\LoopPermissionResolver;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * La Card Journal : ce qui s'est passe, date et signe.
 *
 * Deux presets l'attendent — Pair-aidance et Coaching.
 *
 * Les quatre invariants de la serie sont tenus des la conception :
 *
 * 1. **Ecrire est une ecriture** : `journal.write`, sans `read`. Une Boucle
 *    archivee la refuse.
 * 2. **La transition doit etre valide** : on ne promeut que ce qui existe et
 *    appartient a cette Boucle, et jamais deux fois.
 * 3. **Le cout ne croit pas** : la lecture est bornee, les relations chargees
 *    en une fois.
 * 4. **Chaque geste a un chemin**, teste au refus, au succes **et** a l'ecran.
 *
 * Le droit de corriger suit une regle simple : chacun corrige les siennes,
 * l'animation corrige tout.
 */
class LoopJournalCard extends Component
{
    public Loop $loop;

    public bool $showForm = false;

    public ?string $editingId = null;

    public string $body = '';

    public string $occurredOn = '';

    /** La liste des messages proposes a la promotion, quand elle est ouverte. */
    public bool $showPicker = false;

    public string $flash = '';

    public string $problem = '';

    public function mount(Loop $loop): void
    {
        $this->loop = $loop;
    }

    // ── Droits ──────────────────────────────────────────────────────────────

    public function canView(): bool
    {
        return $this->resolver()->can(auth()->user(), $this->loop, 'journal.view');
    }

    public function canWrite(): bool
    {
        return $this->resolver()->can(auth()->user(), $this->loop, 'journal.write');
    }

    public function canManage(): bool
    {
        return $this->resolver()->can(auth()->user(), $this->loop, 'journal.manage');
    }

    /** Chacun corrige les siennes ; l'animation corrige tout. */
    public function canEdit(LoopJournalEntry $entry): bool
    {
        if (! $this->canWrite()) {
            return false;
        }

        return $this->canManage() || $entry->author_id === auth()->id();
    }

    // ── Gestes ──────────────────────────────────────────────────────────────

    public function save(): void
    {
        $this->authorizeWrite();

        try {
            if ($this->editingId) {
                $entry = $this->resolveEntry($this->editingId);

                // **La garde etait sur `startEditing()`, pas ici.** Or
                // `$editingId` est une propriete publique : la poser depuis le
                // client suffisait a reecrire le corps **et la date** de
                // l'entree de n'importe qui — signee du nom de la victime,
                // puisque `author_id` ne bouge pas.
                abort_unless($this->canEdit($entry), 403);

                $this->service()->update($entry, $this->body, $this->occurredOn ?: null);
            } else {
                $this->service()->write($this->loop, auth()->user(), $this->body, $this->occurredOn ?: null);
            }
        } catch (ValidationException $e) {
            $this->addError(array_key_first($e->errors()) === 'occurred_on' ? 'occurredOn' : 'body', $e->getMessage());

            return;
        }

        $this->reset(['body', 'occurredOn', 'showForm', 'editingId']);
        $this->problem = '';
        $this->flash = __('loops.cards.journal.saved');
    }

    public function startEditing(string $entryId): void
    {
        $this->authorizeWrite();

        $entry = $this->resolveEntry($entryId);
        abort_unless($this->canEdit($entry), 403);

        // Une entree promue n'a pas de texte propre : ouvrir son formulaire
        // menait a un `save()` qui ne pouvait jamais aboutir.
        abort_if($entry->sourceType() === LoopJournalEntry::SOURCE_MESSAGE, 403);

        $this->editingId = $entry->id;
        $this->body = (string) $entry->body;
        $this->occurredOn = $entry->occurred_on?->toDateString() ?? '';
        $this->showForm = true;
        $this->flash = '';
        $this->problem = '';
    }

    public function remove(string $entryId): void
    {
        $this->authorizeWrite();

        $entry = $this->resolveEntry($entryId);
        abort_unless($this->canEdit($entry), 403);

        $this->service()->delete($entry);

        $this->problem = '';
        $this->flash = __('loops.cards.journal.removed');
    }

    /**
     * Fermer le formulaire **et** relacher ce qu'il tenait.
     *
     * `$set('showForm', false)` ne suffisait pas : `editingId` restait pose, et
     * la note suivante — ecrite en croyant en commencer une — **ecrasait**
     * silencieusement celle qu'on venait de renoncer a corriger, date comprise.
     * Une perte de donnees par le parcours nominal, sur la Card dont l'argument
     * est d'etre la memoire de la Boucle.
     */
    public function cancel(): void
    {
        $this->reset(['body', 'occurredOn', 'showForm', 'editingId']);
    }

    public function promote(string $messageId): void
    {
        $this->authorizeWrite();

        try {
            $this->service()->promote($this->loop, auth()->user(), $this->resolveMessage($messageId));
        } catch (ValidationException $e) {
            $this->problem = $e->getMessage();

            return;
        }

        $this->showPicker = false;
        $this->problem = '';
        $this->flash = __('loops.cards.journal.promoted');
    }

    // ── Gardes ──────────────────────────────────────────────────────────────

    private function authorizeWrite(): void
    {
        // `canView()` **aussi** : sans lui, une personne privee de lecture
        // ecrivait quand meme, dans un Journal que l'ecran lui declarait
        // inaccessible.
        abort_unless($this->canView() && $this->canWrite(), 403);
    }

    /** Une entree de **cette** Boucle, ou 404. */
    private function resolveEntry(string $entryId): LoopJournalEntry
    {
        $entry = LoopJournalEntry::where('loop_id', $this->loop->id)->whereKey($entryId)->first();

        abort_unless((bool) $entry, 404);

        return $entry;
    }

    /** Un message de **cette** Boucle, ou 404. */
    private function resolveMessage(string $messageId): LoopMessage
    {
        $message = LoopMessage::where('loop_id', $this->loop->id)->whereKey($messageId)->first();

        abort_unless((bool) $message, 404);

        return $message;
    }

    /**
     * Les messages qu'on peut encore garder.
     *
     * Ceux deja gardes n'y figurent plus : les reproposer inviterait a creer
     * une entree en double, que le service refuse ensuite en silence.
     *
     * @return Collection<int, LoopMessage>
     */
    private function promotable(): Collection
    {
        // Garder un message suppose de pouvoir le lire. Sans ce controle, le
        // Journal devenait une porte laterale sur la conversation pour qui
        // n'avait pas `chatloop.view` — un reglage de distance, la matrice
        // etant administrable.
        if (! $this->resolver()->can(auth()->user(), $this->loop, 'chatloop.view')) {
            return collect();
        }

        $deja = LoopJournalEntry::where('loop_id', $this->loop->id)
            ->whereNotNull('loop_message_id')
            ->pluck('loop_message_id');

        return LoopMessage::where('loop_id', $this->loop->id)
            ->whereNull('deleted_at')
            ->whereNotIn('id', $deja)
            ->with('sender:id,first_name,name,email,organization_id,banned_at')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();
    }

    private function service(): LoopJournalService
    {
        return app(LoopJournalService::class);
    }

    private function resolver(): LoopPermissionResolver
    {
        return app(LoopPermissionResolver::class);
    }

    public function render()
    {
        $canView = $this->canView();
        $canWrite = $canView && $this->canWrite();

        return view('livewire.loop-journal-card', [
            'canView' => $canView,
            'canWrite' => $canWrite,
            'canManage' => $canView && $this->canManage(),
            'entries' => $canView ? $this->service()->entriesFor($this->loop) : collect(),
            'promotable' => $canWrite && $this->showPicker ? $this->promotable() : collect(),
        ]);
    }
}
