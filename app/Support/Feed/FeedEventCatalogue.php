<?php

namespace App\Support\Feed;

use App\Models\Loop;
use App\Models\LoopEvent;
use App\Models\LoopMember;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\Transaction;
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

    /**
     * TASK-1757 §9.5, puis TASK-1809 §2 — une entraide terminee.
     *
     * Le declencheur a change : `TransactionController::partagerDansLeFlux()`
     * (opt-in par case a cocher, chemin HERITE de `confirm()`, non atteignable
     * depuis le parcours visible) existe toujours, mais le chemin COURANT est
     * desormais `EvaluationEntraide::enregistrer()` — le partage est
     * AUTOMATIQUE des que l'aide depose son evaluation, plus de case a
     * cocher. Ce registre dit ce qu'un evenement EST, pas quand il nait.
     */
    public const ENTRAIDE_TERMINEE = 'event.help_completed';

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
                // L'objet est l'APPARTENANCE, pas la Boucle.
                //
                // DEFAUT TROUVE EN RECETTE (04/11) : avec la Boucle comme
                // objet, la contrainte unique
                // `(organization, type, object_type, object_id)` faisait que
                // SEULE LA PREMIERE personne a rejoindre chaque Boucle
                // obtenait une ligne — toutes les suivantes etaient avalees
                // en silence. Mesure : 15 adhesions reparties sur 3 Boucles
                // n'avaient produit que 3 lignes.
                //
                // Invisible aux tests, qui n'utilisaient qu'un seul arrivant
                // par Boucle. C'est la reprise sur de VRAIES donnees qui l'a
                // montre.
                'object' => LoopMember::class,
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
            self::ENTRAIDE_TERMINEE => [
                'object' => Transaction::class,
                'visibilite' => self::VISIBILITE_ORGANIZATION,
                'libelle' => 'feed.events.help_completed',
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
     * Le libelle COURT d'un type, SANS parametre — pour une case a cocher,
     * un filtre, un intitule de colonne. `libelle()` rend la PHRASE complete
     * (« :name a rejoint la Boucle :object »), inutilisable ici : il n'y a ni
     * acteur ni objet a cette echelle, juste le TYPE lui-meme.
     *
     * Les clefs vivent sous `admin.feed_replay_source_*`, partagees avec
     * `FeedHistoryReplay` : la commande Artisan et la page SuperAdmin disent
     * le meme mot pour le meme type.
     */
    public static function libelleCourt(string $type): string
    {
        $courts = [
            self::BOUCLE_CREEE => 'loops_created',
            self::ADHESION_BOUCLE => 'memberships',
            self::PROPOSITION_PUBLIEE => 'services',
            self::DEMANDE_PUBLIEE => 'requests',
            self::EVENEMENT_CREE => 'events',
            self::PRESENTATION_TERMINEE => 'presentations',
        ];

        return __('admin.feed_replay_source_'.($courts[$type] ?? $type));
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
    public static function audiencePour(string $type, mixed $objet = null, ?Loop $boucle = null): string
    {
        if ($type !== self::ADHESION_BOUCLE) {
            return self::types()[$type]['visibilite'] ?? self::VISIBILITE_BOUCLE;
        }

        // La Boucle est fournie a part : depuis que l'objet est
        // l'appartenance, elle ne peut plus etre deduite de `$objet`.
        $boucle ??= $objet instanceof LoopMember
            ? Loop::withoutGlobalScopes()->find($objet->loop_id)
            : null;

        if (! $boucle instanceof Loop) {
            return self::VISIBILITE_BOUCLE;
        }

        return $boucle->isPublic()
            ? self::VISIBILITE_ORGANIZATION
            : self::VISIBILITE_BOUCLE;
    }
}
