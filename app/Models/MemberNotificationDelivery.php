<?php

namespace App\Models;

use App\Support\Notifications\NotificationCatalogue;
use App\Support\Notifications\NotificationDeliveryStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;
use RuntimeException;

/**
 * TASK-1377 — l'etat courant d'une livraison.
 *
 * ## L'identite est FIGEE, et c'est structurel
 *
 * `notification_id` et `channel` forment la contrainte d'unicite. Les laisser
 * muter offrirait par `update()` ce que la contrainte refuse a l'insertion : il
 * suffirait de deplacer une livraison `sent` vers une autre notification pour
 * contourner l'unicite. Meme doctrine qu'en T1375 sur les preferences.
 *
 * ## `status` n'est PAS affectable en masse
 *
 * C'est la garde la plus importante de ce modele. Un `update($request->…)` un
 * peu rapide, ou un `fill()` sur des donnees venues d'ailleurs, pourrait sinon
 * faire passer une livraison a `sent` sans qu'aucun email ne parte. L'etat ne se
 * pose que par les methodes de transition ci-dessous.
 *
 * ## Les transitions sont appliquees par le modele, pas par convention
 *
 * `updating` refuse toute transition non permise. Une regle qui vit dans un
 * service se contourne en appelant le modele directement ; un hook, non. C'est
 * ce qui rend `sent` REELLEMENT irreversible.
 */
class MemberNotificationDelivery extends Model
{
    use HasUuids;

    /**
     * `status`, `attempts`, `claimed_at`, `sent_at` et `diagnostic` sont ABSENTS
     * a dessein — voir le docblock de la classe.
     */
    protected $fillable = [
        'notification_id',
        'channel',
    ];

    /** Les colonnes qui ne peuvent JAMAIS changer apres creation. */
    private const IDENTITE = ['notification_id', 'channel'];

    protected static function booted(): void
    {
        static::creating(function (self $livraison): void {
            $livraison->status ??= NotificationDeliveryStatus::PENDING;

            self::garantirCanal((string) $livraison->channel);

            if (! NotificationDeliveryStatus::existe((string) $livraison->status)) {
                throw new InvalidArgumentException(
                    "Unknown notification delivery status [{$livraison->status}]."
                );
            }
        });

        static::updating(function (self $livraison): void {
            $figees = array_intersect(array_keys($livraison->getDirty()), self::IDENTITE);

            if ($figees !== []) {
                throw new RuntimeException(
                    'A notification delivery identity is frozen: ['
                    .implode(', ', $figees).'] cannot change.'
                );
            }

            if (! $livraison->isDirty('status')) {
                return;
            }

            $depuis = (string) $livraison->getOriginal('status');
            $vers = (string) $livraison->status;

            if (! NotificationDeliveryStatus::transitionPermise($depuis, $vers)) {
                throw new RuntimeException(
                    "Forbidden notification delivery transition [{$depuis}] -> [{$vers}]. "
                    .'Terminal states are final: a delivery is never replayed automatically.'
                );
            }
        });
    }

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'claimed_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function notification(): BelongsTo
    {
        return $this->belongsTo(MemberNotification::class, 'notification_id');
    }

    /**
     * Le canal existe-t-il ?
     *
     * On refuse ce que le catalogue ne connait pas comme canal — sinon une
     * chaine libre creerait des livraisons qu'aucun worker ne ramassera jamais,
     * et qui resteraient `pending` sans que personne ne sache pourquoi.
     */
    private static function garantirCanal(string $channel): void
    {
        $connus = [NotificationCatalogue::CHANNEL_IN_APP, NotificationCatalogue::CHANNEL_EMAIL];

        if (! in_array($channel, $connus, true)) {
            throw new InvalidArgumentException("Unknown notification delivery channel [{$channel}].");
        }
    }

    public function scopeForChannel(Builder $query, string $channel): Builder
    {
        return $query->where('channel', $channel);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', NotificationDeliveryStatus::PENDING);
    }
}
