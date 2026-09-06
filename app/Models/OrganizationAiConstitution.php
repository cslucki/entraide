<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Constitution IA d'une ORGANIZATION, versionnee et OPTIONNELLE (TASK-1348).
 *
 * Elle repond a « qui sommes-nous et quels principes fondamentaux gouvernent
 * notre IA ? » pour CE tenant. Distincte de {@see OrganizationAiDoctrine},
 * qui repond a « comment voulons-nous que l'IA se comporte dans notre
 * metier ? » — la Constitution est composee AU-DESSUS de la Doctrine.
 *
 * Organization = Tenant : `organization_id` est OBLIGATOIRE, jamais nullable,
 * et toute lecture en est bornee. La Constitution de A n'est jamais composee
 * pour B — c'est un invariant teste, pas une intention.
 *
 * Aucune garantie de securite ne depend de ce texte : tenant, sources, portee
 * et validation humaine sont appliques en code, sous un socle immuable qui
 * domine tout texte administrable.
 *
 * Invariants de stockage, identiques a la doctrine :
 * - une seule version `active` par Organization ;
 * - l'historique est conserve ;
 * - `activate()` et `withdraw()` sont les SEULES primitives d'ecriture.
 */
class OrganizationAiConstitution extends Model
{
    use HasUuids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUPERSEDED = 'superseded';

    /** Meme borne que la doctrine : c'est un texte de tenant, pas de plateforme. */
    public const DEFAULT_MAX_CHARS = 4000;

    protected $fillable = [
        'organization_id',
        'version',
        'body',
        'status',
        'created_by',
        'activated_at',
        'superseded_at',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'activated_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
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
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public static function maxChars(): int
    {
        if (! function_exists('app') || ! app()->bound('config')) {
            return self::DEFAULT_MAX_CHARS;
        }

        return max(1, (int) config('ai.constitution.org_max_chars', self::DEFAULT_MAX_CHARS));
    }

    /**
     * La version active de CETTE Organization, ou null. Toujours bornee par
     * `organization_id` : la Constitution de A n'est jamais lue pour B.
     */
    public static function activeFor(string $organizationId): ?self
    {
        // Meme garde que la Constitution plateforme : composer un prompt reste
        // possible sans base bootee.
        if (! function_exists('app') || ! app()->bound('db')) {
            return null;
        }

        return self::query()
            ->where('organization_id', $organizationId)
            ->active()
            ->orderByDesc('version')
            ->first();
    }

    /** Primitive d'ecriture UNIQUE : nouvelle version, activee immediatement. */
    public static function activate(Organization $organization, string $body, User $author): self
    {
        $body = self::normalize($body);

        if ($body === '') {
            throw new InvalidArgumentException('An Organization AI constitution requires a non-blank body.');
        }

        if (mb_strlen($body) > self::maxChars()) {
            throw new InvalidArgumentException('An Organization AI constitution exceeds the maximum length.');
        }

        return DB::transaction(function () use ($organization, $body, $author): self {
            // Les ecrivains d'UNE Organization sont serialises sur sa ligne
            // tenant, exactement comme la doctrine : deux enregistrements
            // simultanes ne peuvent ni produire deux versions actives, ni se
            // disputer `max(version) + 1`. Sans effet sur les autres tenants.
            self::lockTenantRow($organization);

            $current = self::query()
                ->where('organization_id', $organization->id)
                ->active()
                ->orderByDesc('version')
                ->first();

            if ($current !== null && $current->body === $body) {
                return $current;
            }

            $now = now();

            self::query()
                ->where('organization_id', $organization->id)
                ->active()
                ->update([
                    'status' => self::STATUS_SUPERSEDED,
                    'superseded_at' => $now,
                    'updated_at' => $now,
                ]);

            $nextVersion = ((int) self::query()
                ->where('organization_id', $organization->id)
                ->max('version')) + 1;

            return self::query()->create([
                'organization_id' => $organization->id,
                'version' => $nextVersion,
                'body' => $body,
                'status' => self::STATUS_ACTIVE,
                'created_by' => $author->id,
                'activated_at' => $now,
            ]);
        });
    }

    /**
     * Retire la Constitution active sans en creer une nouvelle : l'Organization
     * revient a la composition sans Constitution propre ; l'historique reste.
     */
    public static function withdraw(Organization $organization): bool
    {
        return DB::transaction(function () use ($organization): bool {
            self::lockTenantRow($organization);

            $now = now();

            return self::query()
                ->where('organization_id', $organization->id)
                ->active()
                ->update([
                    'status' => self::STATUS_SUPERSEDED,
                    'superseded_at' => $now,
                    'updated_at' => $now,
                ]) > 0;
        });
    }

    /**
     * Verrou d'ecriture sur la ligne `organizations` du tenant, pour la duree
     * de la transaction courante (PostgreSQL ; sans effet sur SQLite, dont les
     * ecritures sont deja serialisees).
     */
    private static function lockTenantRow(Organization $organization): void
    {
        Organization::query()->whereKey($organization->id)->lockForUpdate()->first();
    }

    /** Normalisation minimale et deterministe. Aucune reecriture du contenu. */
    public static function normalize(string $body): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $body));
    }
}
