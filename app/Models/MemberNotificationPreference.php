<?php

namespace App\Models;

use App\Support\Notifications\NotificationPreferenceInvariants;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * TASK-1375 — l'ecart d'un membre par rapport a un defaut de notification.
 *
 * ## `user_id` n'est PAS affectable en masse
 *
 * C'est la garde la plus importante de ce modele, et elle est structurelle.
 * L'ecran de reglages recoit des donnees du client ; si `user_id` etait
 * fillable, un `create($request->validated())` un peu rapide laisserait
 * n'importe qui ecrire les preferences de n'importe qui.
 *
 * Le proprietaire n'est donc jamais fourni par la requete : il est pose par le
 * controleur depuis l'utilisateur authentifie, via `forOwner()`.
 *
 * ## Les regles vivent dans `NotificationPreferenceInvariants`
 *
 * Appliquees sur `creating` et sur `updating`. Une convention d'appeler le bon
 * service se contourne par distraction ; un hook de modele, non. Meme doctrine
 * qu'en T1372.
 *
 * ## Une ligne est un ECART, jamais un etat
 *
 * L'absence de ligne signifie « je m'en remets au defaut du catalogue », et
 * c'est le cas de la quasi-totalite des membres. Rien n'est provisionne a
 * l'inscription.
 */
class MemberNotificationPreference extends Model
{
    use HasUuids;

    /**
     * `user_id` est ABSENT a dessein — voir le docblock de la classe.
     */
    protected $fillable = [
        'notification_key',
        'channel',
        'enabled',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $preference): void {
            NotificationPreferenceInvariants::assert($preference->getAttributes());
        });

        // Un reglage change d'ETAT, jamais d'identite. Laisser muter
        // `user_id`, la cle ou le canal reviendrait a offrir par `update()` ce
        // que `creating` refuse — et ces trois colonnes forment justement la
        // contrainte d'unicite.
        static::updating(function (self $preference): void {
            $figees = array_intersect(array_keys($preference->getDirty()), ['user_id', 'notification_key', 'channel']);

            if ($figees !== []) {
                throw new RuntimeException(
                    'A notification preference identity is frozen: ['.implode(', ', $figees).'] cannot change.'
                );
            }

            NotificationPreferenceInvariants::assert($preference->getAttributes());
        });
    }

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** La seule porte de lecture : les reglages d'UNE personne. */
    public function scopeForOwner(Builder $query, string $userId): Builder
    {
        return $query->where('user_id', $userId);
    }
}
