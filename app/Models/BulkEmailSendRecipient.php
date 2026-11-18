<?php

namespace App\Models;

use Database\Factories\BulkEmailSendRecipientFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * TASK-1803 — une ligne = un destinataire FIGE d'un `BulkEmailSend`, au
 * moment de sa creation (voir le docblock de la migration). `user_id` reste
 * nullable : si le membre est supprime pendant l'envoi, la ligne survit
 * comme trace, simplement sans compte a qui ecrire.
 *
 * ## TASK-1861 — `queued` et `ambiguous`, meme idiome que `NotificationDeliveryStatus`
 *
 * `queued` est l'etat de RESERVATION : une seule requete UPDATE fait la
 * transition `pending -> queued`, avec l'etat attendu dans son `WHERE`
 * (voir `BulkEmailSendService::claimRecipient()`) — exactement l'idiome de
 * `NotificationEmailDeliverer::prendre()` (TASK-1377), applique ici a un
 * LOT de candidats plutot qu'a une seule ligne. Aucune fenetre de double
 * reservation, sur les deux moteurs.
 *
 * `ambiguous` protege le destinataire, pas le systeme : si le transport leve
 * APRES que `Mail::html()/raw()` a ete appele, personne ne sait s'il est
 * parti. Marquer `failed` inviterait a rejouer, donc a envoyer deux fois ;
 * marquer `sent` affirmerait une livraison peut-etre inexistante. `ambiguous`
 * dit l'ignorance, et n'est JAMAIS repris automatiquement — seul `pending`
 * est jamais re-selectionne par `claimRecipient()`.
 *
 * ## TASK-1861 (revue MASTER, correctif avant fusion) — `processing`
 *
 * `queued` protege contre deux DECLENCHEMENTS HTTP concurrents (deux
 * reservations du MEME `pending`) ; `processing` protege contre deux
 * EXECUTIONS DU JOB concurrentes pour le MEME destinataire DEJA reserve
 * (redelivraison "at-least-once" d'une Managed Queue, deux workers qui
 * ramassent le meme message). Meme idiome, une couche plus bas : une
 * seule requete UPDATE fait la transition `queued -> processing`, avec
 * l'etat attendu dans son `WHERE` (voir
 * `BulkEmailSendService::claimForProcessing()`). Si l'ecriture du
 * resultat final echoue APRES une tentative de transport, la ligne
 * reste `processing` — observable, jamais silencieusement perdue, et
 * jamais rejouee automatiquement : un humain la reprend via
 * reconciliation manuelle (croiser `email_logs` si un journal existe).
 */
class BulkEmailSendRecipient extends Model
{
    /** @use HasFactory<BulkEmailSendRecipientFactory> */
    use HasFactory, HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_AMBIGUOUS = 'ambiguous';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_QUEUED,
        self::STATUS_PROCESSING,
        self::STATUS_SENT,
        self::STATUS_FAILED,
        self::STATUS_AMBIGUOUS,
    ];

    protected static function booted(): void
    {
        $verifier = function (self $destinataire): void {
            if (! in_array($destinataire->status, self::STATUSES, true)) {
                throw new InvalidArgumentException("Unknown status [{$destinataire->status}]. Allowed: ".implode(', ', self::STATUSES).'.');
            }
        };

        static::creating($verifier);
        static::updating($verifier);
    }

    protected $fillable = [
        'bulk_email_send_id',
        'user_id',
        'status',
        'email_log_id',
    ];

    public function send(): BelongsTo
    {
        return $this->belongsTo(BulkEmailSend::class, 'bulk_email_send_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function emailLog(): BelongsTo
    {
        return $this->belongsTo(EmailLog::class);
    }
}
