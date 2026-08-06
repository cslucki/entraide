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
     * Les fuseaux offerts d'emblee dans le menu.
     *
     * Une liste courte et non les ~400 identifiants IANA : un menu de quatre
     * cents lignes sans recherche n'aide personne. Elle ne prétend pas couvrir
     * le monde — c'est timezoneOptions() qui s'en charge, en y ajoutant le
     * fuseau detecte quand il n'y figure pas. Quelqu'un a Antananarivo trouve
     * donc le sien, meme s'il n'est pas ecrit ici.
     *
     * Le stockage accepte n'importe quel identifiant IANA valide : allonger
     * cette liste plus tard ne casse rien.
     */
    public const TIMEZONES = [
        'Europe/Paris',
        'Europe/Brussels',
        'Europe/Zurich',
        'Europe/London',
        'America/Montreal',
        'America/New_York',
        'America/Chicago',
        'America/Los_Angeles',
        'Africa/Dakar',
        'Africa/Abidjan',
        'Indian/Antananarivo',
        'Indian/Reunion',
        'UTC',
    ];

    /**
     * Le dernier recours, quand plus rien d'autre ne repond.
     *
     * Ce n'est PAS le defaut du formulaire : celui-ci vient du navigateur, via
     * resolveTimezone(). Cette constante n'est atteinte que si le navigateur ne
     * dit rien et que la timezone applicative est un UTC generique — auquel cas
     * il faut bien choisir quelque chose, et le pilote actuel est francophone.
     *
     * Ecrire ce nom en dur comme valeur initiale serait un choix francais dans
     * un produit qui s'adresse a des Organizations de plusieurs continents :
     * quelqu'un a Dallas ou a Antananarivo verrait ses horaires decales sans
     * qu'on le lui dise.
     */
    public const FALLBACK_TIMEZONE = 'Europe/Paris';

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

    /**
     * Le fuseau a proposer, dans l'ordre des sources qui savent quelque chose.
     *
     *   1. ce qui est deja dans le formulaire — un choix fait a la main, ou une
     *      valeur restauree apres une erreur de validation, ne se remplace pas ;
     *   2. ce que le navigateur declare — la seule source qui sache reellement
     *      ou se trouve la personne, faute de preference enregistree ;
     *   3. la timezone applicative, si elle dit autre chose qu'« UTC » ;
     *   4. le dernier recours.
     *
     * Chaque candidat est verifie contre les identifiants IANA de PHP : le
     * navigateur est une source, jamais une autorite.
     */
    public static function resolveTimezone(?string $current = null, ?string $detected = null): string
    {
        foreach ([$current, $detected, config('app.timezone')] as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            $candidate = trim($candidate);

            // Un UTC generique n'est pas un lieu : c'est l'absence de reponse.
            if ($candidate === '' || strtoupper($candidate) === 'UTC' || strtoupper($candidate) === 'GMT') {
                continue;
            }

            if (self::isValidTimezone($candidate)) {
                return $candidate;
            }
        }

        return self::FALLBACK_TIMEZONE;
    }

    /**
     * Un identifiant IANA reconnu par ce PHP, et rien d'autre.
     *
     * Refuse donc un decalage brut (`+02:00`), un sigle (`CEST`), une chaine
     * inventee, et la chaine vide. La liste vient de PHP, jamais du client.
     */
    public static function isValidTimezone(?string $timezone): bool
    {
        return is_string($timezone)
            && $timezone !== ''
            && in_array($timezone, timezone_identifiers_list(), true);
    }

    /**
     * Les fuseaux a offrir dans le menu.
     *
     * La liste bornee, plus celui qu'on s'apprete a montrer s'il n'y figure pas :
     * sans cela, une personne a Antananarivo verrait son propre fuseau detecte
     * puis absent du menu, donc impossible a retrouver apres l'avoir quitte.
     *
     * @return array<int, string>
     */
    public static function timezoneOptions(?string $selected = null): array
    {
        $options = self::TIMEZONES;

        if (self::isValidTimezone($selected) && ! in_array($selected, $options, true)) {
            array_unshift($options, $selected);
        }

        return $options;
    }
}
