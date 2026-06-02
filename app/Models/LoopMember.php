<?php

namespace App\Models;

use Database\Factories\LoopMemberFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoopMember extends Model
{
    /** @use HasFactory<LoopMemberFactory> */
    use HasUuids, HasFactory;

    protected $fillable = [
        'loop_id',
        'user_id',
        'role',
        'status',
        'joined_at',
        'organization_id',
    ];

    protected function casts(): array
    {
        return [
            'role' => 'string',
            'status' => 'string',
            'joined_at' => 'datetime',
        ];
    }

    public function loop(): BelongsTo
    {
        return $this->belongsTo(Loop::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
