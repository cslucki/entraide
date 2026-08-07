<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une entree du Journal d'une Boucle.
 *
 * Elle porte **son propre texte** ou **une reference** vers un message du
 * ChatLoop qu'on a voulu garder. Jamais les deux, jamais une copie : le message
 * corrige se corrige partout.
 */
class LoopJournalEntry extends Model
{
    public const SOURCE_WRITTEN = 'written';

    public const SOURCE_MESSAGE = 'message';

    use HasUuids;

    protected $fillable = [
        'organization_id',
        'loop_id',
        'author_id',
        'body',
        'loop_message_id',
        'occurred_on',
    ];

    protected function casts(): array
    {
        return ['occurred_on' => 'date'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function loop(): BelongsTo
    {
        return $this->belongsTo(Loop::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(LoopMessage::class, 'loop_message_id');
    }

    /** `written` ou `message` — une seule lecture, pour que les vues s'accordent. */
    public function sourceType(): string
    {
        return $this->loop_message_id !== null ? self::SOURCE_MESSAGE : self::SOURCE_WRITTEN;
    }

    /**
     * Le texte a afficher.
     *
     * Pour une entree promue, il vient du **message**, lu a chaque fois : c'est
     * ce qui fait qu'une correction du message se voit dans le Journal. Un
     * message efface laisse l'entree, qui retombe alors sur son propre texte
     * s'il y en a un.
     */
    public function displayBody(): string
    {
        if ($this->sourceType() === self::SOURCE_MESSAGE) {
            // **Un message modere reste modere ici aussi.** Le Journal etait le
            // seul ecran a lire `body` brut : partout ailleurs — ChatLoop,
            // banniere epinglee — un message supprime laisse un texte de
            // remplacement. Sans cela, retirer un message du ChatLoop ne le
            // retirait pas du Journal, et la moderation devenait reversible par
            // qui savait ou regarder.
            if ($this->message?->isDeleted()) {
                return (string) __('loops.cards.journal.message_removed');
            }

            return (string) ($this->message?->body ?? $this->body ?? '');
        }

        return (string) ($this->body ?? '');
    }
}
