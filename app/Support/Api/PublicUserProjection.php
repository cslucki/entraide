<?php

namespace App\Support\Api;

use App\Models\User;

/**
 * TASK-1491 — l'UNIQUE forme sous laquelle une personne est publiee par l'API
 * publique.
 *
 * ## Ce qui a ete mesure, et qui a rendu ce fichier necessaire
 *
 * `Api\ServiceController@show` et `Api\ServiceRequestController@show`
 * chargeaient la relation BRUTE :
 * `with('user:id,name,rating,avatar,location,bio,is_available')`.
 *
 * Deux champs n'avaient rien a y faire :
 *
 * - **`location`** est le champ LEGACY que TASK-358 Lot 3 a retire de
 *   l'interface pour raison de vie privee — la fiche montre la ville
 *   (`public_location`), jamais ce texte libre. Mesure au HEAD : la page web
 *   ne montrait PAS « Chezelles (37) », l'API le rendait a un anonyme.
 *   24 personnes portent un `location`, et pour 23 d'entre elles il differe
 *   de la ville.
 * - **`bio`** s'affiche sur `profile/show.blade.php`, que TASK-1479 a ferme aux
 *   MEMBRES de l'Organization. C'est donc un champ visible des membres, pas une
 *   donnee du Web ouvert.
 *
 * ## Pourquoi une classe, et pas un `select()` corrige a trois endroits
 *
 * Parce que le defaut n'etait pas la liste de champs : c'etait qu'il n'existait
 * AUCUN endroit ou la question « qu'est-ce qui est public d'une personne ? »
 * ait une reponse. Trois surfaces repondaient trois choses differentes — la
 * liste (`index`) etait deja etroite, la fiche (`show`) large, et
 * `Api\ProfileController@show` avait sa propre liste explicite.
 *
 * Corriger les `select()` aurait laisse la meme absence d'autorite, et le
 * prochain endpoint aurait invente une quatrieme reponse.
 *
 * ## La regle
 *
 * ALLOWLIST stricte. Un champ n'est publie que s'il est nomme ici. Ajouter une
 * colonne au modele `User` ne l'expose donc jamais par accident — c'est tout
 * l'interet par rapport a une relation brute, qui publiait `location` sans que
 * personne ne l'ait decide.
 *
 * `public_location` est repris tel quel de TASK-358 Lot 3 : ville, et pays
 * seulement si l'Organization l'autorise. On ne reecrit pas cette decision, on
 * s'y branche.
 */
final class PublicUserProjection
{
    /**
     * Les seuls champs d'une personne que l'API publique publie.
     *
     * @var list<string>
     */
    public const FIELDS = ['id', 'name', 'avatar_url', 'rating', 'is_available', 'public_location'];

    /**
     * Les colonnes a charger pour construire cette projection.
     *
     * `city` et `country_code` ne sont PAS publiees telles quelles : elles
     * alimentent l'accesseur `public_location`, qui applique la regle
     * d'Organization (`show_country`). `avatar` alimente `avatar_url`.
     *
     * @var list<string>
     */
    public const COLUMNS = ['id', 'organization_id', 'name', 'rating', 'avatar', 'is_available', 'city', 'country_code'];

    /** La relation Eloquent a charger, colonnes comprises. */
    public static function relation(): string
    {
        return 'user:'.implode(',', self::COLUMNS);
    }

    /** @return array<string, mixed>|null */
    public static function for(?User $user): ?array
    {
        if (! $user instanceof User) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'avatar_url' => $user->avatar_url,
            'rating' => $user->rating,
            'is_available' => $user->is_available,
            'public_location' => $user->public_location,
        ];
    }

    /**
     * Remplace le bloc `user` d'une charge utile deja serialisee.
     *
     * On passe par le tableau plutot que par `setRelation()` : la projection
     * n'est pas un modele, et la substituer dans la relation ferait mentir le
     * type. Ici la frontiere est explicite — ce qui sort est ce tableau.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function applyTo(array $payload, ?User $user): array
    {
        $payload['user'] = self::for($user);

        return $payload;
    }
}
