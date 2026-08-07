<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un QCM — ce qui se corrige tout seul.
 *
 * **Ce n'est pas un Sondage.** Un Sondage n'a pas de bonne reponse, pas de
 * score, pas de tentative. Ce qui se reutilise est la forme, pas les tables.
 */
class CourseQuiz extends Model
{
    /** Le detail des bonnes reponses des la premiere tentative. */
    public const REVEAL_IMMEDIATE = 'immediate';

    /** Quand il ne reste plus de tentative. Le defaut. */
    public const REVEAL_AFTER_LAST = 'after_last_attempt';

    /** Quand le formateur l'ouvre. */
    public const REVEAL_AFTER_RELEASE = 'after_release';

    public const REVEAL_MODES = [self::REVEAL_IMMEDIATE, self::REVEAL_AFTER_LAST, self::REVEAL_AFTER_RELEASE];

    use HasUuids;

    protected $table = 'course_quizzes';

    protected $fillable = [
        'organization_id',
        'loop_id',
        'course_sequence_id',
        'title',
        'intro',
        'pass_score',
        'max_attempts',
        'blocking',
        'reveal_mode',
        'released_at',
        'position',
        'archived_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'pass_score' => 'integer',
            'max_attempts' => 'integer',
            'blocking' => 'boolean',
            'released_at' => 'datetime',
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

    public function questions(): HasMany
    {
        return $this->hasMany(CourseQuizQuestion::class)->orderBy('position')->orderBy('created_at');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(CourseQuizAttempt::class);
    }
}
