<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * TASK-1739 — un abonnement Web Push, c'est-a-dire UN APPAREIL.
 *
 * ## Pourquoi `endpoint_hash` est calcule ICI et nulle part ailleurs
 *
 * L'empreinte porte la contrainte d'unicite qui garantit l'invariant central
 * du CDC 5.2 : « un endpoint n'est attribue qu'a UN SEUL `user_id` a un
 * instant donne ». Si un appelant pouvait l'ecrire lui-meme, il pourrait la
 * desynchroniser de l'`endpoint` — deux lignes pour le meme appareil, et au
 * chantier d'envoi un Push du compte frere precedent sur l'ecran verrouille.
 *
 * Elle est donc DERIVEE a l'ecriture, hors `$fillable`, et recalculee a chaque
 * fois que `endpoint` bouge. Le seul moyen de la changer est de changer
 * l'endpoint.
 *
 * ## `user_id` hors `$fillable`
 *
 * Meme doctrine que `MemberNotificationPreference` : le proprietaire vient de
 * la session, jamais de la requete. Un `create($request->validated())` un peu
 * rapide laisserait quelqu'un abonner l'appareil de quelqu'un d'autre.
 *
 * ## Ce que ce modele ne fait PAS
 *
 * Il n'envoie rien, ne chiffre rien, ne connait aucun canal. `web_push`
 * n'entrera au `NotificationCatalogue` qu'au chantier d'envoi, apres
 * correction du defaut de `NotificationDeliveryPlanner::plan()`.
 *
 * @property string $id
 * @property string $user_id
 * @property string $endpoint
 * @property string $endpoint_hash
 * @property string $p256dh
 * @property string $auth
 * @property string|null $device_label
 * @property string|null $created_env
 * @property \Illuminate\Support\Carbon|null $last_seen_at
 */
class PushSubscription extends Model
{
    use HasFactory, HasUuids;

    /**
     * `user_id` et `endpoint_hash` sont ABSENTS a dessein : le premier vient
     * de la session, le second est derive de `endpoint`.
     */
    protected $fillable = [
        'endpoint',
        'p256dh',
        'auth',
        'device_label',
        'last_seen_at',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
    ];

    /**
     * L'empreinte qui porte l'unicite.
     *
     * Publique parce que la REVENDICATION d'un endpoint doit pouvoir la
     * calculer avant d'ecrire, pour viser la bonne ligne en une seule
     * operation atomique.
     */
    public static function empreinteDe(string $endpoint): string
    {
        return hash('sha256', trim($endpoint));
    }

    protected static function booted(): void
    {
        // `saving` et non `creating` : l'empreinte doit suivre l'endpoint a la
        // creation ET a la mise a jour. Sans cela, un changement d'endpoint
        // laisserait l'empreinte de l'ancien et la contrainte d'unicite
        // defendrait le mauvais appareil.
        static::saving(function (self $abonnement): void {
            $endpoint = trim((string) $abonnement->endpoint);

            if ($endpoint === '') {
                throw new RuntimeException('A push subscription cannot have an empty endpoint.');
            }

            $abonnement->endpoint = $endpoint;
            $abonnement->endpoint_hash = self::empreinteDe($endpoint);
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
