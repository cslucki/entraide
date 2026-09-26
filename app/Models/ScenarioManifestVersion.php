<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * TASK-1646 — une version de scenario, telle que l'administration la connait.
 *
 * Le Scenario Manager persiste des VERSIONS, pas des mondes. Une ligne porte le
 * document Manifest (`json_source`), son etat de revue, son approbation et sa
 * provenance. Elle ne cree aucune donnee metier : celles-la naissent au Load,
 * dans une sandbox, et appartiennent au moteur existant
 * (`ScenarioPackLoad` / `ScenarioPackEntity`).
 *
 * Les deux CDC qui font autorite :
 * `TODO/SPECS/260920-11h10-CDC-scenario-manifest.md` pour le langage,
 * `TODO/SPECS/260926-CDC-scenario-manager.md` pour le produit.
 *
 * ## Deux etats persistes, un troisieme derive
 *
 * `STATE_DRAFT` et `STATE_VALID` sont les SEULS etats stockes. `LOADED` est
 * derive par {@see self::isLoaded()} et n'a deliberement pas de colonne : ce
 * serait une seconde verite, et elle serait fausse des que la sandbox
 * disparaitrait. Voir le docblock de la migration pour la mesure qui rend
 * cette derivation fiable.
 */
class ScenarioManifestVersion extends Model
{
    use HasUuids;

    /** Brouillon : editable, potentiellement invalide, sans donnee metier (CDC 8.2). */
    public const STATE_DRAFT = 'draft';

    /** Valide : Validator vert ET digest confirme humainement (CDC 8.3). */
    public const STATE_VALID = 'valid';

    /**
     * La liste est FERMEE. `loaded` n'en fait pas partie, et ne doit jamais y
     * entrer : c'est un etat derive.
     */
    public const STATES = [
        self::STATE_DRAFT,
        self::STATE_VALID,
    ];

    public const USAGE_QA = 'qa';

    public const USAGE_DOGFOODING = 'dogfooding';

    public const USAGE_DEMO = 'demo';

    public const USAGE_PROSPECT = 'prospect';

    public const USAGES = [
        self::USAGE_QA,
        self::USAGE_DOGFOODING,
        self::USAGE_DEMO,
        self::USAGE_PROSPECT,
    ];

    public const ORIGIN_NEW = 'new';

    public const ORIGIN_IMPORT = 'import';

    public const ORIGIN_DUPLICATE = 'duplicate';

    public const ORIGIN_CAPTURE = 'capture';

    public const ORIGIN_TEMPLATE = 'template';

    public const ORIGINS = [
        self::ORIGIN_NEW,
        self::ORIGIN_IMPORT,
        self::ORIGIN_DUPLICATE,
        self::ORIGIN_CAPTURE,
        self::ORIGIN_TEMPLATE,
    ];

    /**
     * Plafond du document, aligne sur `ManifestJsonParser::MAX_BYTES` (spec 9.2).
     * Reexpose ici pour qu'une validation de formulaire n'ait pas a deviner la
     * meme constante ailleurs.
     */
    public const MAX_JSON_BYTES = 2097152;

    protected $fillable = [
        'scenario_key',
        'name',
        'version',
        'usage',
        'origin',
        'state',
        'json_source',
        'digest',
        'validation_summary',
        'approved_digest',
        'approved_by',
        'approved_at',
        'parent_id',
        'captured_from_organization_id',
        'scenario_pack_load_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'validation_summary' => 'array',
            'approved_at' => 'datetime',
        ];
    }

    public function isDraft(): bool
    {
        return $this->state === self::STATE_DRAFT;
    }

    public function isValid(): bool
    {
        return $this->state === self::STATE_VALID;
    }

    /**
     * `LOADED`, derive — jamais lu dans une colonne (CDC 7.3 et 33.8).
     *
     * Les deux conditions sont necessaires. `state = valid` seul ne suffit pas :
     * une version approuvee mais jamais chargee est VALID, pas LOADED. Le lien
     * seul ne suffit pas non plus : il ne peut subsister sur un brouillon, et
     * une version qui repasse DRAFT ne doit plus etre annoncee chargee.
     *
     * La fiabilite vient de la FK `nullOnDelete` : un chargement est vivant si
     * et seulement si sa ligne `scenario_pack_loads` existe, donc la
     * disparition de la sandbox denoue le lien d'elle-meme.
     */
    public function isLoaded(): bool
    {
        return $this->isValid() && $this->scenario_pack_load_id !== null;
    }

    /**
     * L'approbation ne vaut que pour le CONTENU approuve.
     *
     * Un `approved_digest` qui ne correspond plus au `digest` courant signale
     * une modification posterieure a l'approbation : elle doit repasser DRAFT
     * (CDC 12.3). Cette methode dit seulement si l'approbation est encore
     * alignee ; elle n'autorise a elle seule aucun Load, l'etat et la
     * confirmation humaine restant des preconditions distinctes (spec 5.2).
     */
    public function approvalMatchesCurrentDigest(): bool
    {
        return $this->approved_digest !== null
            && $this->digest !== null
            && hash_equals($this->approved_digest, $this->digest);
    }

    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('state', self::STATE_DRAFT);
    }

    public function scopeValid(Builder $query): Builder
    {
        return $query->where('state', self::STATE_VALID);
    }

    /**
     * Les versions actuellement chargees, avec la MEME definition que
     * {@see self::isLoaded()} — une divergence entre le predicat SQL et le
     * predicat PHP produirait deux verites sur le meme etat.
     */
    public function scopeLoaded(Builder $query): Builder
    {
        return $query->where('state', self::STATE_VALID)->whereNotNull('scenario_pack_load_id');
    }

    public function scopeForScenario(Builder $query, string $scenarioKey): Builder
    {
        return $query->where('scenario_key', $scenarioKey);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function scenarioPackLoad(): BelongsTo
    {
        return $this->belongsTo(ScenarioPackLoad::class, 'scenario_pack_load_id');
    }

    public function capturedFromOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'captured_from_organization_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
