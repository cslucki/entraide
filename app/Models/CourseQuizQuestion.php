<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Une question d'un QCM. Trois formes, celles du MVP. */
class CourseQuizQuestion extends Model
{
    public const KIND_SINGLE = 'single';

    public const KIND_MULTIPLE = 'multiple';

    public const KIND_TRUE_FALSE = 'true_false';

    public const KINDS = [self::KIND_SINGLE, self::KIND_MULTIPLE, self::KIND_TRUE_FALSE];

    use HasUuids;

    protected $fillable = ['organization_id', 'course_quiz_id', 'text', 'kind', 'position'];

    protected $casts = ['position' => 'integer'];

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(CourseQuiz::class, 'course_quiz_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(CourseQuizOption::class)->orderBy('position')->orderBy('created_at');
    }

    /**
     * Une question a **exactement une** bonne reponse, sauf en choix multiple.
     *
     * Verifie a la correction et non a l'ecriture : une question en cours de
     * redaction passe forcement par un etat incomplet, et refuser de
     * l'enregistrer ferait perdre le travail en cours.
     */
    public function expectsSingleAnswer(): bool
    {
        return $this->kind !== self::KIND_MULTIPLE;
    }
}
