<?php

namespace App\Models;

use App\Models\Traits\HasOrganizationId;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class LoopRoadmapLabel extends Model
{
    use HasOrganizationId, HasUuids;

    /** Controlled palette — no free-form CSS. */
    public const COLORS = ['violet', 'blue', 'cyan', 'green', 'yellow', 'orange', 'red', 'pink', 'gray'];

    /** Max labels attached to a single action. */
    public const MAX_PER_ITEM = 5;

    protected $fillable = [
        'organization_id',
        'loop_id',
        'name',
        'color',
        'created_by',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function loop(): BelongsTo
    {
        return $this->belongsTo(Loop::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): BelongsToMany
    {
        return $this->belongsToMany(LoopRoadmapItem::class, 'loop_roadmap_item_label');
    }

    public static function isValidColor(string $color): bool
    {
        return in_array($color, self::COLORS, true);
    }
}
