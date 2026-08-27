<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TASK-1315 — un message du fil du Shell « BouclePro IA ».
 *
 * Le fil est defini par le couple `(organization_id, user_id)`, et porte un
 * `conversation_id` STABLE, reutilise d'une page a l'autre — il n'y a pas de
 * ligne « conversation » a maintenir a cote. Organization = Tenant : deux Organizations ne
 * partagent jamais un fil, meme pour le meme utilisateur, et c'est le scope
 * `forThread()` — utilise partout — qui le rend inevitable.
 */
class AiShellMessage extends Model
{
    use HasUuids;

    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'user_id',
        'conversation_id',
        'role',
        'content',
        'reply_to_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime:Y-m-d H:i:s.u',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** La seule porte de lecture : jamais un `where('user_id')` nu ailleurs. */
    public function scopeForThread(Builder $query, string $organizationId, string $userId): Builder
    {
        return $query
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId);
    }
}
