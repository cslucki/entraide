<?php

namespace App\Models;

use Database\Factories\LoopMessageFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class LoopMessage extends Model
{
    /** @use HasFactory<LoopMessageFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'loop_id',
        'sender_id',
        'reply_to_id',
        'body',
        'image_path',
        'type',
        'metadata',
        'organization_id',
        'pinned_at',
        'pinned_by_id',
        'edited_at',
        'deleted_at',
        'deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'pinned_at' => 'datetime',
            'edited_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function loop(): BelongsTo
    {
        return $this->belongsTo(Loop::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'reply_to_id');
    }

    public function pinnedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pinned_by_id');
    }

    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    public function reactions(): MorphMany
    {
        return $this->morphMany(Reaction::class, 'reactionable');
    }

    public function imageUrl(): ?string
    {
        if ($this->isDeleted()) {
            return null;
        }

        return $this->image_path ? Storage::disk('public')->url($this->image_path) : null;
    }

    public function scopeForLoop($query, string $loopId)
    {
        return $query->where('loop_id', $loopId);
    }

    public function scopeUserMessages($query, string $userId)
    {
        return $query->where('sender_id', $userId);
    }

    public function scopePinned($query)
    {
        return $query->whereNotNull('pinned_at');
    }

    public function scopeNotDeleted($query)
    {
        return $query->whereNull('deleted_at');
    }

    public function isDeleted(): bool
    {
        return $this->deleted_at !== null;
    }

    public function isServiceRequestProjection(): bool
    {
        return $this->type === 'help_request'
            && ($this->metadata['projection_type'] ?? null) === 'service_request'
            && is_string($this->metadata['service_request_id'] ?? null)
            && Str::isUuid($this->metadata['service_request_id']);
    }

    public function isEditableBy(User $user): bool
    {
        // TASK-1298 : quand la reponse d'un agent portait `type=user`, son
        // membre (sender_id) pouvait l'editer ; `member_agent` PRESERVE ce
        // droit a l'identique. Le resserrement eventuel est un retrait de
        // capacite : DECISION_REQUIRED_CYRIL, pas pris ici.
        return in_array($this->type, ['user', 'member_agent'], true)
            && ! $this->isDeleted()
            && $this->sender_id === $user->id;
    }

    public function isPinned(): bool
    {
        return $this->pinned_at !== null;
    }

    public function pin(User $user): void
    {
        $this->pinned_at = now();
        $this->pinned_by_id = $user->id;
        $this->save();
    }

    public function unpin(): void
    {
        $this->pinned_at = null;
        $this->pinned_by_id = null;
        $this->save();
    }
}
