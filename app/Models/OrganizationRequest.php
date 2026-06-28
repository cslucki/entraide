<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationRequest extends Model
{
    use HasUuids;

    protected $fillable = [
        'boucle_name',
        'contact_name',
        'contact_email',
        'contact_phone',
        'website_url',
        'description',
        'context',
        'status',
        'user_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
