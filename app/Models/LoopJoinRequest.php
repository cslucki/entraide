<?php

namespace App\Models;

use App\Models\Traits\HasOrganizationId;
use Database\Factories\LoopJoinRequestFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoopJoinRequest extends Model
{
    /** @use HasFactory<LoopJoinRequestFactory> */
    use HasFactory, HasOrganizationId, HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'organization_id',
        'loop_id',
        'user_id',
        'message',
        'status',
        'decided_by',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'string',
            'decided_at' => 'datetime',
        ];
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * TASK-1885 C4 (demande Cyril, 22/11) — « pourquoi ils ont souhaite
     * rejoindre » : la phrase de la DERNIERE demande acceptee de chaque membre,
     * indexee par `user_id`.
     *
     * Un membre ajoute directement, invite ou entre dans une Boucle ouverte n'a
     * pas de demande : il n'a pas d'entree, et l'ecran n'affiche rien. Une
     * seule requete pour toute la liste (jamais une par ligne). Tenant
     * explicite : l'Organization de la Boucle.
     *
     * @param  iterable<int, string|null>  $userIds
     * @return array<string, string>
     */
    public static function joinReasonsFor(Loop $loop, iterable $userIds): array
    {
        $ids = collect($userIds)->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return static::query()
            ->where('organization_id', $loop->organization_id)
            ->where('loop_id', $loop->id)
            ->whereIn('user_id', $ids)
            ->where('status', self::STATUS_ACCEPTED)
            ->whereNotNull('message')
            // Ordre croissant : la plus recente ecrase les precedentes.
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['user_id', 'message'])
            ->filter(fn (self $demande) => trim((string) $demande->message) !== '')
            ->mapWithKeys(fn (self $demande) => [(string) $demande->user_id => trim((string) $demande->message)])
            ->all();
    }

    public function loop(): BelongsTo
    {
        return $this->belongsTo(Loop::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
