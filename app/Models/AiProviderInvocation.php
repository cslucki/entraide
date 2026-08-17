<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ledger canonique des invocations provider IA (TASK-1220).
 *
 * Une ligne = UNE tentative/appel provider economiquement reel. C'est le
 * niveau que les tables historiques n'ont jamais eu : `correlation_id` y
 * identifie une OPERATION metier, pas un appel (TASK-1219), donc sommer
 * sur-compte et dedupliquer sous-compte. Ici, deux appels d'une meme
 * operation = deux lignes, chacune avec son propre id.
 *
 * Registre append-only : pas de `updated_at`, aucune mise a jour apres
 * ecriture — comme `AiInteraction`. Ecrit UNIQUEMENT par
 * `AiProviderInvocationLedger` ; aucun credential, prompt ni contenu de
 * reponse n'y entre.
 *
 * Ce ledger PREPARE l'autorite economique future. Il ne la porte pas encore :
 * `AiEconomicGuard` continue de lire `ai_interactions` (compatibilite
 * TASK-1219, migration d'autorite = TASK future avec preuve de couverture).
 */
class AiProviderInvocation extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    public const OPERATION_GENERATION = 'generation';

    public const OPERATION_EMBEDDING = 'embedding';

    public const EMBEDDING_OPERATION_INGESTION = 'ingestion';

    public const EMBEDDING_OPERATION_QUERY = 'query';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const COST_KNOWN = 'known';

    public const COST_UNKNOWN = 'unknown';

    /**
     * Sources de credential PROUVEES par la primitive qui pose la cle
     * (`ProviderResolver::registerInstance`), jamais inferees. `none` couvre
     * les drivers keyless (ollama local) : il est demontrable qu'aucun
     * credential n'existe — `unknown` serait un mensonge inverse. `platform`
     * et `user` sont reserves : aucune primitive ne les prouve encore.
     */
    public const CREDENTIAL_ORGANIZATION = 'organization';

    public const CREDENTIAL_PLATFORM = 'platform';

    public const CREDENTIAL_USER = 'user';

    public const CREDENTIAL_NONE = 'none';

    public const CREDENTIAL_UNKNOWN = 'unknown';

    protected $fillable = [
        'organization_id',
        'user_id',
        'capability',
        'process',
        'operation',
        'embedding_operation',
        'provider',
        'model',
        'credential_source',
        'input_tokens',
        'output_tokens',
        'total_tokens',
        'embedding_count',
        'embedding_dimensions',
        'provider_cost',
        'currency',
        'cost_status',
        'cost_source',
        'status',
        'failure_reason',
        'correlation_id',
        'sdk_invocation_id',
        'provider_invocation_id',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'total_tokens' => 'integer',
            'embedding_count' => 'integer',
            'embedding_dimensions' => 'integer',
            'provider_cost' => 'decimal:6',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
