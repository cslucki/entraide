<?php

namespace App\Support\Feed;

use App\Models\Loop;
use App\Models\LoopEvent;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;

/**
 * TASK-1752 — le registre des evenements de Flux, FAIL-CLOSED.
 *
 * ## Pourquoi un registre et pas cinq chaines eparpillees
 *
 * `feed_posts.type` n'avait qu'une valeur (`announcement`), ecrite a un seul
 * endroit et **lue nulle part** : aucun filtre, aucun affichage conditionnel.
 * La colonne etait donc libre — mais **rien ne garde l'ensemble des valeurs
 * legales** : ni contrainte, ni enum, ni validation.
 *
 * Cinq chaines dispersees dans cinq controleurs auraient produit le defaut
 * classique : une faute de frappe cree un type qui n'existe pas, la ligne
 * s'ecrit quand meme, et rien ne s'affiche parce qu'aucun libelle ne
 * correspond. Un registre refuse a l'emission.
 *
 * ## Pas de contrainte `CHECK` en base, et c'est un choix
 *
 * **SQLite ne les applique pas** : un litteral invente dans un test passerait
 * en local et rougirait en CI PostgreSQL (lecon T1749). Le registre, lui,
 * refuse sur les deux moteurs, et le dit avec un message utile.
 *
 * ## L'audience par defaut est une DECISION, pas une commodite
 *
 * `ADHESION_BOUCLE` est le seul evenement dont l'audience **depend de
 * l'objet** : `organization` si la Boucle est publique, `loop` si elle est
 * privee (arbitrage Cyril, §8.2 du CDC). C'est la transposition exacte de
 * `LoopEventService::canPublishToOrganization()` — « une Boucle privee ne
 * remonte jamais, quel que soit le rang de celui qui demande ».
 *
 * Annoncer « Marie a rejoint le Conseil de direction » a toute l'Organization
 * revelerait une appartenance que le produit n'expose nulle part. T1075 a rendu
 * la BOUCLE decouvrable — son nom, son nombre de membres, ses animateurs — pas
 * sa liste de membres.
 *
 * La creation d'une Boucle, elle, est sans risque : le catalogue la montre
 * deja a tout le monde.
 */
final class FeedEventCatalogue
{
    public const PRESENTATION_TERMINEE = 'event.presentation_completed';

    public const DEMANDE_PUBLIEE = 'event.help_request_published';

    public const PROPOSITION_PUBLIEE = 'event.service_published';

    public const ADHESION_BOUCLE = 'event.loop_joined';

    public const BOUCLE_CREEE = 'event.loop_created';

    public const EVENEMENT_CREE = 'event.loop_event_created';

    public const VISIBILITE_ORGANIZATION = 'organization';

    public const VISIBILITE_BOUCLE = 'loop';

    /**
     * Le registre. Ecrit a la main, jamais derive d'un scan.
     *
     * `object` est la classe attendue : elle sert a refuser un objet qui ne
     * correspond pas au type, defaut qu'aucun test de rendu n'attraperait.
     *
     * @return array<string, array{object: class-string, visibilite: string, libelle: string}>
     */
    public static function types(): array
    {
        return [
            self::PRESENTATION_TERMINEE => [
                'object' => User::class,
                'visibilite' => self::VISIBILITE_ORGANIZATION,
                'libelle' => 'feed.events.presentation_completed',
            ],
            self::DEMANDE_PUBLIEE => [
                'object' => ServiceRequest::class,
                'visibilite' => self::VISIBILITE_ORGANIZATION,
                'libelle' => 'feed.events.help_request_published',
            ],
            self::PROPOSITION_PUBLIEE => [
                'object' => Service::class,
                'visibilite' => self::VISIBILITE_ORGANIZATION,
                'libelle' => 'feed.events.service_published',
            ],
            self::ADHESION_BOUCLE => [
                'object' => Loop::class,
                // Defaut seulement : `audiencePour()` tranche sur la Boucle.
                'visibilite' => self::VISIBILITE_BOUCLE,
                'libelle' => 'feed.events.loop_joined',
            ],
            self::BOUCLE_CREEE => [
                'object' => Loop::class,
                'visibilite' => self::VISIBILITE_ORGANIZATION,
                'libelle' => 'feed.events.loop_created',
            ],
            self::EVENEMENT_CREE => [
                'object' => LoopEvent::class,
                'visibilite' => self::VISIBILITE_ORGANIZATION,
                'libelle' => 'feed.events.loop_event_created',
            ],
        ];
    }

    /** @return list<string> */
    public static function clefs(): array
    {
        return array_keys(self::types());
    }

    public static function connait(string $type): bool
    {
        return array_key_exists($type, self::types());
    }

    public static function classeAttendue(string $type): ?string
    {
        return self::types()[$type]['object'] ?? null;
    }

    public static function libelle(string $type): ?string
    {
        return self::types()[$type]['libelle'] ?? null;
    }

    /**
     * L'audience d'un evenement, objet en main.
     *
     * Un seul type a une audience VARIABLE — l'adhesion a une Boucle. Pour
     * tous les autres, le registre tranche a lui seul.
     *
     * La question est posee a `Loop::isPublic()` — l'autorite du modele — et
     * non a `visibility === 'public'` recopie ici : `loops.visibility` vaut
     * `public` ou `private`, et re-deriver la regle finirait par diverger
     * d'elle (lecon payee sur un test qui re-derivait la condition d'une vue).
     *
     * Si la Boucle manque, on retombe sur la restriction — **fail-closed** :
     * dans le doute, on montre moins, jamais plus.
     */
    public static function audiencePour(string $type, mixed $objet = null): string
    {
        if ($type !== self::ADHESION_BOUCLE) {
            return self::types()[$type]['visibilite'] ?? self::VISIBILITE_BOUCLE;
        }

        if (! $objet instanceof Loop) {
            return self::VISIBILITE_BOUCLE;
        }

        return $objet->isPublic()
            ? self::VISIBILITE_ORGANIZATION
            : self::VISIBILITE_BOUCLE;
    }
}
