<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * TASK-1775 — le reglage de notification COLLECTIVE d'un membre, pour UNE
 * Boucle precise.
 *
 * ## Absence de ligne = Boucle fraichement rejointe
 *
 * Aucune ligne n'est ecrite au moment de l'adhesion : le resolver rend les
 * defauts du CATALOGUE en l'absence de toute ligne. Depuis l'arbitrage du
 * 07/11, ces defauts sont `collective_enabled = true`, **email ACTIF** et
 * **push ACTIF** (opt-OUT) — les colonnes de cette table ne font autorite que
 * lorsqu'une ligne existe, c'est-a-dire apres un reglage explicite.
 *
 * ## `collective_enabled` eteint coupe TOUT, quel que soit le canal
 *
 * C'est l'interrupteur GENERAL de la section B1 du CDC : une Boucle que CE
 * MEMBRE a coupee ne lui produit plus aucune notification de ce type, in-app
 * comprise — `email_enabled` et `web_push_enabled` ne REDUISENT le bruit que
 * si la collecte elle-meme reste active.
 *
 * ## ⚠️ « General » ne veut pas dire « collectif » — TASK-1778
 *
 * Chaque ligne appartient a UN membre : l'unicite est `(loop_id, user_id)` et
 * toutes les lectures passent par `forOwner($user->id)`. **Personne ne peut
 * couper une Boucle pour les autres**, ni son createur, ni un administrateur.
 * Un reglage ne reduit donc jamais le fan-out de la Boucle : il ne retire que
 * son propre destinataire.
 *
 * La precision est ecrite ici parce que l'ambiguite a deja produit une erreur
 * de jugement : « maitre de Boucle », formule employee dans les commentaires
 * de T1775, a ete lue comme « le maitre DE LA Boucle », donc comme un levier
 * collectif qui n'existe pas.
 *
 * ## L'identite est figee, meme doctrine que `MemberNotificationPreference`
 *
 * `(organization_id, loop_id, user_id)` forment un fait : QUI, pour QUELLE
 * Boucle, dans QUEL tenant. Seuls les trois leviers peuvent changer apres
 * coup. Et `organization_id` n'est pas pose en confiance : il doit
 * correspondre a celui de la Boucle elle-meme, verifie a l'ecriture — sinon
 * une ligne pourrait affirmer qu'une Boucle appartient a un autre tenant que
 * le sien.
 *
 * ## Ce que cette garde ne couvre PAS
 *
 * L'appartenance ACTIVE a la Boucle n'est pas verifiee ici : elle depend d'un
 * etat qui change sans toucher cette table (quitter une Boucle ne supprime
 * pas la ligne). C'est au controleur de ne jamais presenter, ni accepter, un
 * reglage pour une Boucle dont la personne n'est plus membre actif —
 * exactement le meme partage de responsabilite qu'entre `NotificationEmitter`
 * (garde structurelle) et `LoopMessageService` (garde d'eligibilite).
 */
class LoopNotificationPreference extends Model
{
    use HasUuids;

    /** Le nom par defaut derive du nom de classe serait `loop_notification_preferences` — la table porte le prefixe `member_`, comme ses voisines. */
    protected $table = 'member_loop_notification_preferences';

    /**
     * `organization_id`, `loop_id` et `user_id` sont ABSENTS a dessein — voir
     * le docblock de la classe. Ils sont poses par le controleur depuis
     * l'acteur authentifie et la Boucle resolue, jamais recus de la requete.
     */
    protected $fillable = [
        'collective_enabled',
        'email_enabled',
        'web_push_enabled',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $reglage): void {
            self::assertOrganizationMatchesLoop($reglage);
        });

        static::updating(function (self $reglage): void {
            $figees = array_intersect(
                array_keys($reglage->getDirty()),
                ['organization_id', 'loop_id', 'user_id']
            );

            if ($figees !== []) {
                throw new RuntimeException(
                    'A loop notification preference identity is frozen: ['.implode(', ', $figees).'] cannot change.'
                );
            }
        });
    }

    protected function casts(): array
    {
        return [
            'collective_enabled' => 'boolean',
            'email_enabled' => 'boolean',
            'web_push_enabled' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function loop(): BelongsTo
    {
        return $this->belongsTo(Loop::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** La seule porte de lecture : les reglages d'UNE personne. */
    public function scopeForOwner(Builder $query, string $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    private static function assertOrganizationMatchesLoop(self $reglage): void
    {
        $organizationId = Loop::query()->whereKey($reglage->loop_id)->value('organization_id');

        if ($organizationId === null || (string) $organizationId !== (string) $reglage->organization_id) {
            throw new RuntimeException('A loop notification preference cannot name an organization foreign to its loop.');
        }
    }
}
