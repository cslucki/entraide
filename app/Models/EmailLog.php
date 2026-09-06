<?php

namespace App\Models;

use App\Models\Traits\HasOrganizationId;
use Database\Factories\EmailLogFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

class EmailLog extends Model
{
    /** @use HasFactory<EmailLogFactory> */
    use HasFactory, HasOrganizationId, HasUuids;

    /**
     * TASK-1377 — le vocabulaire de `status`, applique sur les DEUX moteurs.
     *
     * `sent` : le transport a accepte le message.
     * `failed` : echec CONNU — rien n'est parti.
     * `ambiguous` : resultat INCONNU — le transport a leve APRES qu'on lui a
     *   remis le message. Ni un succes, ni un echec : une ignorance.
     *
     * Fondre `ambiguous` dans `failed` rendrait un rejeu automatique dangereux,
     * puisqu'on renverrait un message peut-etre deja delivre. La distinction est
     * donc ce qui garantit l'absence de rejeu.
     */
    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_AMBIGUOUS = 'ambiguous';

    /** @var list<string> */
    public const STATUSES = [self::STATUS_SENT, self::STATUS_FAILED, self::STATUS_AMBIGUOUS];

    /**
     * Le vocabulaire est verifie ICI, donc identiquement sur SQLite et
     * PostgreSQL.
     *
     * La migration historique declarait un `enum`, ce qui produit une contrainte
     * REELLE sur PostgreSQL et RIEN sur SQLite. Les deux moteurs ne faisaient
     * donc pas respecter la meme regle, et une suite qui ne tourne que sur
     * SQLite ne pouvait pas voir la divergence. Ce hook la ferme.
     */
    protected static function booted(): void
    {
        $verifier = function (self $journal): void {
            if (! in_array((string) $journal->status, self::STATUSES, true)) {
                throw new InvalidArgumentException(
                    "Unknown email log status [{$journal->status}]. Allowed: ".implode(', ', self::STATUSES).'.'
                );
            }
        };

        static::creating($verifier);
        static::updating($verifier);
    }

    protected $fillable = [
        'template_id',
        'user_id',
        'to_email',
        'subject',
        'status',
        'error_message',
        'data',
        'organization_id',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    protected $casts = [
        'data' => 'array',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class, 'template_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
