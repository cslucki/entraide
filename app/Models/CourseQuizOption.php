<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une option d'une question.
 *
 * `is_correct` est **la** colonne qui distingue un QCM d'un Sondage.
 */
class CourseQuizOption extends Model
{
    use HasUuids;

    protected $fillable = ['organization_id', 'course_quiz_question_id', 'text', 'is_correct', 'position'];

    protected $casts = ['is_correct' => 'boolean', 'position' => 'integer'];

    public function question(): BelongsTo
    {
        return $this->belongsTo(CourseQuizQuestion::class, 'course_quiz_question_id');
    }
}
