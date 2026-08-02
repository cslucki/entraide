<?php

namespace App\Models;

use App\Models\Traits\HasOrganizationId;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class LoopRoadmapItemMessage extends Model
{
    use HasOrganizationId, HasUuids, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'loop_id',
        'loop_roadmap_item_id',
        'user_id',
        'body',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function loop(): BelongsTo
    {
        return $this->belongsTo(Loop::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(LoopRoadmapItem::class, 'loop_roadmap_item_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
