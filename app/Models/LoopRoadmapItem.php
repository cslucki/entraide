<?php

namespace App\Models;

use App\Models\Traits\HasOrganizationId;
use Database\Factories\LoopRoadmapItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoopRoadmapItem extends Model
{
    /** @use HasFactory<LoopRoadmapItemFactory> */
    use HasFactory, HasOrganizationId, HasUuids;

    public const STATUS_OPEN = 'open';

    public const STATUS_DONE = 'done';

    protected $fillable = [
        'organization_id',
        'loop_id',
        'title',
        'status',
        'position',
        'assigned_to',
        'due_at',
        'created_by',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'string',
            'position' => 'integer',
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function loop(): BelongsTo
    {
        return $this->belongsTo(Loop::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    public function scopeDone(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_DONE);
    }

    /** Open items first, then by position, then by creation date. */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query
            ->orderByRaw("CASE WHEN status = '".self::STATUS_DONE."' THEN 1 ELSE 0 END")
            ->orderBy('position')
            ->orderBy('created_at');
    }

    public function isDone(): bool
    {
        return $this->status === self::STATUS_DONE;
    }
}
