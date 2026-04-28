<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PointGuideline extends Model
{
    use HasUuids;

    protected $fillable = ['category_id', 'level', 'points_min', 'points_max', 'duration_label'];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
