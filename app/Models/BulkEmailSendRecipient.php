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
 */
class BulkEmailSendRecipient extends Model
{
    /** @use HasFactory<BulkEmailSendRecipientFactory> */
    use HasFactory, HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    /** @var list<string> */
    public const STATUSES = [self::STATUS_PENDING, self::STATUS_SENT, self::STATUS_FAILED];

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
