<?php

namespace App\Support\Entraide;

use App\Models\Organization;
use App\Models\Review;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * TASK-1755 (§9.6 du CDC) — le profil comme BILAN D'ENTRAIDE.
 *
 * ## Ce que le profil disait, et ce qu'on attend de lui
 *
 * Il affichait un solde : « 160 pts ». C'est un chiffre de portefeuille, et il
 * ne dit rien de ce que la personne a fait pour les autres. Sur une plateforme
 * d'entraide, ce qu'on veut lire tient en trois nombres : combien de personnes
 * elle a aidees, combien de fois elle a ete aidee, et combien de temps elle a
 * offert.
 *
 * ## Une seule requete, et c'est volontaire
 *
 * Le profil est une page publique, consultee bien plus souvent qu'elle n'est
 * ecrite. Quatre agregats en quatre requetes auraient quadruple son cout pour
 * un gain nul : `SUM` et `COUNT DISTINCT` conditionnels font le meme travail en
 * un passage. Meme patron que `AdminController::userListStats()`.
 *
 * ## L'Organization est un PARAMETRE, pas un contexte ambiant
 *
 * Premiere version ecrite avec `withoutGlobalScopes()` : elle faisait
 * apparaitre sur le profil un merci ecrit dans une AUTRE Organization.
 * `T126ProfileReviewsTenantScopingTest` l'a attrapee immediatement — la
 * frontiere de locataire d'un avis est portee par sa TRANSACTION, pas par
 * l'avis lui-meme.
 *
 * Elle est donc passee en argument. Une frontiere de locataire qui depend d'un
 * scope implicite est une frontiere qu'on oublie le jour ou l'on appelle la
 * methode depuis une console, un worker ou une commande — exactement ce qui est
 * arrive en T1751 sur `Transaction::subject`.
 *
 * ## Les discussions directes sont EXCLUES
 *
 * « Ecrire a quelqu'un » cree une Transaction a 0 point. La compter comme une
 * entraide gonflerait le bilan de tout le monde avec de simples bonjours. Le
 * filtre porte sur l'ANNONCE (`service_id` ou `request_id`) et non sur les
 * points : un coup de main OFFERT vaut 0 point mais reste une entraide, et
 * c'est precisement la distinction que le §9.3 a introduite.
 */
final class BilanEntraide
{
    /**
     * Les quatre nombres du bilan, en UNE requete.
     *
     * @return array{personnes_aidees: int, fois_aide: int, temps_offert: int, mercis: int}
     */
    public static function pour(User $membre, Organization $organization): array
    {
        $ligne = Transaction::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('status', 'completed')
            ->where(fn ($q) => $q->where('seller_id', $membre->id)->orWhere('buyer_id', $membre->id))
            // On exclut les DISCUSSIONS directes, en appelant la definition
            // canonique — `scopeDirectConversation()` — plutot qu'en la
            // re-derivant.
            //
            // Premiere version : « doit avoir une annonce ». C'etait PLUS STRICT
            // que la definition, qui exige AUSSI zero point. Un echange a 30
            // points sans annonce — il en existe dans la base de recette —
            // disparaissait donc du bilan sans raison. Trouve en preparant la
            // recette, avant meme d'ouvrir le navigateur.
            ->whereNot(fn ($q) => $q->directConversation())
            ->selectRaw(
                'count(distinct case when seller_id = ? then buyer_id end) as personnes_aidees,'
                .' count(case when buyer_id = ? then 1 end) as fois_aide,'
                .' coalesce(sum(case when seller_id = ? then coalesce(points_agreed, 0) else 0 end), 0) as temps_offert',
                [$membre->id, $membre->id, $membre->id]
            )
            ->first();

        return [
            'personnes_aidees' => (int) ($ligne->personnes_aidees ?? 0),
            'fois_aide' => (int) ($ligne->fois_aide ?? 0),
            'temps_offert' => (int) ($ligne->temps_offert ?? 0),
            'mercis' => self::compteDesMercis($membre, $organization),
        ];
    }

    /**
     * Les derniers mots de remerciement, pour les montrer.
     *
     * Un compteur seul ne rend pas la gratitude : ce sont les MOTS qu'on vient
     * lire. Seules les notes qui en portent un sont remontees — une note nue
     * n'a rien a dire.
     *
     * @return Collection<int, Review>
     */
    public static function mercis(User $membre, Organization $organization, int $limite = 3)
    {
        return self::avisAvecMot($membre, $organization)
            ->with('reviewer:id,first_name,name,slug')
            ->latest('created_at')
            ->limit($limite)
            ->get();
    }

    /**
     * Les avis qui portent un MOT, dans CETTE Organization.
     *
     * La frontiere passe par `whereHas('transaction', …)` — meme patron que
     * `ProfileController`, et c'est le seul qui soit juste : un avis n'a pas
     * d'Organization propre, il herite de celle de l'echange qu'il clot.
     *
     * @return Builder<Review>
     */
    private static function avisAvecMot(User $membre, Organization $organization)
    {
        return Review::query()
            ->withoutGlobalScopes()
            ->where('reviewed_id', $membre->id)
            ->whereHas('transaction', fn ($q) => $q->withoutGlobalScopes()->where('organization_id', $organization->id))
            ->whereNotNull('comment')
            ->where('comment', '!=', '');
    }

    /**
     * Combien de MERCIS — pas combien de notes.
     *
     * Arbitrage Q6 : la note reste obligatoire, donc toute entraide notee en
     * produit une. Compter les notes reviendrait a compter les echanges une
     * seconde fois. Le merci, lui, est le geste volontaire : c'est celui qui
     * merite d'etre affiche.
     */
    private static function compteDesMercis(User $membre, Organization $organization): int
    {
        return (int) self::avisAvecMot($membre, $organization)->count();
    }

    /** « 4 h offertes » — le temps, jamais un nombre de points. */
    public static function tempsOffert(int $points): string
    {
        return TempsPartage::duree($points, offertSiZero: false);
    }

    /**
     * Le bilan a-t-il quelque chose a raconter ?
     *
     * Un profil tout neuf afficherait « 0 personne · 0 fois · 0 min », ce qui
     * est un reproche, pas un bilan. Mieux vaut ne rien montrer.
     */
    public static function estVide(array $bilan): bool
    {
        return $bilan['personnes_aidees'] === 0
            && $bilan['fois_aide'] === 0
            && $bilan['mercis'] === 0;
    }
}
