<?php

namespace App\Models;

use App\Models\Traits\HasOrganizationId;
use Illuminate\Database\Eloquent\Relations\Pivot;

class ServiceSkill extends Pivot
{
    use HasOrganizationId;
}
