<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ServiceImage extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = ['service_id', 'path', 'order'];

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function getUrlAttribute(): string
    {
        return Storage::disk('public')->url($this->path);
    }

    public function getThumbnailUrlAttribute(): string
    {
        return Storage::disk('public')->url('thumbnails/' . $this->path);
    }
}
