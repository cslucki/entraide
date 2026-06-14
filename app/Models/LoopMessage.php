<?php

namespace App\Models;

use Database\Factories\LoopMessageFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LoopMessage extends Model
{
    /** @use HasFactory<LoopMessageFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'loop_id',
        'sender_id',
        'reply_to_id',
        'body',
        'type',
        'metadata',
        'organization_id',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
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

    public function scopeForLoop($query, string $loopId)
    {
        return $query->where('loop_id', $loopId);
    }

    public function scopeUserMessages($query, string $userId)
    {
        return $query->where('sender_id', $userId);
    }
}
