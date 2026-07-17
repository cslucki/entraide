<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DossierMember extends Model
{
    use HasFactory;
    use HasUuids;

    public const ROLE_READER = 'reader';

    public const ROLE_EDITOR = 'editor';

    protected $fillable = [
        'organization_id',
        'dossier_id',
        'user_id',
        'role',
        'added_by',
    ];

    protected function casts(): array
    {
        return [
            'role' => 'string',
        ];
    }

    public function dossier(): BelongsTo
    {
        return $this->belongsTo(Dossier::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
