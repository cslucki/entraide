<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TASK-1693 bug Z — le registre des adresses publiques, courantes ET passees.
 *
 * Ce n'est pas un simple historique : c'est ce qui EMPECHE le recyclage. Un
 * slug abandonne reste inscrit au nom de la meme personne, donc indisponible
 * pour autrui, et sa resolution redirige vers l'adresse courante. Sans cette
 * table, changer de slug liberait le precedent — et quiconque le reprenait
 * heritait de tous les liens deja partages.
 *
 * Volontairement SANS `BelongsToOrganizationScope` : la resolution d'une URL se
 * fait avant que le tenant courant ne soit toujours etabli, et le service borne
 * lui-meme chaque requete par `organization_id`.
 */
class UserSlug extends Model
{
    use HasUuids;

    protected $table = 'user_slugs';

    protected $fillable = ['user_id', 'organization_id', 'slug', 'is_current'];

    protected function casts(): array
    {
        return ['is_current' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
