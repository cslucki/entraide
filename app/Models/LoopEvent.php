<?php

namespace App\Models;

use App\Models\Traits\HasOrganizationId;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Une rencontre proposee dans une Boucle.
 *
 * Les dates vivent en UTC ; `timezone` garde le fuseau IANA choisi a la
 * creation. Afficher, c'est donc toujours reconvertir — jamais lire la colonne
 * telle quelle.
 */
class LoopEvent extends Model
{
    use HasOrganizationId, HasUuids;

    public const FORMAT_IN_PERSON = 'in_person';

    public const FORMAT_ONLINE = 'online';

    public const FORMAT_HYBRID = 'hybrid';

    public const FORMATS = [self::FORMAT_IN_PERSON, self::FORMAT_ONLINE, self::FORMAT_HYBRID];

    public const VISIBILITY_LOOP = 'loop';

    public const VISIBILITY_ORGANIZATION = 'organization';

    public const VISIBILITIES = [self::VISIBILITY_LOOP, self::VISIBILITY_ORGANIZATION];

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Les fuseaux proposes dans le formulaire.
     *
     * Une liste bornee et non les ~400 identifiants IANA : ce produit s'adresse
     * a un public francophone, et un menu de quatre cents lignes n'aide
     * personne. Le stockage accepte n'importe quel identifiant valide, donc
     * elargir cette liste plus tard ne casse rien.
     */
    public const TIMEZONES = [
        'Europe/Paris',
        'Europe/Brussels',
        'Europe/Zurich',
        'Europe/London',
        'America/Montreal',
        'America/New_York',
        'America/Chicago',
        'Africa/Dakar',
        'Indian/Reunion',
        'UTC',
    ];

    /**
     * Le fuseau propose par defaut.
     *
     * `config('app.timezone')` vaut `UTC` dans ce projet, ce qui serait un
     * defaut absurde a montrer a quelqu'un qui organise une reunion a Paris. Le
     * stockage reste en UTC ; c'est l'affichage qui est en cause.
     */
    public const DEFAULT_TIMEZONE = 'Europe/Paris';

    protected $fillable = [
        'organization_id', 'loop_id', 'created_by',
        'title', 'description', 'format',
        'starts_at', 'ends_at', 'timezone',
        'location', 'meeting_url',
        'visibility', 'status', 'cancelled_at', 'cancelled_by',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function loop(): BelongsTo
    {
        return $this->belongsTo(Loop::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(LoopEventResponse::class, 'event_id');
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isScheduled(): bool
    {
        return $this->status === self::STATUS_SCHEDULED;
    }

    public function isOrganizationWide(): bool
    {
        return $this->visibility === self::VISIBILITY_ORGANIZATION;
    }

    /**
     * Passe, au sens de l'agenda : la fin si elle existe, sinon le debut.
     *
     * Une reunion de deux heures commencee il y a une heure n'est pas passee.
     */
    public function isPast(): bool
    {
        return ($this->ends_at ?? $this->starts_at)->isPast();
    }

    public function needsLocation(): bool
    {
        return in_array($this->format, [self::FORMAT_IN_PERSON, self::FORMAT_HYBRID], true);
    }

    public function needsMeetingUrl(): bool
    {
        return in_array($this->format, [self::FORMAT_ONLINE, self::FORMAT_HYBRID], true);
    }

    /** Le debut, lu dans le fuseau de l'Evenement. */
    public function startsAtLocal(): CarbonInterface
    {
        return $this->starts_at->copy()->setTimezone($this->timezone);
    }

    public function endsAtLocal(): ?CarbonInterface
    {
        return $this->ends_at?->copy()->setTimezone($this->timezone);
    }

    public function hasResponses(): bool
    {
        return $this->responses()->exists();
    }
}
