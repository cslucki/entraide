<?php

namespace App\Models;

use App\Models\Traits\HasOrganizationId;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Une question posee aux membres d'une Boucle.
 *
 * Nominatif par construction : chaque vote porte son auteur, et rien ici ne
 * permet de l'effacer. C'est une decision produit, pas un manque — dans une
 * Boucle, on assume ce qu'on dit.
 */
class LoopPoll extends Model
{
    use HasOrganizationId, HasUuids;

    public const TYPE_SINGLE = 'single';

    public const TYPE_MULTIPLE = 'multiple';

    public const TYPES = [self::TYPE_SINGLE, self::TYPE_MULTIPLE];

    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    /** Un Sondage se pose avec au moins deux reponses possibles. */
    public const MIN_OPTIONS = 2;

    /** Au-dela, ce n'est plus une question, c'est un formulaire. */
    public const MAX_OPTIONS = 10;

    protected $fillable = [
        'organization_id',
        'loop_id',
        'created_by',
        'question',
        'description',
        'selection_type',
        'status',
        'closed_at',
        'closed_by',
    ];

    protected function casts(): array
    {
        return [
            'closed_at' => 'datetime',
        ];
    }

    public function loop(): BelongsTo
    {
        return $this->belongsTo(Loop::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function options(): HasMany
    {
        return $this->hasMany(LoopPollOption::class, 'poll_id')->orderBy('position');
    }

    public function votes(): HasMany
    {
        return $this->hasMany(LoopPollVote::class, 'poll_id');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }

    public function allowsMultiple(): bool
    {
        return $this->selection_type === self::TYPE_MULTIPLE;
    }

    /**
     * Un Sondage qui n'a recu aucune voix n'a rien fige.
     *
     * C'est la frontiere de tout ce qui est modifiable : la question, les
     * options, le mode de selection, et la suppression. Des qu'une personne a
     * vote, elle a vote sur *cette* question avec *ces* options ; les changer
     * apres coup falsifierait son vote.
     */
    public function hasVotes(): bool
    {
        return $this->votes()->exists();
    }
}
