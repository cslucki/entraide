<?php

namespace App\Models;

use App\Ai\Constitution;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Constitution IA de la PLATEFORME, versionnee (TASK-1348).
 *
 * Ce que c'est : du TEXTE, ecrit par un Super Admin, compose par
 * `PromptRepository` SOUS le socle de code immuable et AU-DESSUS de tout
 * texte d'Organization. Il repond a « qui sommes-nous et quels principes
 * fondamentaux gouvernent notre IA ? ».
 *
 * Ce que ce n'est PAS : une garantie de securite. Le tenant, les sources
 * autorisees, la portee, la validation humaine et les gardes economiques sont
 * appliques EN CODE et ne dependent d'aucun texte. Une constitution hostile
 * peut demander n'importe quoi : elle n'elargit pas d'un octet le contexte
 * reellement transmis.
 *
 * Portee particuliere, donc prudence particuliere : ce texte est compose dans
 * CHAQUE appel de CHAQUE capability de TOUTES les Organizations. C'est
 * precisement pourquoi le socle de code le domine et pourquoi sa borne de
 * caracteres est re-appliquee a la composition.
 *
 * Invariants de stockage, repris de {@see OrganizationAiDoctrine} :
 * - une seule version `active` pour toute la plateforme (index unique partiel) ;
 * - l'historique est conserve (les versions precedentes passent `superseded`) ;
 * - `activate()` et `withdraw()` sont les SEULES primitives d'ecriture.
 */
class PlatformAiConstitution extends Model
{
    use HasUuids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUPERSEDED = 'superseded';

    /**
     * Borne dure du texte, en caracteres. Plus large que la doctrine (4000) :
     * la Constitution porte l'identite et les principes de la plateforme
     * entiere, pas les preferences d'un tenant. Elle reste bornee — un texte
     * compose dans tous les appels ne peut pas etre libre.
     */
    public const DEFAULT_MAX_CHARS = 6000;

    protected $fillable = [
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
        // Constructible sans framework boote (tests unitaires de composition).
        if (! function_exists('app') || ! app()->bound('config')) {
            return self::DEFAULT_MAX_CHARS;
        }

        return max(1, (int) config('ai.constitution.max_chars', self::DEFAULT_MAX_CHARS));
    }

    /**
     * La version active de la plateforme, ou null.
     *
     * La garde `bound('db')` reprend l'idiome de `maxChars()` et de
     * `CapabilityRegistry` : la composition d'un prompt doit rester possible
     * SANS base — c'est ce qui permet aux tests unitaires de
     * `PromptRepository` de tourner sans framework complet. Hors de ce cas,
     * la base est toujours la, et c'est elle qui fait autorite.
     */
    public static function active(): ?self
    {
        if (! function_exists('app') || ! app()->bound('db')) {
            return null;
        }

        return self::query()->active()->orderByDesc('version')->first();
    }

    /**
     * Le texte REELLEMENT compose : la version active si elle existe, sinon la
     * graine immuable du code.
     *
     * C'est ce repli qui garantit qu'une installation existante et non
     * provisionnee ne tombe jamais en panne d'IA, et que la composition reste
     * byte-identique a celle d'avant TASK-1348.
     */
    public static function activeTextOrSeed(): string
    {
        return self::active()?->body ?? (new Constitution)->text();
    }

    /**
     * Primitive d'ecriture UNIQUE : nouvelle version, activee immediatement.
     *
     * Un texte identique a la version active ne cree pas de version
     * (l'appelant recoit la version active) — meme regle que la doctrine :
     * republier a l'identique n'est pas un evenement.
     */
    public static function activate(string $body, User $author): self
    {
        $body = self::normalize($body);

        if ($body === '') {
            throw new InvalidArgumentException('A platform AI constitution requires a non-blank body.');
        }

        if (mb_strlen($body) > self::maxChars()) {
            throw new InvalidArgumentException('A platform AI constitution exceeds the maximum length.');
        }

        return DB::transaction(function () use ($body, $author): self {
            // Les ecrivains sont serialises sur la ligne active courante : deux
            // enregistrements simultanes ne peuvent ni produire deux versions
            // actives, ni se disputer `max(version) + 1`. La table n'ayant pas
            // de ligne tenant a verrouiller, c'est la ligne active qui joue ce
            // role ; sur une table vide il n'y a rien a serialiser, et l'index
            // unique partiel reste le juge de derniere instance.
            $current = self::query()->active()->orderByDesc('version')->lockForUpdate()->first();

            if ($current !== null && $current->body === $body) {
                return $current;
            }

            $now = now();

            self::query()->active()->update([
                'status' => self::STATUS_SUPERSEDED,
                'superseded_at' => $now,
                'updated_at' => $now,
            ]);

            $nextVersion = ((int) self::query()->max('version')) + 1;

            return self::query()->create([
                'version' => $nextVersion,
                'body' => $body,
                'status' => self::STATUS_ACTIVE,
                'created_by' => $author->id,
                'activated_at' => $now,
            ]);
        });
    }

    /**
     * Retire la version active sans en creer une nouvelle : la plateforme
     * revient a la graine immuable du code ; l'historique reste intact.
     */
    public static function withdraw(): bool
    {
        return DB::transaction(function (): bool {
            $now = now();

            return self::query()->active()->update([
                'status' => self::STATUS_SUPERSEDED,
                'superseded_at' => $now,
                'updated_at' => $now,
            ]) > 0;
        });
    }

    /** Normalisation minimale et deterministe. Aucune reecriture du contenu. */
    public static function normalize(string $body): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $body));
    }
}
