<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Le reglage de parcours d'une Formation.
 *
 * Dans sa propre table plutot qu'en colonne de `loops` : c'est un reglage qui
 * n'a de sens que pour un type, et le socle doit rester neutre. Une Boucle sans
 * ligne ici est **sequentielle** — le defaut arrete, obtenu sans rien ecrire a
 * la creation.
 */
class CourseSetting extends Model
{
    /** Un Module reste ferme tant que le precedent n'est pas satisfait. */
    public const PATH_SEQUENTIAL = 'sequential';

    /** Tout est ouvert d'emblee. Choisi explicitement, jamais par defaut. */
    public const PATH_FREE = 'free';

    use HasUuids;

    protected $fillable = [
        'organization_id',
        'loop_id',
        'path_mode',
    ];

    public function loop(): BelongsTo
    {
        return $this->belongsTo(Loop::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
