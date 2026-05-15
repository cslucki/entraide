<?php

namespace App\Models;

use Database\Factories\LoopFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Loop extends Model
{
    /** @use HasFactory<LoopFactory> */
    use HasUuids, HasFactory;

    protected $fillable = [
        'community_id',
        'name',
        'slug',
        'description',
        'type',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => 'string',
            'status' => 'string',
        ];
    }

    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class, 'community_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function members(): HasMany
    {
        return $this->hasMany(LoopMember::class);
    }

    public function activeMembers(): HasMany
    {
        return $this->hasMany(LoopMember::class)->where('status', 'active');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(LoopMessage::class);
    }
}
