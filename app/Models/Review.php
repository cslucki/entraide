<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Review extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    public $timestamps = false;

    // TASK-1808 — `comment_skipped_at` distingue « pas encore de mot » de
    // « l'aide a repondu Terminer sans commentaire ». Une chaine vide dans
    // `comment` confondrait les deux, et le micro-ecran B reviendrait sans
    // fin.
    //
    // TASK-1808 §2 (cockpit) — `hidden_at` masque (reversible), `deleted_at`
    // (SoftDeletes) supprime (restaurable) : deux gestes INDEPENDANTS, voir
    // le docbloc de la migration. `moderated_by`/`moderation_reason` portent
    // la DERNIERE intervention pour l'affichage ; l'historique complet vit
    // dans `review_moderation_logs`.
    protected $fillable = [
        'transaction_id', 'reviewer_id', 'reviewed_id', 'rating', 'comment', 'comment_skipped_at', 'organization_id',
        'hidden_at', 'moderated_by', 'moderation_reason',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'comment_skipped_at' => 'datetime',
            'hidden_at' => 'datetime',
            'deleted_at' => 'datetime',
            'rating' => 'integer',
        ];
    }

    /** L'evaluation compte-t-elle encore dans ce que le public voit ? */
    public function estVisiblePubliquement(): bool
    {
        return $this->hidden_at === null;
    }

    public function moderateur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderated_by');
    }

    public function journalModeration(): HasMany
    {
        return $this->hasMany(ReviewModerationLog::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function reviewed(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_id');
    }
}
