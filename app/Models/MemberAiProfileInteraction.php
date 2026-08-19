<?php

namespace App\Models;

use App\Support\Ai\AiCorrelation;
use App\Support\Ai\AiRefusedException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberAiProfileInteraction extends Model
{
    use HasFactory;
    use HasUuids;

    public const STATUS_SUCCESS = 'success';

    /**
     * TASK-1251 / TASK-1252 : l'appel a ete REFUSE avant de partir (garde
     * economique, ou visiteur anonyme — `metadata.economic_refusal.code`).
     * Pas de reponse, cout NULL/NULL (« non evalue », jamais 0). C'est l'etat
     * de l'echange, visible par le proprietaire (badge ambre) — PAS une ligne
     * economique : aucun lecteur economique ne lit cette table.
     */
    public const STATUS_REFUSED = 'refused';

    public const VISITOR_TYPE_USER = 'user';

    public const VISITOR_TYPE_GUEST = 'guest';

    protected $fillable = [
        'organization_id',
        'correlation_id',
        'process',
        'member_ai_profile_id',
        'profile_owner_user_id',
        'visitor_user_id',
        'visitor_type',
        'provider',
        'model',
        'status',
        'question',
        'response',
        'matched_fields',
        'metadata',
        'latency_ms',
        'input_tokens',
        'output_tokens',
        'cost_usd',
        'cost_unknown',
    ];

    protected function casts(): array
    {
        return [
            'matched_fields' => 'array',
            'metadata' => 'array',
            'latency_ms' => 'integer',
            // TASK-1132 : nullable des l'origine ici, pour que « aucun usage
            // rapporte » reste distinct de « 0 token ».
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cost_usd' => 'decimal:8',
            'cost_unknown' => 'boolean',
        ];
    }

    /**
     * Ligne « refus avant depart » — UNE seule forme pour tous les chemins de
     * l'agent de profil (job `GenerateAiAgentResponse` T1251, chat visiteur
     * `AiAgentChat` T1252) : rien n'est parti, rien n'est evalue.
     *
     * @param  array{provider: string, model: string}|null  $attempted  provider/modele qui AURAIENT ete appeles (NULL si aucun n'a ete choisi — visiteur anonyme)
     */
    public static function recordRefusal(
        MemberAiProfile $profile,
        string $process,
        string $feature,
        AiRefusedException $refused,
        string $question,
        ?User $visitor,
        ?array $attempted,
    ): self {
        return self::create([
            'organization_id' => $profile->organization_id,
            'correlation_id' => AiCorrelation::id(),
            'process' => $process,
            'member_ai_profile_id' => $profile->id,
            'profile_owner_user_id' => $profile->user_id,
            'visitor_user_id' => $visitor?->id,
            'visitor_type' => $visitor !== null ? self::VISITOR_TYPE_USER : self::VISITOR_TYPE_GUEST,
            'provider' => $attempted['provider'] ?? null,
            'model' => $attempted['model'] ?? null,
            'status' => self::STATUS_REFUSED,
            'question' => $question,
            'response' => null,
            'matched_fields' => [],
            'metadata' => [
                'economic_refusal' => [
                    'code' => $refused->refusalCode,
                    'feature' => $feature,
                ],
            ],
            'latency_ms' => null,
            'input_tokens' => null,
            'output_tokens' => null,
            // Aucun appel parti : rien a evaluer — NULL / NULL, jamais 0.
            'cost_usd' => null,
            'cost_unknown' => null,
        ]);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(MemberAiProfile::class, 'member_ai_profile_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'profile_owner_user_id');
    }

    public function visitor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'visitor_user_id');
    }

    public function scopeForOrganization($query, $organization): void
    {
        $query->where('organization_id', $organization instanceof Model ? $organization->id : $organization);
    }
}
