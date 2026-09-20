<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * TASK-1450 — Un atelier (Workshop) de l'Organization (Growth V3 §7).
 *
 * Workshop != Loop : domaine dedie, Organization obligatoire, aucun `loop_id`.
 * Ce que la page publique peut montrer (V3 §8) : titre, promesse, description,
 * format, duree. Jamais : meeting_url, participants, notes internes, CRM,
 * credentials — ces champs n'existent pas ici ; les sessions (B4) porteront
 * la `meeting_url` SECRETE.
 *
 * Statuts : draft (invisible) → published (page publique) → retired (404 a
 * nouveau) ; un atelier retire peut etre republie. Le slug est l'URL publique :
 * fige des la premiere publication.
 *
 * @property string $id
 * @property string $organization_id
 * @property string|null $acquisition_journey_id
 * @property string $slug
 * @property string $title
 * @property string|null $promise
 * @property string|null $description
 * @property string $format
 * @property int|null $duration_minutes
 * @property string $locale
 * @property string|null $flyer_path
 * @property int|null $flyer_width
 * @property int|null $flyer_height
 * @property string $status
 * @property string|null $created_by
 * @property string|null $published_by
 * @property Carbon|null $published_at
 * @property Carbon|null $retired_at
 */
class Workshop extends Model
{
    use HasFactory;
    use HasUuids;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_RETIRED = 'retired';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_PUBLISHED, self::STATUS_RETIRED];

    public const FORMAT_IN_PERSON = 'in_person';

    public const FORMAT_ONLINE = 'online';

    public const FORMAT_HYBRID = 'hybrid';

    public const FORMATS = [self::FORMAT_IN_PERSON, self::FORMAT_ONLINE, self::FORMAT_HYBRID];

    /** TASK-1611 — le flyer : disque, dossier et bornes de validation, une seule fois. */
    public const FLYER_DISK = 'public';

    public const FLYER_DIRECTORY = 'workshops/flyers';

    public const FLYER_MAX_KILOBYTES = 5120;

    /** @var list<string> */
    public const FLYER_MIMES = ['jpeg', 'jpg', 'png', 'webp'];

    public const MAX_SLUG_CHARS = 80;

    public const MAX_TITLE_CHARS = 160;

    public const MAX_PROMISE_CHARS = 255;

    public const MAX_DESCRIPTION_CHARS = 5000;

    public const MAX_DURATION_MINUTES = 1440;

    /** Un slug public : minuscules, chiffres, tirets, 3 a 80 caracteres. */
    public const SLUG_PATTERN = '/^[a-z0-9][a-z0-9\-]{2,79}$/';

    protected $fillable = [
        'organization_id',
        'acquisition_journey_id',
        'slug',
        'title',
        'promise',
        'description',
        'format',
        'duration_minutes',
        'locale',
        'flyer_path',
        'flyer_width',
        'flyer_height',
        'status',
        'created_by',
        'published_by',
        'published_at',
        'retired_at',
    ];

    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'flyer_width' => 'integer',
            'flyer_height' => 'integer',
            'published_at' => 'datetime',
            'retired_at' => 'datetime',
        ];
    }

    /** TASK-1611 — un atelier porte AU PLUS un visuel, et il est facultatif. */
    public function hasFlyer(): bool
    {
        return filled($this->flyer_path);
    }

    /**
     * L'URL PUBLIQUE et ABSOLUE du flyer — celle que les reseaux sociaux
     * telechargent. Elle est batie sur `APP_URL` par le disque `public`
     * (config/filesystems), jamais sur l'hote de la requete : un partage fait
     * depuis un domaine de courtoisie ne doit pas propager ce domaine.
     */
    public function flyerUrl(): ?string
    {
        if (! $this->hasFlyer()) {
            return null;
        }

        $url = Storage::disk(self::FLYER_DISK)->url($this->flyer_path);

        // Selon le pilote, le disque rend deja une URL absolue (S3, ou
        // `FILESYSTEM_PUBLIC_URL` pose) ou un simple chemin `/storage/...`.
        // Un reseau social ne sait rien faire d'un chemin : on le complete sur
        // `APP_URL`, jamais sur l'hote de la requete — partager depuis un
        // domaine de courtoisie ne doit pas propager ce domaine.
        return str_starts_with($url, 'http://') || str_starts_with($url, 'https://')
            ? $url
            : rtrim((string) config('app.url'), '/').'/'.ltrim($url, '/');
    }

    /** Le type MIME deduit de l'extension stockee — `og:image:type`. */
    public function flyerMimeType(): ?string
    {
        if (! $this->hasFlyer()) {
            return null;
        }

        return match (strtolower(pathinfo($this->flyer_path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => null,
        };
    }

    public static function isValidSlug(string $slug): bool
    {
        return preg_match(self::SLUG_PATTERN, $slug) === 1;
    }

    /** @return list<string> */
    public static function supportedLocales(): array
    {
        return AcquisitionJourney::supportedLocales();
    }

    public function scopeForOrganization(Builder $query, Organization|string $organization): Builder
    {
        return $query->where('organization_id', $organization instanceof Organization ? $organization->getKey() : $organization);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    public function isRetired(): bool
    {
        return $this->status === self::STATUS_RETIRED;
    }

    /** Le slug est l'URL publique : fige des qu'une publication a eu lieu. */
    public function hasBeenPublished(): bool
    {
        return $this->published_at !== null;
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function journey(): BelongsTo
    {
        return $this->belongsTo(AcquisitionJourney::class, 'acquisition_journey_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    /** TASK-1451 (B4-A) : les sessions de l'atelier. */
    public function sessions(): HasMany
    {
        return $this->hasMany(WorkshopSession::class);
    }

    /** TASK-1454 : les inscriptions (toutes sessions) et les interets Guest — lecture OrgAdmin/SuperAdmin. */
    public function registrations(): HasMany
    {
        return $this->hasMany(WorkshopRegistration::class);
    }

    public function interests(): HasMany
    {
        return $this->hasMany(WorkshopSessionInterest::class);
    }

    /** Les sessions publiees a venir, celles que la page publique montre (jamais un brouillon, jamais une annulee). */
    public function publicUpcomingSessions(): HasMany
    {
        return $this->sessions()->published()->upcoming()->orderBy('starts_at');
    }
}
