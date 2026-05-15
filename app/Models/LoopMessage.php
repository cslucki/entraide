<?php

namespace App\Models;

use Database\Factories\LoopMessageFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoopMessage extends Model
{
    /** @use HasFactory<LoopMessageFactory> */
    use HasUuids, HasFactory;

    protected $fillable = [
        'loop_id',
        'sender_id',
        'body',
        'type',
        'metadata',
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

    public function scopeForLoop($query, string $loopId)
    {
        return $query->where('loop_id', $loopId);
    }

    public function scopeUserMessages($query, string $userId)
    {
        return $query->where('sender_id', $userId);
    }
}
