<?php

namespace App\Models;

use Database\Factories\CrmContactEventFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * TASK-1414 — un fait de la timeline d'un Contact.
 *
 * APPEND-ONLY : un evenement s'ecrit une fois et ne se modifie jamais (CDC §6 :
 * « aucune disparition silencieuse »). La garde est dans le modele, pas
 * seulement dans les usages : `update()` ou `delete()` sur une ligne existante
 * levent. La suppression en cascade avec le Contact reste possible au niveau
 * SQL — c'est le Contact qui disparait, pas un fait isole.
 *
 * Le `payload` porte ce qu'il faut pour relire le fait SANS dependre de l'etat
 * courant (par exemple les libelles des statuts au moment du changement : un
 * statut renomme ensuite ne reecrit pas l'histoire).
 */
class CrmContactEvent extends Model
{
    /** @use HasFactory<CrmContactEventFactory> */
    use HasFactory, HasUuids;

    public const TYPE_STATUS_CHANGED = 'status_changed';

    /** TASK-1415 — une note ecrite par un humain ; `payload.channel` non nul = interaction reelle. */
    public const TYPE_NOTE = 'note';

    public const TYPE_ACCOUNT_CREATED = 'account_created';

    public const TYPE_EMAIL_VERIFIED = 'email_verified';

    /** TASK-1417 — coordonnees modifiees par un humain : `payload.changes` = champ => [from, to]. */
    public const TYPE_CONTACT_UPDATED = 'contact_updated';

    /** Faits systeme : sans auteur humain, une seule fois par Contact. */
    public const SYSTEM_TYPES = [self::TYPE_ACCOUNT_CREATED, self::TYPE_EMAIL_VERIFIED];

    protected $fillable = [
        'organization_id',
        'crm_contact_id',
        'type',
        'author_user_id',
        'payload',
        'occurred_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'occurred_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('A CRM timeline event is append-only and cannot be updated.');
        });

        static::deleting(function () {
            throw new LogicException('A CRM timeline event is append-only and cannot be deleted.');
        });
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(CrmContact::class, 'crm_contact_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function scopeForOrganization(Builder $query, Organization|string $organization): Builder
    {
        $id = $organization instanceof Organization ? $organization->id : $organization;

        return $query->where('crm_contact_events.organization_id', $id);
    }

    public function scopeChronological(Builder $query): Builder
    {
        return $query->orderBy('crm_contact_events.occurred_at')->orderBy('crm_contact_events.created_at');
    }
}
