<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une tentative — ce qu'une personne a repondu, et ce que ca valait.
 *
 * Le score et la reussite viennent de la **correction**, jamais d'une main :
 * ils sont ecrits par le service au moment ou la tentative est soumise. Aucun
 * chemin ne permet de les poser autrement.
 */
class CourseQuizAttempt extends Model
{
    use HasUuids;

    protected $fillable = [
        'organization_id',
        'course_quiz_id',
        'user_id',
        'attempt_number',
        'score',
        'passed',
        'answers',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'attempt_number' => 'integer',
            'score' => 'integer',
            'passed' => 'boolean',
            'answers' => 'array',
            'submitted_at' => 'datetime',
        ];
    }

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(CourseQuiz::class, 'course_quiz_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
