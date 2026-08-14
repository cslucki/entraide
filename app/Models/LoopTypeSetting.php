<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One deliberate departure from a type's defaults in config/loop_types.php.
 *
 * A row exists only where a super-admin changed something — the card preset,
 * the availability, or both. Its absence means "use the configured default",
 * and returning to that default deletes the row rather than writing the value
 * back. Nothing here is ever materialised.
 *
 * Read and written exclusively through LoopTypeSettingsService.
 */
class LoopTypeSetting extends Model
{
    use HasUuids;

    // `organization_id` nullable : `null` signifie **Plateforme**, comme
    // l'absence d'une cle dans `organizations.loop_permissions` signifie
    // « herite ».
    protected $fillable = ['organization_id', 'loop_type', 'label', 'description', 'cards', 'available'];

    protected $casts = [
        'cards' => 'array',
        'available' => 'boolean',
    ];
}
