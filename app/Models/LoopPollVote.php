<?php

namespace App\Models;

use App\Models\Traits\HasOrganizationId;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * La voix d'une personne sur un Sondage.
 *
 * Un objet et non une ligne par choix : c'est ce qui permet a la base — et pas
 * seulement au code — de garantir une voix par personne, que le Sondage soit a
 * choix unique ou multiple. Les choix pendent de cet objet.
 */
class LoopPollVote extends Model
{
    use HasOrganizationId, HasUuids;

    protected $fillable = ['organization_id', 'poll_id', 'user_id'];

    public function poll(): BelongsTo
    {
        return $this->belongsTo(LoopPoll::class, 'poll_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function voteOptions(): HasMany
    {
        return $this->hasMany(LoopPollVoteOption::class, 'vote_id');
    }

    public function options(): BelongsToMany
    {
        return $this->belongsToMany(
            LoopPollOption::class,
            'loop_poll_vote_options',
            'vote_id',
            'option_id',
        );
    }
}
