<?php

namespace App\Models;

use App\Models\Traits\HasOrganizationId;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class DossierFile extends Model
{
    use HasFactory;
    use HasOrganizationId;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'dossier_id',
        'uploaded_by',
        'disk',
        'path',
        'original_name',
        'display_name',
        'mime_type',
        'size_bytes',
        'checksum_sha256',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function dossier(): BelongsTo
    {
        return $this->belongsTo(Dossier::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }

    public function temporaryUrl(int $minutes = 30): string
    {
        return Storage::disk($this->disk)->temporaryUrl($this->path, now()->addMinutes($minutes));
    }
}
