<?php

namespace App\Models;

use Database\Factories\LoopMemberFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class LoopMember extends Model
{
    /** @use HasFactory<LoopMemberFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'loop_id',
        'user_id',
        'role',
        'status',
        'joined_at',
        'organization_id',
    ];

    protected function casts(): array
    {
        return [
            'role' => 'string',
            'status' => 'string',
            'joined_at' => 'datetime',
        ];
    }

    public function loop(): BelongsTo
    {
        return $this->belongsTo(Loop::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * TASK-1802 (demande Cyril, 10/11) — une adhesion REELLEMENT courante.
     *
     * `loop_members.organization_id` est fige au moment de l'adhesion. Si la
     * personne change ensuite d'Organization — mesure en recette : un membre
     * reel du jumeau, `loop_members` toujours `active`, `users.
     * organization_id` pointant desormais vers une AUTRE Organization — cette
     * ligne devient un VESTIGE : elle reste visible dans les listes de
     * membres et atteignable par les notifications, alors que la personne
     * n'appartient plus a l'Organization de la Boucle.
     *
     * « Il faut afficher et envoyer des emails UNIQUEMENT aux membres de la
     * Boucle qui sont dans l'Organization de la Boucle » (demande explicite).
     * Ce scope applique la regle une seule fois, pour quiconque liste des
     * membres a MONTRER ou a NOTIFIER — jamais pour une recherche
     * d'appartenance dont la personne cherchee cible elle-meme sa propre
     * ligne (une permission individuelle que l'intervalle de validite
     * concerne autrement).
     */
    public function scopeCurrentlyInLoopOrganization(Builder $query): Builder
    {
        return $query->whereExists(function ($sub) {
            $sub->select(DB::raw(1))
                ->from('users')
                ->whereColumn('users.id', 'loop_members.user_id')
                ->whereColumn('users.organization_id', 'loop_members.organization_id');
        });
    }
}
