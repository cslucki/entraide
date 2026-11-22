<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * TASK-1879 — une categorie de Veille, propre a UNE Boucle.
 *
 * Pas de trait `HasOrganizationId`, deliberement : il remplirait
 * `organization_id` depuis `current_organization`, alors que la seule valeur
 * juste est celle de la BOUCLE. LoopWatchService l'ecrit explicitement.
 *
 * Ecrite exclusivement par LoopWatchService.
 */
class LoopWatchCategory extends Model
{
    use HasUuids;

    public const NAME_MAX = 80;

    protected $fillable = [
        'organization_id',
        'loop_id',
        'name',
        'position',
        'archived_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    public function loop(): BelongsTo
    {
        return $this->belongsTo(Loop::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(LoopWatchItem::class, 'category_id');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * TASK-1879 (design) — la teinte STABLE d'une categorie, 0..5, derivee de
     * son identifiant : la meme pastille dans la Card, ChatLoop et le Flux,
     * sans colonne ni requete. La palette vit dans
     * `components/veille/categorie.blade.php`.
     */
    public function teinte(): int
    {
        return crc32((string) $this->getKey()) % 6;
    }

    /** @param  Builder<LoopWatchCategory>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    /** @param  Builder<LoopWatchCategory>  $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('position')->orderBy('name')->orderBy('id');
    }
}
