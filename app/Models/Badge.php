<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Badge extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = ['key', 'name', 'description', 'icon', 'color'];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'badge_user', 'badge_id', 'user_id')
            ->withPivot('earned_at');
    }
}
