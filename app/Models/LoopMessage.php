<?php

namespace App\Models;

use Database\Factories\LoopMessageFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

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
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'pinned_at' => 'datetime',
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

    public function imageUrl(): ?string
    {
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
