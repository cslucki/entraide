<?php

namespace App\Support;

use Closure;

/**
 * TASK-1778 — se souvenir d'une lecture, le temps d'UNE requete ou d'UN job.
 *
 * ## Le defaut mesure que cette classe corrige
 *
 * Le fan-out d'une demande d'aide relit, pour CHAQUE destinataire, des lignes
 * qui sont les memes pour tout le monde : la Boucle, l'Organization, la langue
 * de l'Organization. Le banc T1778 a compte, pour une Boucle de 100 membres :
 *
 * | lecture repetee | par destinataire | total |
 * |---|---|---|
 * | Boucle + Organization du resolveur de cibles | 4 | **400** |
 * | langue de l'Organization (2 compositions par email) | 2 | **200** |
 *
 * Six cents requetes pour trois lignes. Sur SQLite local, invisible. Sur Neon,
 * chacune paie un aller-retour reseau.
 *
 * ## Ce que ce n'est PAS
 *
 * Ce n'est **pas un cache** au sens de l'infrastructure : aucun Redis, aucun
 * Memcached, aucun fichier, rien qui survive au processus, rien qui se partage
 * entre deux visiteurs. C'est un tableau PHP dont la duree de vie est bornee
 * par le conteneur.
 *
 * ## La duree de vie, et pourquoi elle est la bonne
 *
 * Le service est enregistre en `scoped()`. Laravel vide les instances
 * `scoped` a DEUX moments :
 *
 * - entre deux requetes HTTP (`Illuminate\Foundation\Http\Kernel`) ;
 * - **entre deux jobs de file d'attente**
 *   (`Illuminate\Queue\QueueServiceProvider`, qui appelle
 *   `forgetScopedInstances()` dans le `resetScope` du `Worker`).
 *
 * La seconde est la raison du choix. Un `singleton()` aurait survecu d'un job
 * a l'autre dans un `queue:work` de longue duree, et une Boucle renommee ou
 * une Organization dont le slug a change seraient restees perimees jusqu'au
 * redemarrage du worker — exactement le piege deja rencontre avec le code
 * garde en memoire par un worker. `scoped()` donne la seule garantie qui
 * compte ici : **ce qui est memorise ne traverse jamais la frontiere d'une
 * unite de travail.**
 *
 * ## Ce qu'on y met, et ce qu'on n'y met JAMAIS
 *
 * Uniquement des lectures de REFERENCE, identiques pour tous les
 * destinataires d'un meme evenement, et dont une evolution en cours de
 * requete n'aurait aucun sens metier : une Organization, une Boucle, la
 * langue d'une Organization.
 *
 * Jamais une preference, jamais une appartenance, jamais un statut de
 * livraison. Les livreurs doivent relire la preference COURANTE au moment de
 * livrer — une preference memorisee a la planification rendrait un verdict
 * perime et casserait cette garantie. La frontiere est la, et elle est
 * explicite dans le docblock de chaque appelant.
 *
 * **Jamais du contenu ADMINISTRABLE non plus.** Le gabarit d'email a ete
 * memorise ici le temps d'une mesure, puis retire : il est fait pour etre
 * modifie, l'ecran d'administration le re-rend dans la requete meme ou il
 * l'enregistre, et un `Builder::update()` en masse ne declenche aucun
 * evenement de modele — donc aucune invalidation ne pouvait etre fiable. Le
 * raisonnement complet est dans `SystemEmailTemplateResolver::resolve()`.
 *
 * ## La cle est la question, pas la reponse
 *
 * Elle doit contenir TOUT ce qui fait varier la reponse. Une cle qui oublie
 * un parametre rend la valeur d'un autre appel — un defaut qui ne se voit pas
 * en test unitaire, seulement en charge, et seulement parfois.
 */
final class ScopedReadCache
{
    /** @var array<string, mixed> */
    private array $valeurs = [];

    /**
     * La valeur memorisee, ou le resultat de `$lecture` — memorise.
     *
     * `array_key_exists` et non `isset` : une lecture qui rend legitimement
     * `null` (« cette Boucle n'existe plus ») doit etre memorisee comme telle,
     * sinon la requete repart a chaque destinataire et le cas ABSENT devient
     * le plus couteux de tous.
     *
     * @template T
     *
     * @param  Closure(): T  $lecture
     * @return T
     */
    public function souviens(string $cle, Closure $lecture): mixed
    {
        if (array_key_exists($cle, $this->valeurs)) {
            return $this->valeurs[$cle];
        }

        return $this->valeurs[$cle] = $lecture();
    }

    /** Pour les tests qui mesurent une evolution en cours de requete. */
    public function oublie(): void
    {
        $this->valeurs = [];
    }

    /** Combien de reponses sont memorisees — pour le banc, jamais pour decider. */
    public function taille(): int
    {
        return count($this->valeurs);
    }
}
