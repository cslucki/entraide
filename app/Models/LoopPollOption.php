<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Une reponse possible.
 *
 * Pas d'`organization_id` : une option n'est atteignable qu'a travers son
 * Sondage, dont l'Organization fait foi. En porter une copie inviterait a la
 * desynchroniser.
 */
class LoopPollOption extends Model
{
    use HasUuids;

    protected $fillable = ['poll_id', 'label', 'position'];

    public function poll(): BelongsTo
    {
        return $this->belongsTo(LoopPoll::class, 'poll_id');
    }

    public function voteOptions(): HasMany
    {
        return $this->hasMany(LoopPollVoteOption::class, 'option_id');
    }
}
