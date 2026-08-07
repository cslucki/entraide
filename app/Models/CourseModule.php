<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Un Module du Support de cours.
 *
 * **Ce n'est pas une Card.** C'est un contenu de la Card « Support de cours »,
 * comme un Article est un contenu d'un Dossier. Il n'apparait dans aucune
 * grille, ne se declare pas dans `config/loop_cards.php`, et n'a pas de
 * permission a lui : ce qu'on a le droit d'en faire depend de la Card.
 */
class CourseModule extends Model
{
    use HasUuids;

    protected $fillable = [
        'organization_id',
        'loop_id',
        'title',
        'summary',
        'position',
        'created_by',
    ];

    protected $casts = [
        'position' => 'integer',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function loop(): BelongsTo
    {
        return $this->belongsTo(Loop::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function sequences(): HasMany
    {
        return $this->hasMany(CourseSequence::class)->orderBy('position')->orderBy('created_at');
    }

    /**
     * Les Sequences, chacune portant son numero **calcule**.
     *
     * Meme regle que les Series : le numero vient du rang, jamais d'une colonne
     * et jamais du titre. Reordonner n'ecrit que des positions.
     *
     * @return Collection<int, array{number: string, rank: int, sequence: CourseSequence}>
     */
    public function numberedSequences(): Collection
    {
        return $this->sequences->values()->map(fn (CourseSequence $sequence, int $index) => [
            'number' => str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
            'rank' => $index + 1,
            'sequence' => $sequence,
        ]);
    }
}
