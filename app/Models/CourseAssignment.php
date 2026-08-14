<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un Travail a rendre.
 *
 * La structure est commune : un Travail existe une fois pour la Boucle. Ce que
 * chacun remet vit dans `CourseSubmission`.
 *
 * C'est **le seul objet a porter une date limite** dans le MVP.
 */
class CourseAssignment extends Model
{
    use HasUuids;

    protected $fillable = [
        'organization_id',
        'loop_id',
        'course_sequence_id',
        'title',
        'brief',
        'due_at',
        'position',
        'archived_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'archived_at' => 'datetime',
            'position' => 'integer',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function loop(): BelongsTo
    {
        return $this->belongsTo(Loop::class);
    }

    public function sequence(): BelongsTo
    {
        return $this->belongsTo(CourseSequence::class, 'course_sequence_id');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(CourseSubmission::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * La date limite est-elle passee ?
     *
     * **Rien n'en decoule automatiquement** : pas de fermeture, pas de penalite,
     * pas de rappel. C'est une information affichee, et le formateur en fait ce
     * qu'il veut. Un retard qui ferme tout seul appellerait un mecanisme de
     * derogation que personne n'a demande.
     */
    public function isOverdue(): bool
    {
        return $this->due_at !== null && $this->due_at->isPast();
    }
}
