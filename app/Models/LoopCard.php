<?php

namespace App\Models;

use App\Models\Traits\HasOrganizationId;
use Database\Factories\LoopCardFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One card enabled on one Loop.
 *
 * `card_key` points at config/loop_cards.php, which stays the catalogue; this
 * table only records composition. `added_by_preset` remembers which type preset
 * created the row, so the admin screens can tell a type's baseline apart from a
 * Loop's own customisation.
 */
class LoopCard extends Model
{
    /** @use HasFactory<LoopCardFactory> */
    use HasFactory, HasOrganizationId, HasUuids;

    protected $fillable = [
        'organization_id',
        'loop_id',
        'card_key',
        'enabled',
        'added_by_preset',
        // TASK-1124 : NULL = pas mis en avant ; 0..2 = outil principal, dans
        // cet ordre. Ne dit rien de l'activation, qui reste `enabled` seul.
        'primary_rank',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'primary_rank' => 'integer',
    ];

    public function loop(): BelongsTo
    {
        return $this->belongsTo(Loop::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Catalogue entry backing this row, or null if it vanished from config.
     *
     * Array access, not config() dot-notation: card keys contain a dot
     * ("core.manifesto"), which dot-notation would split into a nested lookup
     * that never resolves.
     */
    public function definition(): ?array
    {
        return config('loop_cards.cards', [])[$this->card_key] ?? null;
    }

    public function label(): string
    {
        $definition = $this->definition();

        return $definition ? __($definition['label_key']) : $this->card_key;
    }
}
