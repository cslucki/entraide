<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BugReport extends Model
{
    use HasUuids;

    protected $fillable = [
        'organization_id',
        'reporter_id',
        'reason',
        'details',
        'page_url',
        'user_agent',
        'status',
        'resolution_notes',
        'fixed_at',
    ];

    protected function casts(): array
    {
        return [
            'fixed_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }
}
