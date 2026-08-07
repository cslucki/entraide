<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ce qu'une personne a remis pour un Travail.
 *
 * Le fichier, quand il y en a un, est une **reference** vers un `DossierFile`
 * du Dossier de la Boucle : le quota, la somme de controle et la deduplication
 * sont deja la. Rien n'est recopie, donc rien ne diverge.
 */
class CourseSubmission extends Model
{
    /** Commence, pas remis. */
    public const STATUS_DRAFT = 'draft';

    /** Remis, en attente du formateur. */
    public const STATUS_SUBMITTED = 'submitted';

    /** Approuve par le formateur. */
    public const STATUS_VALIDATED = 'validated';

    /** Le formateur demande une reprise. */
    public const STATUS_REDO = 'redo';

    use HasUuids;

    protected $fillable = [
        'organization_id',
        'course_assignment_id',
        'user_id',
        'body',
        'dossier_file_id',
        'status',
        'submitted_at',
        'feedback',
        'reviewed_at',
        'reviewed_by',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(CourseAssignment::class, 'course_assignment_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function dossierFile(): BelongsTo
    {
        return $this->belongsTo(DossierFile::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** Ce Travail est-il termine pour cette personne ? Seul un humain le dit. */
    public function isFinished(): bool
    {
        return $this->status === self::STATUS_VALIDATED;
    }

    /** Attend-il un geste du formateur ? */
    public function awaitsReview(): bool
    {
        return $this->status === self::STATUS_SUBMITTED;
    }
}
