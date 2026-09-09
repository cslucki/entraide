<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * TASK-1439 — UsageReference V1 (Shell Welcome V3 §9, MASTER Q66/Q67).
 *
 * « A quoi sert cette surface et comment l'utilise-t-on ? » Un texte CURE par
 * un humain, versionne, publie explicitement — jamais genere. Plateforme-only :
 * pas d'organization_id, pas d'override tenant en V1.
 *
 * UsageReference != PageContext (« ou suis-je ? ») != Runtime (« que
 * peut-on faire ici, maintenant ? ») != Constitution (« comment l'IA se
 * comporte ») != Journey. Ne jamais les fusionner.
 *
 * Cycle : `draft` (editable) -> `published` (IMMUABLE : pour changer le texte,
 * une nouvelle version) -> `retired` (historique). Une seule `published` par
 * (surface, locale), garantie en base.
 */
class UsageReference extends Model
{
    use HasUuids;

    public const STATE_DRAFT = 'draft';

    public const STATE_PUBLISHED = 'published';

    public const STATE_RETIRED = 'retired';

    public const STATES = [self::STATE_DRAFT, self::STATE_PUBLISHED, self::STATE_RETIRED];

    public const SURFACE_ORGANIZATION_HOME = 'organization_home';

    public const SURFACE_SHELL_WELCOME = 'shell_welcome';

    public const SURFACE_WORKSHOP = 'workshop';

    public const SURFACE_WORKSHOP_SESSION = 'workshop_session';

    public const SURFACE_SIGNUP = 'signup';

    // TASK-1477 — les surfaces MEMBRE. Leurs cles sont EXACTEMENT celles de
    // `AiShellPageContext::SURFACE_ROUTES`, et un test le verifie : deux
    // vocabulaires pour le meme lieu recreeraient l'homonymie que TASK-1473 a
    // du defaire.
    //
    // `organization_home` n'y figure pas : depuis TASK-1473 ce mot designe
    // l'ACCUEIL PUBLIC des deux cotes, et le tableau de bord porte `dashboard`.
    //
    // `dossier` et `article` n'y figurent pas non plus : une UsageReference
    // explique un LIEU (« a quoi sert cet endroit »), pas un objet particulier.
    // Un Dossier precis n'a pas de mode d'emploi propre — le rayon « Dossiers »
    // en a un.

    public const SURFACE_DASHBOARD = 'dashboard';

    public const SURFACE_AGENDA = 'agenda';

    public const SURFACE_DIRECTORY = 'directory';

    public const SURFACE_EXCHANGES = 'exchanges';

    public const SURFACE_DOSSIERS = 'dossiers';

    public const SURFACE_BLOG = 'blog';

    public const SURFACE_PROFILE = 'profile';

    /**
     * Les surfaces PUBLIQUES / Guest (V3 §9).
     *
     * Cette liste reste distincte parce qu'un parcours d'acquisition s'adresse
     * a un VISITEUR : lui proposer « l'annuaire » ou « l'agenda » n'aurait pas
     * de sens, et `OrgAcquisitionController` lit donc cette constante-ci et non
     * `SURFACES`.
     */
    public const SURFACES_PUBLIC = [
        self::SURFACE_ORGANIZATION_HOME,
        self::SURFACE_SHELL_WELCOME,
        self::SURFACE_WORKSHOP,
        self::SURFACE_WORKSHOP_SESSION,
        self::SURFACE_SIGNUP,
    ];

    /** Les surfaces MEMBRE, celles que le Shell « BouclePro IA » peut nommer. */
    public const SURFACES_MEMBER = [
        self::SURFACE_DASHBOARD,
        self::SURFACE_AGENDA,
        self::SURFACE_DIRECTORY,
        self::SURFACE_EXCHANGES,
        self::SURFACE_DOSSIERS,
        self::SURFACE_BLOG,
        self::SURFACE_PROFILE,
    ];

    /**
     * Toutes les surfaces adressables. Elargir cette liste n'ecrit AUCUN
     * contenu et n'accorde AUCUN droit : elle dit seulement quelles cles la
     * table accepte et lesquelles le resolver consent a chercher.
     */
    public const SURFACES = [
        ...self::SURFACES_PUBLIC,
        ...self::SURFACES_MEMBER,
    ];

    public const DEFAULT_MAX_CHARS = 4000;

    public const MAX_TITLE_CHARS = 160;

    protected $fillable = [
        'surface_key',
        'locale',
        'title',
        'content',
        'version',
        'state',
        'created_by',
        'published_by',
        'published_at',
        'retired_at',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'published_at' => 'datetime',
            'retired_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Une version publiee ne change plus silencieusement : seul son ETAT peut
        // evoluer (published -> retired). Le contenu d'une version publiee est
        // ce que les visiteurs ont lu ; pour le changer, on publie une nouvelle version.
        static::updating(function (self $reference): void {
            if ($reference->getOriginal('state') !== self::STATE_PUBLISHED) {
                return;
            }

            foreach (['surface_key', 'locale', 'title', 'content', 'version'] as $field) {
                if ($reference->isDirty($field)) {
                    throw new LogicException("A published usage reference is immutable ([{$field}]) : publish a new version instead.");
                }
            }
        });
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('state', self::STATE_PUBLISHED);
    }

    public function scopeForSurface(Builder $query, string $surfaceKey): Builder
    {
        return $query->where('surface_key', $surfaceKey);
    }

    public function scopeForLocale(Builder $query, string $locale): Builder
    {
        return $query->where('locale', $locale);
    }

    public function isDraft(): bool
    {
        return $this->state === self::STATE_DRAFT;
    }

    public function isPublished(): bool
    {
        return $this->state === self::STATE_PUBLISHED;
    }

    public static function maxChars(): int
    {
        return max(1, (int) config('ai.usage_reference.max_chars', self::DEFAULT_MAX_CHARS));
    }

    /** L'autorite de locale existante (config), jamais un code en dur. */
    public static function supportedLocales(): array
    {
        $locales = config('services.supported_locales', ['fr', 'en']);

        return is_array($locales) && $locales !== [] ? array_values($locales) : ['fr', 'en'];
    }

    /** La locale canonique de la plateforme — le repli du resolver (MASTER Q66). */
    public static function platformLocale(): string
    {
        return (string) config('app.locale', config('app.fallback_locale', 'en'));
    }

    /** Normalisation minimale et deterministe. Aucune reecriture du contenu. */
    public static function normalize(string $text): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $text));
    }
}
