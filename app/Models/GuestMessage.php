<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * TASK-1434 — SW-4 : un message du Shell Welcome. Append-only : ce qui a ete
 * dit ne se reecrit pas. Un message assistant pointe vers l'invocation
 * provider qui l'a produit (`ai_provider_invocations`, autorite economique).
 */
class GuestMessage extends Model
{
    use HasUuids;

    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    public const ROLE_SYSTEM = 'system';

    public const ROLES = [self::ROLE_USER, self::ROLE_ASSISTANT, self::ROLE_SYSTEM];

    /**
     * Borne d'entree d'un message visiteur (input bound, Addendum V2 §11).
     * MASTER Q55 : une seule autorite pour le Shell — `ai.shell.max_input_chars`
     * (celle du Shell membre), jamais un second 2000 qui pourrait diverger.
     */
    public static function maxUserBodyLength(): int
    {
        return (int) config('ai.shell.max_input_chars', 2000);
    }

    protected $fillable = [
        'organization_id',
        'guest_conversation_id',
        'role',
        'body',
        'ai_provider_invocation_id',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('A guest message is append-only and cannot be updated.');
        });
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(GuestConversation::class, 'guest_conversation_id');
    }

    public function invocation(): BelongsTo
    {
        return $this->belongsTo(AiProviderInvocation::class, 'ai_provider_invocation_id');
    }

    public function isFromVisitor(): bool
    {
        return $this->role === self::ROLE_USER;
    }
}
