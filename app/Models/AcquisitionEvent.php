<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * TASK-1449 — Un fait du parcours d'acquisition (Growth V3 §5, MASTER Q77).
 *
 * Append-only : ni mise a jour ni suppression par le produit (garde ci-dessous,
 * comme `CrmContactEvent`). Les 12 evenements sont EXACTEMENT ceux du CDC ;
 * ceux dont le producteur n'existe pas encore restent sans writer jusqu'a leur
 * TASK — jamais un faux evenement.
 *
 * Ce n'est pas un event sourcing : l'etat produit vit dans ses tables (Guest,
 * conversation, User, Contact) ; ce journal permet seulement de RECONSTRUIRE
 * le parcours et de rattacher un cout/une conversion a une provenance.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $event
 * @property string|null $acquisition_journey_id
 * @property string|null $guest_visitor_id
 * @property string|null $guest_conversation_id
 * @property string|null $user_id
 * @property string|null $referrer
 * @property string|null $utm_source
 * @property string|null $utm_medium
 * @property string|null $utm_campaign
 * @property string|null $shortcut
 * @property string|null $locale
 * @property array<string, string>|null $metadata
 * @property string|null $dedupe_key
 * @property Carbon $created_at
 */
class AcquisitionEvent extends Model
{
    use HasFactory;
    use HasUuids;

    public const UPDATED_AT = null;

    public const SHORTCUT_OPENED = 'shortcut_opened';

    public const GUEST_CREATED = 'guest_created';

    public const CONVERSATION_STARTED = 'conversation_started';

    public const CTA_SHOWN = 'cta_shown';

    public const WORKSHOP_VIEWED = 'workshop_viewed';

    public const SESSION_SELECTED = 'session_selected';

    public const SIGNUP_STARTED = 'signup_started';

    public const ACCOUNT_CREATED = 'account_created';

    public const EMAIL_VERIFIED = 'email_verified';

    public const PARTICIPATION_CONFIRMED = 'participation_confirmed';

    public const CRM_CONTACT_LINKED = 'crm_contact_linked';

    public const CONVERTED = 'converted';

    /** Les 12 evenements V1 du CDC Growth V3 §5 — verbatim, dans son ordre. */
    public const EVENTS = [
        self::SHORTCUT_OPENED,
        self::GUEST_CREATED,
        self::CONVERSATION_STARTED,
        self::CTA_SHOWN,
        self::WORKSHOP_VIEWED,
        self::SESSION_SELECTED,
        self::SIGNUP_STARTED,
        self::ACCOUNT_CREATED,
        self::EMAIL_VERIFIED,
        self::PARTICIPATION_CONFIRMED,
        self::CRM_CONTACT_LINKED,
        self::CONVERTED,
    ];

    /** Metadata bornee : au plus N cles, valeurs scalaires tronquees. */
    public const METADATA_MAX_KEYS = 20;

    public const METADATA_MAX_VALUE_CHARS = 200;

    protected $fillable = [
        'organization_id',
        'event',
        'acquisition_journey_id',
        'guest_visitor_id',
        'guest_conversation_id',
        'user_id',
        'referrer',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'shortcut',
        'locale',
        'metadata',
        'dedupe_key',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('An acquisition event is append-only and cannot be updated.');
        });

        static::deleting(function () {
            throw new LogicException('An acquisition event is append-only and cannot be deleted.');
        });
    }

    public static function isValidEvent(string $event): bool
    {
        return in_array($event, self::EVENTS, true);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function journey(): BelongsTo
    {
        return $this->belongsTo(AcquisitionJourney::class, 'acquisition_journey_id');
    }

    public function visitor(): BelongsTo
    {
        return $this->belongsTo(GuestVisitor::class, 'guest_visitor_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(GuestConversation::class, 'guest_conversation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
