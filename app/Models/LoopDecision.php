<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Une Decision prise dans une Boucle.
 *
 * Elle porte **toujours son titre** — c'est ce qu'on relit dans une liste — et
 * peut porter en plus une **reference** vers le message du ChatLoop qui l'a
 * fait naitre. Jamais une copie de ce message : le message corrige se corrige
 * partout.
 *
 * Une Decision remplacee **reste lisible**. C'est une memoire, pas un etat
 * courant : effacer ce qui a ete decide avant priverait le collectif de son
 * histoire, qui est precisement ce que la Card conserve.
 */
class LoopDecision extends Model
{
    use HasUuids;

    protected $fillable = [
        'organization_id',
        'loop_id',
        'author_id',
        'title',
        'rationale',
        'decided_on',
        'loop_message_id',
        'superseded_by_id',
    ];

    protected function casts(): array
    {
        return ['decided_on' => 'date'];
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

    /** Le message du ChatLoop dont elle est issue, s'il y en a un. */
    public function message(): BelongsTo
    {
        return $this->belongsTo(LoopMessage::class, 'loop_message_id');
    }

    /** La Decision qui a pris le relais. */
    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }

    /** Celles que celle-ci a remplacees — plusieurs sont possibles. */
    public function supersedes(): HasMany
    {
        return $this->hasMany(self::class, 'superseded_by_id');
    }

    /**
     * Les actions nees de cette Decision.
     *
     * C'est le lien que le North Star reclame : « une decision n'est pas
     * transformee en action » est la perte que cette Card existe pour eviter.
     */
    public function actions(): HasMany
    {
        return $this->hasMany(LoopRoadmapItem::class, 'loop_decision_id');
    }

    /** Une Decision remplacee n'est plus celle qui fait foi. */
    public function isSuperseded(): bool
    {
        return $this->superseded_by_id !== null;
    }

    /** Promue depuis le ChatLoop, ou consignee directement. */
    public function isPromoted(): bool
    {
        return $this->loop_message_id !== null;
    }

    /**
     * Le message d'origine, tel qu'il faut l'afficher.
     *
     * Lu **a chaque fois** sur le message : une correction s'y voit, et un
     * message retire du ChatLoop reste retire ici. Le Journal avait paye cette
     * lecon — il lisait `body` brut, et la moderation devenait reversible pour
     * qui savait ou regarder.
     */
    public function displayMessage(): ?string
    {
        if (! $this->isPromoted()) {
            return null;
        }

        if ($this->message?->isDeleted()) {
            return (string) __('loops.cards.decisions.message_removed');
        }

        return $this->message?->body;
    }
}
