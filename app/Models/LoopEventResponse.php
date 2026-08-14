<?php

namespace App\Models;

use App\Models\Traits\HasOrganizationId;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * « Je viens », « peut-etre », « je ne viens pas ».
 *
 * Un objet unique par personne et par Evenement — l'unicite est en base. Une
 * reponse donnee reste : quitter la Boucle ne l'efface pas, elle decrit ce qui
 * s'est passe.
 */
class LoopEventResponse extends Model
{
    use HasOrganizationId, HasUuids;

    public const GOING = 'going';

    public const MAYBE = 'maybe';

    public const NOT_GOING = 'not_going';

    public const RESPONSES = [self::GOING, self::MAYBE, self::NOT_GOING];

    protected $fillable = ['organization_id', 'event_id', 'user_id', 'response'];

    public function event(): BelongsTo
    {
        return $this->belongsTo(LoopEvent::class, 'event_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
