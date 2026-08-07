<?php

namespace App\Models;

use App\Models\Traits\HasOrganizationId;
use Database\Factories\LoopRoadmapItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LoopRoadmapItem extends Model
{
    /** @use HasFactory<LoopRoadmapItemFactory> */
    use HasFactory, HasOrganizationId, HasUuids, SoftDeletes;

    public const STATUS_TODO = 'todo';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_DONE = 'done';

    /** The three kanban columns, in display order. */
    public const STATUSES = [self::STATUS_TODO, self::STATUS_IN_PROGRESS, self::STATUS_DONE];

    /** An action can be assigned to at most three people. */
    public const MAX_ASSIGNEES = 3;

    protected $fillable = [
        'organization_id',
        'loop_id',
        // La Decision qui a lance cette action, s'il y en a une. Nullable :
        // l'immense majorite des items n'en vient d'aucune.
        'loop_decision_id',
        'title',
        'description',
        'status',
        'position',
        'due_at',
        'created_by',
        'completed_at',
    ];

    /** La Decision qui a lance cette action, s'il y en a une. */
    public function decision(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(LoopDecision::class, 'loop_decision_id');
    }

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

    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'loop_roadmap_item_user')
            ->withTimestamps()
            ->orderBy('name');
    }

    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(LoopRoadmapLabel::class, 'loop_roadmap_item_label')
            ->withTimestamps()
            ->orderBy('name');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(LoopRoadmapItemMessage::class)->latest();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeTodo(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_TODO);
    }

    public function scopeInProgress(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_IN_PROGRESS);
    }

    public function scopeDone(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_DONE);
    }

    /** Not done (todo + in_progress) — the "open" work that still counts. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_TODO, self::STATUS_IN_PROGRESS]);
    }

    /** Kanban order: todo, then in_progress, then done; then by position, then creation. */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query
            ->orderByRaw("CASE status WHEN '".self::STATUS_TODO."' THEN 0 WHEN '".self::STATUS_IN_PROGRESS."' THEN 1 ELSE 2 END")
            ->orderBy('position')
            ->orderBy('created_at');
    }

    public function isDone(): bool
    {
        return $this->status === self::STATUS_DONE;
    }

    public static function isValidStatus(string $status): bool
    {
        return in_array($status, self::STATUSES, true);
    }
}
