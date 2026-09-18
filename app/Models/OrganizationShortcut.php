<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TASK-1447 — OrganizationShortcut (Growth V2 §5, MASTER Q75) : `/s/{code}`
 * → 302 vers une destination CANONIQUE de l'Organization. Aucune URL libre,
 * aucune destination Workshop avant sa phase, aucune nouvelle autorite tenant.
 * Le navigateur n'est jamais l'autorite d'attribution : au premier geste
 * Guest, c'est le Shortcut relu en base qui donne la Journey et la campagne.
 */
class OrganizationShortcut extends Model
{
    use HasUuids;

    public const DESTINATION_ORGANIZATION_HOME = 'organization_home';

    public const DESTINATION_SIGNUP = 'signup';

    public const DESTINATIONS = [self::DESTINATION_ORGANIZATION_HOME, self::DESTINATION_SIGNUP];

    public const CODE_PATTERN = '/^[a-z0-9][a-z0-9\-]{2,31}$/';

    /** Codes qui entreraient en collision avec des routes ou des mots reserves de la plateforme. */
    public const RESERVED_CODES = ['org', 'admin', 'api', 'login', 'register', 'logout', 'password', 'profile', 'search', 'explorer', 'sitemap', 'membres', 'echanges', 'partenaires', 'partners', 'boucles', 'loops', 'mycelium', 'shell', 'new', 'edit', 'create', 'delete', 'www', 'app'];

    public const QUERY_PARAM = 'shortcut';

    protected $fillable = [
        'organization_id',
        'code',
        'destination',
        'acquisition_journey_key',
        'campaign',
        'active',
        'created_by',
    ];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    public function scopeForOrganization(Builder $query, Organization|string $organization): Builder
    {
        return $query->where('organization_id', $organization instanceof Organization ? $organization->getKey() : $organization);
    }

    public static function isValidCode(mixed $code): bool
    {
        return is_string($code) && preg_match(self::CODE_PATTERN, $code) === 1 && ! in_array($code, self::RESERVED_CODES, true);
    }

    /** La destination canonique, calculee cote serveur — jamais stockee comme URL. */
    public function destinationUrl(array $query = []): string
    {
        $organization = $this->organization;
        $route = match ($this->destination) {
            self::DESTINATION_SIGNUP => route('organization.register', ['organization' => $organization->slug]),
            default => route('organization.home', ['organization' => $organization->slug]),
        };
        $query = array_filter(['shortcut' => $this->code, 'journey' => $this->acquisition_journey_key, 'campaign' => $this->campaign] + $query, fn ($v) => $v !== null && $v !== '');

        return $query === [] ? $route : $route.'?'.http_build_query($query);
    }
}
