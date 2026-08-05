<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un choix retenu dans un vote. */
class LoopPollVoteOption extends Model
{
    use HasUuids;

    protected $fillable = ['vote_id', 'option_id'];

    public function vote(): BelongsTo
    {
        return $this->belongsTo(LoopPollVote::class, 'vote_id');
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(LoopPollOption::class, 'option_id');
    }
}
