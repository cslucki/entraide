<?php

namespace App\Models;

use Database\Factories\BulkEmailSendFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

/**
 * TASK-1803 — un envoi d'email en masse depuis le superadmin.
 *
 * `organization_id` NULL = « toutes les Organizations », une valeur a part
 * entiere. C'est pour cela que ce modele n'utilise PAS `HasOrganizationId` :
 * son hook de creation remplacerait ce NULL par l'Organization courante de
 * l'admin qui cree l'envoi, ce qui romprait silencieusement la cible
 * « toutes les Organizations ».
 */
class BulkEmailSend extends Model
{
    /** @use HasFactory<BulkEmailSendFactory> */
    use HasFactory, HasUuids;

    public const MEMBER_FILTER_ALL = 'all';

    public const MEMBER_FILTER_NO_PRESENTATION = 'no_presentation';

    /** @var list<string> */
    public const MEMBER_FILTERS = [self::MEMBER_FILTER_ALL, self::MEMBER_FILTER_NO_PRESENTATION];

    public const CONTENT_SOURCE_CUSTOM = 'custom';

    public const CONTENT_SOURCE_TEMPLATE = 'template';

    /** @var list<string> */
    public const CONTENT_SOURCES = [self::CONTENT_SOURCE_CUSTOM, self::CONTENT_SOURCE_TEMPLATE];

    public const CONTENT_FORMAT_PLAIN = 'plain';

    public const CONTENT_FORMAT_HTML = 'html';

    /** @var list<string> */
    public const CONTENT_FORMATS = [self::CONTENT_FORMAT_PLAIN, self::CONTENT_FORMAT_HTML];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SENDING = 'sending';

    public const STATUS_COMPLETED = 'completed';

    /** @var list<string> */
    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_SENDING, self::STATUS_COMPLETED];

    /**
     * Meme idiome que `EmailLog::booted()` — verifie ICI pour se comporter
     * identiquement sur SQLite et PostgreSQL (une colonne `string`, pas un
     * `enum`, n'est contrainte nativement que sur l'un des deux moteurs).
     */
    protected static function booted(): void
    {
        $verifier = function (self $envoi): void {
            if (! in_array($envoi->member_filter, self::MEMBER_FILTERS, true)) {
                throw new InvalidArgumentException("Unknown member_filter [{$envoi->member_filter}]. Allowed: ".implode(', ', self::MEMBER_FILTERS).'.');
            }
            if (! in_array($envoi->content_source, self::CONTENT_SOURCES, true)) {
                throw new InvalidArgumentException("Unknown content_source [{$envoi->content_source}]. Allowed: ".implode(', ', self::CONTENT_SOURCES).'.');
            }
            if ($envoi->content_format !== null && ! in_array($envoi->content_format, self::CONTENT_FORMATS, true)) {
                throw new InvalidArgumentException("Unknown content_format [{$envoi->content_format}]. Allowed: ".implode(', ', self::CONTENT_FORMATS).'.');
            }
            if (! in_array($envoi->status, self::STATUSES, true)) {
                throw new InvalidArgumentException("Unknown status [{$envoi->status}]. Allowed: ".implode(', ', self::STATUSES).'.');
            }
        };

        static::creating($verifier);
        static::updating($verifier);
    }

    protected $fillable = [
        'organization_id',
        'member_filter',
        'content_source',
        'email_template_id',
        'subject',
        'content_format',
        'content_raw',
        'status',
        'total_recipients',
        'sent_count',
        'failed_count',
        'created_by',
    ];

    // TASK-1803 — sans ce cast, le pilote de base de donnees peut rendre ces
    // colonnes en CHAINES ("0" plutot que 0), et `$send->total_recipients
    // === 0` (comparaison stricte, cote controleur) ne vaut jamais vrai.
    protected $casts = [
        'total_recipients' => 'integer',
        'sent_count' => 'integer',
        'failed_count' => 'integer',
    ];

    public function recipients(): HasMany
    {
        return $this->hasMany(BulkEmailSendRecipient::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class, 'email_template_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
