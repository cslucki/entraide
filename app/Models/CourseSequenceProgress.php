<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ce qu'une personne a fait d'une Sequence.
 *
 * **Ce n'est pas une Card**, et ce n'est pas une inscription : l'inscription
 * reste `LoopMember`. Cette ligne ne dit qu'une chose — ou en est cette
 * personne, sur cette Sequence.
 *
 * Les etats `unavailable` et `available` n'y figurent jamais. Ce sont des
 * **conclusions** tirees des regles d'ouverture, pas des faits : elles changent
 * sans que la personne ait rien fait. Seul ce qu'elle a reellement fait est
 * ecrit ici.
 */
class CourseSequenceProgress extends Model
{
    /** Ouvert, mais rien de fait — deduit, jamais stocke. */
    public const STATUS_AVAILABLE = 'available';

    /** Les conditions ne sont pas reunies — deduit, jamais stocke. */
    public const STATUS_UNAVAILABLE = 'unavailable';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_VALIDATED = 'validated';

    public const STATUS_REDO = 'redo';

    /** Les etats qui s'ecrivent. Les deux autres se calculent. */
    public const STORED_STATUSES = [
        self::STATUS_IN_PROGRESS,
        self::STATUS_SUBMITTED,
        self::STATUS_COMPLETED,
        self::STATUS_VALIDATED,
        self::STATUS_REDO,
    ];

    use HasUuids;

    protected $table = 'course_sequence_progress';

    protected $fillable = [
        'organization_id',
        'course_sequence_id',
        'user_id',
        'status',
        'started_at',
        'completed_at',
        'validated_at',
        'validated_by',
        'unlocked_at',
        'unlocked_by',
        'passed_quiz_id',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'validated_at' => 'datetime',
            'unlocked_at' => 'datetime',
        ];
    }

    public function sequence(): BelongsTo
    {
        return $this->belongsTo(CourseSequence::class, 'course_sequence_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function validatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    public function unlockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'unlocked_by');
    }

    /**
     * Cette Sequence compte-t-elle comme terminee ?
     *
     * `completed` suffit quand la Sequence n'attend pas de regard ; sinon seul
     * `validated` termine. C'est la distinction qui porte tout le suivi.
     */
    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_VALIDATED], true);
    }

    /** Le formateur a-t-il ouvert cette etape a la main ? */
    public function isManuallyUnlocked(): bool
    {
        return $this->unlocked_at !== null;
    }
}
