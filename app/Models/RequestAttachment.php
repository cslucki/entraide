<?php

namespace App\Models;

use App\Models\Traits\HasOrganizationId;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class RequestAttachment extends Model
{
    use HasOrganizationId, HasUuids;

    protected $fillable = ['service_request_id', 'path', 'original_name', 'mime_type', 'order', 'organization_id'];

    public function serviceRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class);
    }

    public function getUrlAttribute(): string
    {
        return Storage::disk('public')->url($this->path);
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    public function iconClass(): string
    {
        return match (true) {
            $this->mime_type === 'application/pdf' => 'pdf',
            in_array($this->mime_type, ['application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document']) => 'word',
            in_array($this->mime_type, ['application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']) => 'excel',
            default => 'image',
        };
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
