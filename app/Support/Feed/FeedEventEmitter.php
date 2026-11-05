<?php

namespace App\Support\Feed;

use App\Models\FeedPost;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * TASK-1752 — le point d'entree UNIQUE des evenements de Flux.
 *
 * ## Pourquoi un seul point d'entree
 *
 * Cinq evenements, poses a six endroits du code. Si chacun ecrivait son
 * `FeedPost::create()`, chacun porterait sa propre idee de l'audience, de
 * l'idempotence et du `published_at` — et la sixieme se tromperait.
 *
 * Meme doctrine que `NotificationEmitter` : fail-closed, idempotent, et c'est
 * le service qui decide, pas l'appelant.
 *
 * ## L'idempotence est STRUCTURELLE, pas conventionnelle
 *
 * La contrainte `unique(organization_id, type, object_type, object_id)` est en
 * BASE. Ce service s'appuie dessus plutot que de faire un `exists()` prealable
 * — qui laisserait une fenetre entre la lecture et l'ecriture, exactement le
 * defaut que la contrainte existe pour fermer.
 *
 * Le `QueryException` de violation d'unicite est donc **un succes** : cela veut
 * dire que la ligne etait deja la. On la relit et on la rend.
 *
 * ## Pourquoi `emettre()` ne leve jamais vers l'appelant
 *
 * Les six points de bascule sont des gestes metier : publier une demande,
 * rejoindre une Boucle, enregistrer son profil. **Aucun ne doit echouer parce
 * que le Flux a eu un probleme.** La personne verrait une erreur pour une
 * action pourtant accomplie. Meme choix que `rescue()` dans
 * `TransactionController`.
 *
 * ## `published_at` peut etre ANTIDATE
 *
 * C'est ce qui rend la reprise d'historique possible (§8.4 du CDC). La vraie
 * date du fait va dans `published_at` — la colonne que le Flux trie et que la
 * carte affiche — pendant que `created_at` garde honnetement le moment ou la
 * ligne a ete ecrite. Aucune colonne n'affirme avoir ete creee a une date
 * qu'elle n'a pas.
 *
 * Une garde refuse le FUTUR : un `published_at` a venir ferait disparaitre la
 * ligne des listes, ou la ferait flotter en tete sans raison.
 */
final class FeedEventEmitter
{
    /**
     * TASK-1752 — l'emission est SUSPENDUE pendant un chargement en masse.
     *
     * ## Le defaut que cela ferme, trouve par la CI
     *
     * Les packs de scenarios appellent `LoopService::createLoopForOrg()` et
     * `addMemberByUserId()` — les primitives canoniques du produit, et c'est
     * voulu. Mais chaque appel emettait donc une ligne de Flux : charger un
     * pack remplissait le Flux de dizaines de lignes qui ne racontent rien.
     *
     * Pire, et c'est ce que la CI a montre :
     * `ScenarioLifecycleTest::test_reinitialiser_restaure_EXACTEMENT_le_monde`
     * supprime un compte de la sandbox par `User::query()->whereKey()->delete()`.
     * Un `delete()` de QUERY BUILDER ne declenche AUCUN evenement de modele —
     * la regle posee sur `User::deleting` ne s'y applique donc pas — et
     * `feed_posts.user_id` est en RESTRICT : la suppression echouait.
     *
     * Le CDC l'avait annonce (§2.3, §2.4) : « a neutraliser explicitement ».
     * J'avais ecrit a tort que les packs ne passaient pas par `LoopService`.
     *
     * ## Pourquoi un drapeau et pas `withoutEvents()`
     *
     * `withoutEvents()` couperait TOUS les evenements de modele, y compris
     * ceux dont le pack depend. Ce drapeau ne coupe que l'emission de Flux,
     * nommement, et il est RETABLI dans un `finally` — une exception pendant
     * le chargement ne doit pas laisser le produit muet pour la suite du
     * processus.
     */
    private static bool $suspendu = false;

    /**
     * Execute `$travail` sans qu'aucune ligne de Flux ne soit ecrite.
     *
     * @template T
     *
     * @param  callable(): T  $travail
     * @return T
     */
    public static function sansEmission(callable $travail): mixed
    {
        $precedent = self::$suspendu;
        self::$suspendu = true;

        try {
            return $travail();
        } finally {
            self::$suspendu = $precedent;
        }
    }

    /**
     * Ecrit — ou retrouve — la ligne de Flux d'un evenement.
     *
     * @param  string  $type  une clef du registre ; tout autre valeur est refusee
     * @param  mixed  $objet  l'objet metier concerne (sa classe est verifiee)
     * @param  User  $acteur  la personne dont on parle
     * @param  \DateTimeInterface|string|null  $publieLe  la vraie date du fait ; maintenant par defaut
     */
    public function emettre(
        string $type,
        mixed $objet,
        User $acteur,
        Organization|string $organization,
        ?Loop $boucle = null,
        \DateTimeInterface|string|null $publieLe = null,
    ): ?FeedPost {
        if (self::$suspendu) {
            return null;
        }

        if (! FeedEventCatalogue::connait($type)) {
            return null;
        }

        $classeAttendue = FeedEventCatalogue::classeAttendue($type);

        // Un objet qui ne correspond pas au type est un defaut d'appel, pas un
        // cas limite : il produirait une ligne dont le rendu ne saurait rien
        // faire. On refuse plutot que d'ecrire une ligne morte.
        if ($classeAttendue === null || ! $objet instanceof $classeAttendue) {
            return null;
        }

        $organizationId = $organization instanceof Organization
            ? $organization->id
            : (string) $organization;

        if ($organizationId === '') {
            return null;
        }

        if (! $this->activeSur($organization, $organizationId)) {
            return null;
        }

        $audience = FeedEventCatalogue::audiencePour($type, $objet, $boucle);

        $quand = $publieLe !== null ? Carbon::parse($publieLe) : now();

        // JAMAIS dans le futur. Une ligne a venir disparaitrait des listes ou
        // flotterait en tete sans raison.
        if ($quand->isFuture()) {
            $quand = now();
        }

        $cle = [
            'organization_id' => $organizationId,
            'type' => $type,
            'object_type' => $classeAttendue,
            'object_id' => (string) $objet->getKey(),
        ];

        $existante = FeedPost::withTrashed()->where($cle)->first();

        if ($existante !== null) {
            return $existante;
        }

        try {
            return DB::transaction(fn (): FeedPost => FeedPost::create($cle + [
                'user_id' => $acteur->id,
                'content' => null,
                'status' => FeedPost::STATUS_PUBLISHED,
                'visibility' => $audience,
                'loop_id' => $audience === FeedEventCatalogue::VISIBILITE_BOUCLE
                    ? ($boucle?->id ?? ($objet instanceof Loop ? $objet->id : null))
                    : null,
                'published_at' => $quand,
            ]));
        } catch (QueryException $e) {
            // Violation d'unicite = la ligne existait deja, ecrite entre notre
            // lecture et notre ecriture. C'est le resultat VOULU, pas un echec.
            return FeedPost::withTrashed()->where($cle)->first();
        }
    }

    /**
     * Met a jour l'audience d'un evenement DEJA emis.
     *
     * Sert a la promotion de visibilite d'un Evenement : cree « loop », il
     * peut devenir « organization » plus tard. Sans cela, la promotion
     * creerait une SECONDE ligne pour le meme fait — ce que la contrainte
     * d'unicite refuserait de toute facon, laissant le Flux mentir.
     */
    public function mettreAJourAudience(string $type, mixed $objet, Organization|string $organization, string $audience, ?Loop $boucle = null): ?FeedPost
    {
        if (! FeedEventCatalogue::connait($type)) {
            return null;
        }

        $classeAttendue = FeedEventCatalogue::classeAttendue($type);

        if ($classeAttendue === null || ! $objet instanceof $classeAttendue) {
            return null;
        }

        $ligne = FeedPost::where([
            'organization_id' => $organization instanceof Organization ? $organization->id : (string) $organization,
            'type' => $type,
            'object_type' => $classeAttendue,
            'object_id' => (string) $objet->getKey(),
        ])->first();

        if ($ligne === null) {
            return null;
        }

        $ligne->update([
            'visibility' => $audience,
            'loop_id' => $audience === FeedEventCatalogue::VISIBILITE_BOUCLE ? $boucle?->id : null,
        ]);

        return $ligne;
    }

    /**
     * L'interrupteur par Organization (§8.5, arbitrage Cyril).
     *
     * Defaut **ACTIVE** : il existe pour pouvoir ETEINDRE, pas pour attendre
     * qu'on l'allume. Une Organization qui n'en veut pas le coupe, sans
     * deploiement.
     */
    private function activeSur(Organization|string $organization, string $organizationId): bool
    {
        $org = $organization instanceof Organization
            ? $organization
            : Organization::query()->whereKey($organizationId)->first();

        if ($org === null) {
            return false;
        }

        return (bool) ($org->feed_auto_events_enabled ?? true);
    }
}
