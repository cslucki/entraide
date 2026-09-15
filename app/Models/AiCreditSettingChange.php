<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trace d'un changement de reglage IA : qui, quand, quoi (avant / apres).
 * Plateforme (`organization_id` NULL) ou Organization. Jamais modifiee apres
 * ecriture.
 *
 * TASK-1229 l'a creee pour le credit IA par utilisateur, et le nom de la table
 * en garde la marque. TASK-1563 lui a ajoute une SECONDE nature de reglage —
 * l'autorisation de rerank — sans la renommer : `UserDataLifecycleRegistry`
 * porte une politique RGPD nommee sur cette table.
 *
 * Elle a donc DEUX ecrivains, `AiUserCreditSettings` et `AiRerankSettings`, et
 * `setting_kind` dit lequel a parle. TOUT lecteur doit filtrer dessus :
 * l'oublier fait afficher un changement de rerank comme un changement de
 * credit — ce qui s'est produit sur l'historique de /admin/ai-monetization
 * avant que la revue de TASK-1563 ne l'attrape.
 */
class AiCreditSettingChange extends Model
{
    use HasUuids;

    public const SCOPE_PLATFORM = 'platform';

    public const SCOPE_ORGANIZATION = 'organization';

    /**
     * TASK-1563 — la NATURE du reglage dont cette ligne trace le changement.
     *
     * Cette table ne portait qu'une seule nature, et ses lecteurs ne
     * filtraient donc que par perimetre. Depuis qu'il en existe deux, ce
     * filtre ne suffit plus : sans discriminant, un changement de rerank
     * remonterait sur l'ecran de monetisation presente comme un changement de
     * credit.
     */
    public const KIND_CREDIT = 'credit';

    public const KIND_RERANK = 'rerank';

    public const UPDATED_AT = null;

    protected $fillable = [
        'scope',
        'setting_kind',
        'organization_id',
        'changes',
        'changed_by',
    ];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
