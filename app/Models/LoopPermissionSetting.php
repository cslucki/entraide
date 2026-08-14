<?php

namespace App\Models;

use Database\Factories\LoopPermissionSettingFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One explicit global override of a Loop permission, set by the super-admin.
 *
 * A row exists only where a value was deliberately changed. Its absence means
 * "use the registry default", and deleting it is how you return to that default
 * — the matrix is never materialised, and there is no per-Loop configuration.
 */
class LoopPermissionSetting extends Model
{
    /** @use HasFactory<LoopPermissionSettingFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['loop_type', 'loop_role', 'permission', 'allowed'];

    protected $casts = ['allowed' => 'boolean'];
}
