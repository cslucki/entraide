<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Doctrine IA d'une Organization, versionnee (TASK-1227).
 *
 * Ce que c'est : du TEXTE ecrit par l'Admin Organization, compose par
 * `PromptRepository` SOUS la Constitution BouclePro, comme preference
 * d'Organization delimitee et attribuee — jamais comme instruction systeme de
 * meme rang. Aucune garantie de securite (tenant, sources, validation
 * humaine) ne depend de ce texte : elles sont en code.
 *
 * Invariants de stockage :
 * - une seule version `active` par Organization ;
 * - l'historique est conserve (les versions precedentes passent `superseded`) ;
 * - `activate()` et `withdraw()` sont les SEULES primitives d'ecriture.
 */
class OrganizationAiDoctrine extends Model
{
    use HasUuids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUPERSEDED = 'superseded';

    /**
     * Borne dure du texte, en caracteres. La validation HTTP applique la meme
     * valeur ; la composition la re-applique defensivement.
     */
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
        // Constructible sans framework boote (tests unitaires de la
        // composition) : meme garde que CapabilityRegistry.
        if (! function_exists('app') || ! app()->bound('config')) {
            return self::DEFAULT_MAX_CHARS;
        }

        return max(1, (int) config('ai.doctrine.max_chars', self::DEFAULT_MAX_CHARS));
    }

    /**
     * La version active de CETTE Organization, ou null. Toujours bornee par
     * `organization_id` : la doctrine de A n'est jamais lue pour B.
     */
    public static function activeFor(string $organizationId): ?self
    {
        return self::query()
            ->where('organization_id', $organizationId)
            ->active()
            ->orderByDesc('version')
            ->first();
    }

    /**
     * Primitive d'ecriture UNIQUE : nouvelle version, activee immediatement.
     *
     * Transaction : la version active courante passe `superseded`, la
     * nouvelle prend `max(version) + 1`. Un texte identique a la version
     * active ne cree pas de version (l'appelant recoit la version active).
     */
    public static function activate(Organization $organization, string $body, User $author): self
    {
        $body = self::normalize($body);

        if ($body === '') {
            throw new InvalidArgumentException('An Organization AI doctrine requires a non-blank body.');
        }

        if (mb_strlen($body) > self::maxChars()) {
            throw new InvalidArgumentException('An Organization AI doctrine exceeds the maximum length.');
        }

        return DB::transaction(function () use ($organization, $body, $author): self {
            $current = self::query()
                ->where('organization_id', $organization->id)
                ->active()
                ->lockForUpdate()
                ->orderByDesc('version')
                ->first();

            if ($current !== null && $current->body === $body) {
                return $current;
            }

            $now = now();

            if ($current !== null) {
                $current->forceFill([
                    'status' => self::STATUS_SUPERSEDED,
                    'superseded_at' => $now,
                ])->save();
            }

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
     * Retire la doctrine active sans en creer une nouvelle : l'Organization
     * revient a la composition sans doctrine ; l'historique reste.
     */
    public static function withdraw(Organization $organization): bool
    {
        return DB::transaction(function () use ($organization): bool {
            $current = self::query()
                ->where('organization_id', $organization->id)
                ->active()
                ->lockForUpdate()
                ->first();

            if ($current === null) {
                return false;
            }

            $current->forceFill([
                'status' => self::STATUS_SUPERSEDED,
                'superseded_at' => now(),
            ])->save();

            return true;
        });
    }

    /**
     * Normalisation minimale et deterministe : fins de ligne unifiees, espaces
     * de bordure retires. Aucune reecriture du contenu.
     */
    public static function normalize(string $body): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $body));
    }
}
