<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Review extends Model
{
    use HasFactory, HasUuids;

    public $timestamps = false;

    // TASK-1808 — `comment_skipped_at` distingue « pas encore de mot » de
    // « l'aide a repondu Terminer sans commentaire ». Une chaine vide dans
    // `comment` confondrait les deux, et le micro-ecran B reviendrait sans
    // fin.
    protected $fillable = ['transaction_id', 'reviewer_id', 'reviewed_id', 'rating', 'comment', 'comment_skipped_at', 'organization_id'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'comment_skipped_at' => 'datetime',
            'rating' => 'integer',
        ];
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
