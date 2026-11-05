<?php

namespace App\Support\Entraide;

use App\Models\Service;
use App\Models\Transaction;

/**
 * TASK-1756 (§6.3 du CDC) — l'atelier de groupe, sans transaction de groupe.
 *
 * ## Le modele reste N echanges 1-1
 *
 * Une Transaction est strictement 1-1 (`buyer_id` et `seller_id` NOT NULL).
 * Plutot que d'inventer une « transaction a six » — table de participants,
 * reprise de l'existant, seconde choregraphie a maintenir — on reutilise ce qui
 * marche deja : plusieurs personnes ont CHACUNE leur echange vers la meme
 * annonce. C'est le mecanisme livre par T1693 bug W cote demandes, et la garde
 * anti-doublon porte sur le couple (proposant, annonce), pas sur l'annonce.
 *
 * Chaque participant garde donc sa conversation, ses etapes et sa
 * confirmation. Il ne manquait qu'une BORNE.
 *
 * ## Ce qu'est une PLACE
 *
 * Une place est prise par un echange ACCEPTE — pas par une proposition en
 * attente. Une proposition qui n'a pas encore ete acceptee ne reserve rien :
 * sinon trois personnes pourraient bloquer un atelier de trois sans que
 * l'aidant ait dit oui a quiconque.
 *
 * Consequence directe, et c'est la garde qui compte : deux propositions en
 * attente pour UNE place restante sont toutes deux legitimes, et c'est a
 * `approve()` que la derniere doit etre refusee. La garde a `store()` ne suffit
 * donc JAMAIS a elle seule.
 *
 * ## Le modele de cout — option C, arbitrage Cyril du 05/11
 *
 * L'aidant annonce X pour l'atelier ENTIER, et declare un PLANCHER de
 * repartition. Chaque participant paie `ceil(X / max(inscrits, plancher))`.
 *
 * Les deux lectures sans plancher etaient toutes deux injustes :
 * - repartir sur les inscrits REELS fait payer 45 min a une personne seule ;
 * - repartir sur la CAPACITE fait que l'aidant qui annonce 45 min n'en recoit
 *   que 22 si l'atelier est a moitie plein.
 *
 * Le plancher tranche : l'aidant dit « je le fais a partir de 3 », le
 * participant connait son prix MAXIMUM des l'inscription, et le risque du
 * non-remplissage est partage selon ce que l'aidant a lui-meme declare.
 */
final class Atelier
{
    /** La borne produit. Au-dela, ce n'est plus de l'entraide, c'est une salle. */
    public const CAPACITE_MAX = 6;

    public const CAPACITE_MIN = 2;

    /** Les statuts qui OCCUPENT une place. */
    public const STATUTS_ENGAGES = ['accepted', 'buyer_done', 'completed'];

    /** Cette annonce est-elle un atelier de groupe ? */
    public static function estGroupe(Service $annonce): bool
    {
        return $annonce->capacity !== null && (int) $annonce->capacity >= self::CAPACITE_MIN;
    }

    /** Une capacite saisie est-elle acceptable ? `null` reste l'individuel. */
    public static function capaciteValide(mixed $capacite): bool
    {
        if ($capacite === null || $capacite === '') {
            return true;
        }

        $n = (int) $capacite;

        return $n >= self::CAPACITE_MIN && $n <= self::CAPACITE_MAX;
    }

    /** Combien de places sont OCCUPEES aujourd'hui. */
    public static function placesPrises(Service $annonce): int
    {
        return Transaction::query()
            ->withoutGlobalScopes()
            ->where('service_id', $annonce->id)
            ->whereIn('status', self::STATUTS_ENGAGES)
            ->count();
    }

    /**
     * Combien il en reste. `null` pour une annonce individuelle — il n'y a pas
     * de places a compter, et rendre « 1 » ferait croire a un atelier d'une
     * personne.
     */
    public static function placesRestantes(Service $annonce): ?int
    {
        if (! self::estGroupe($annonce)) {
            return null;
        }

        return max(0, (int) $annonce->capacity - self::placesPrises($annonce));
    }

    /** L'atelier est-il plein ? Une annonce individuelle ne l'est jamais. */
    public static function estComplet(Service $annonce): bool
    {
        return self::estGroupe($annonce) && self::placesRestantes($annonce) === 0;
    }

    /**
     * Le plancher de repartition. `null` = pas de plancher declare.
     *
     * Borne a la capacite : declarer « a partir de 8 » sur un atelier de 6
     * n'aurait aucun sens, et rendrait le prix maximum inatteignable.
     */
    public static function plancher(Service $annonce): int
    {
        if (! self::estGroupe($annonce)) {
            return 1;
        }

        $plancher = (int) ($annonce->min_participants ?? 1);

        return max(1, min($plancher, (int) $annonce->capacity));
    }

    /**
     * La PART de chaque participant — option C, arbitrage Cyril du 05/11.
     *
     * `ceil(X / max(inscrits, plancher))`.
     *
     * ## Pourquoi `ceil` et JAMAIS `round`
     *
     * Les points sont des ENTIERS en base : aucune part ne peut comporter de
     * virgule, et le demander serait impossible a honorer. Reste a choisir le
     * SENS de l'arrondi, et ce n'est pas neutre.
     *
     * `round()` peut faire que la somme des parts soit INFERIEURE a l'annonce —
     * l'aidant serait alors paye moins que ce qu'il a affiche, sans que
     * personne n'ait rien decide. `ceil()` garantit le sens de l'ecart : la
     * somme vaut X, ou tres legerement plus, et le reste va a l'aidant.
     *
     * Exemple : 45 min pour 4 participants donne 11,25 → chacun paie 12, et
     * l'aidant recoit 48. Jamais 44.
     *
     * Une annonce individuelle rend son prix inchange : le diviseur vaut 1.
     */
    public static function part(Service $annonce, ?int $inscrits = null): int
    {
        $points = max(0, (int) $annonce->points_cost);

        if (! self::estGroupe($annonce)) {
            return $points;
        }

        $inscrits ??= self::placesPrises($annonce);
        $diviseur = max(1, $inscrits, self::plancher($annonce));

        return (int) ceil($points / $diviseur);
    }

    /**
     * Le prix MAXIMUM qu'un participant puisse payer — celui qu'on affiche.
     *
     * C'est tout l'interet du plancher : le participant connait son plafond des
     * l'inscription. Plus l'atelier se remplit, moins il paie, jamais plus.
     */
    public static function partMaximale(Service $annonce): int
    {
        return self::part($annonce, inscrits: 0);
    }
}
