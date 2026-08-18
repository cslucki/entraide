<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trace d'un changement de reglage du credit IA par utilisateur (TASK-1229) :
 * qui, quand, quoi (avant / apres). Plateforme (`organization_id` NULL) ou
 * Organization. Ecrite UNIQUEMENT par `AiUserCreditSettings` ; jamais
 * modifiee.
 */
class AiCreditSettingChange extends Model
{
    use HasUuids;

    public const SCOPE_PLATFORM = 'platform';

    public const SCOPE_ORGANIZATION = 'organization';

    public const UPDATED_AT = null;

    protected $fillable = [
        'scope',
        'organization_id',
        'changes',
        'changed_by',
    ];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
