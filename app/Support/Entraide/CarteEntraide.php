<?php

namespace App\Support\Entraide;

use App\Models\Transaction;
use App\Models\User;

/**
 * TASK-1755 (§3.2 et §5.2 du CDC) — ce qu'un echange RACONTE, selon qui regarde.
 *
 * ## Le defaut que cette classe ferme
 *
 * Le bandeau de conversation alignait jusqu'a QUATRE boutons — « Accepter
 * Refuser [30] Ajuster Annuler » — et pas une phrase ne disait QUI demandait
 * QUOI a QUI. Dans la liste, cinq conversations avec la meme personne ne se
 * distinguaient que par un badge. Constat de recette du 04/11, et c'est le
 * coeur de la demande de Cyril.
 *
 * ## Pourquoi une classe, et pourquoi elle ne rend pas d'URL
 *
 * Les memes trois questions — quelle phrase, quelle etape, quelle action — se
 * posent a DEUX endroits : la carte en tete de fil et la ligne de liste. Et la
 * liste est rendue DEUX fois (volet mobile et volet bureau). Ecrite dans les
 * vues, la reponse existerait en quatre exemplaires.
 *
 * En revanche elle ne construit AUCUNE URL : la conversation vit sur deux
 * surfaces (courte et prefixee par l'Organization), et seule la vue sait sur
 * laquelle le membre se trouve. Cette classe rend des NOMS de route ; la vue
 * les resout avec son idiome. Rendre une URL ici obligerait a dupliquer cette
 * connaissance, et c'est exactement ce que T1754 a paye sur `followMember`.
 *
 * ## Le sens de l'aide ne s'inverse JAMAIS
 *
 * `estLAidant()` / `estLAide()` et non `proposerId()` / `decisionMakerId()` :
 * qui PROPOSE s'inverse selon qu'on repond a une proposition ou a une demande
 * (T1693 bug S), mais qui APPORTE l'aide ne s'inverse pas. Confondre les deux,
 * c'est reintroduire le bug S dans les phrases.
 */
final class CarteEntraide
{
    /** Les quatre etapes de l'entraide, dans l'ordre. */
    public const ETAPES = ['proposition', 'accord', 'aide', 'confirmation'];

    /** Les filtres de la liste (§5.2.2). `tous` n'en est pas vraiment un. */
    public const FILTRES = ['tous', 'en_cours', 'discussions', 'termines'];

    /**
     * Un echange entre-t-il dans ce filtre ?
     *
     * La definition vit ICI et non dans le controleur ni dans la vue : les deux
     * en ont besoin — l'un pour filtrer, l'autre pour allumer la bonne pastille
     * — et une liste qui ne correspondrait pas a sa pastille serait pire que
     * pas de filtre du tout.
     *
     * « En cours » = ce sur quoi quelqu'un attend encore quelque chose. Les
     * discussions directes en sont EXCLUES : elles n'ont pas d'etat, elles ne
     * finissent jamais, et les y laisser aurait rempli le filtre de lignes sur
     * lesquelles il n'y a rien a faire.
     */
    public static function correspondAuFiltre(Transaction $echange, ?string $filtre): bool
    {
        if ($filtre === null || $filtre === 'tous' || ! in_array($filtre, self::FILTRES, true)) {
            return true;
        }

        if ($echange->isDirectConversation()) {
            return $filtre === 'discussions';
        }

        return match ($filtre) {
            'en_cours' => in_array($echange->status, ['pending', 'accepted', 'buyer_done'], true),
            'termines' => in_array($echange->status, ['completed', 'refused', 'cancelled'], true),
            default => false,
        };
    }

    /**
     * La phrase orientee role — c'est ELLE qui porte le sens.
     *
     * Arbitrage Q2 de Cyril : le badge reste court et neutre, la phrase dit
     * tout. Un badge, aussi bien ecrit soit-il, ne dira jamais « Ottoline dit
     * avoir recu l'aide, confirmez pour recevoir vos 30 min ».
     */
    public static function phrase(Transaction $echange, ?User $lecteur): ?string
    {
        if ($lecteur === null || $echange->isDirectConversation()) {
            return null;
        }

        $temps = TempsPartage::libelle($echange->points_agreed ?? $echange->points_proposed);
        $autre = self::prenomDeLAutre($echange, $lecteur);

        return match ($echange->status) {
            'pending' => $lecteur->id === $echange->decisionMakerId()
                ? __('messages.phrase.pending_decideur', [
                    'prenom' => $autre,
                    'titre' => $echange->subject,
                    'temps' => $temps,
                ])
                : __('messages.phrase.pending_proposant', ['prenom' => $autre, 'temps' => $temps]),

            'accepted' => __('messages.phrase.accepted', ['prenom' => $autre, 'temps' => $temps]),

            // Ici le role qui compte est celui de l'AIDE, pas du proposant.
            'buyer_done' => $echange->estLAidant($lecteur)
                ? __('messages.phrase.buyer_done_aidant', ['prenom' => $autre, 'temps' => $temps])
                : __('messages.phrase.buyer_done_aide', ['prenom' => $autre, 'temps' => $temps]),

            'completed' => __('messages.phrase.completed', ['prenom' => $autre, 'temps' => $temps]),

            'refused' => $lecteur->id === $echange->decisionMakerId()
                ? __('messages.phrase.refused_decideur', ['prenom' => $autre])
                : __('messages.phrase.refused_proposant', ['prenom' => $autre]),

            default => __('messages.phrase.cancelled', ['prenom' => $autre]),
        };
    }

    /**
     * L'etape en cours, de 1 a 4. Zero quand l'echange n'a pas abouti.
     *
     * Un echange decline ou annule n'est pas « a l'etape 1 » : il est SORTI du
     * parcours. Allumer un point ferait croire qu'il attend quelque chose.
     */
    public static function etape(Transaction $echange): int
    {
        return match ($echange->status) {
            'pending' => 1,
            'accepted' => 2,
            'buyer_done' => 3,
            'completed' => 4,
            default => 0,
        };
    }

    /**
     * Le sens de l'aide, pour la ligne de liste : « qui aide qui ».
     *
     * C'est ce qui manquait le plus : la meme personne apparaissait N fois et
     * seul un badge les distinguait. Le resultat porte son emoji et sa couleur
     * pour que le sens se lise AVANT le texte.
     *
     * @return array{emoji: string, texte: string, classe: string}
     */
    public static function sens(Transaction $echange, ?User $lecteur): array
    {
        if ($echange->isDirectConversation()) {
            return ['emoji' => '💬', 'texte' => __('messages.direct_conversation'), 'classe' => 'text-gray-500 dark:text-gray-400'];
        }

        $temps = TempsPartage::libelle($echange->points_agreed ?? $echange->points_proposed);
        $autre = self::prenomDeLAutre($echange, $lecteur);

        if ($echange->status === 'completed') {
            return [
                'emoji' => '✓',
                'texte' => __('messages.sens.terminee', ['temps' => $temps]),
                'classe' => 'text-green-600 dark:text-green-400',
            ];
        }

        // `estLAidant` et non `decisionMakerId` : on dit le SENS DE L'AIDE,
        // pas qui doit cliquer.
        return $echange->estLAidant($lecteur)
            ? ['emoji' => '👀', 'texte' => __('messages.sens.vous_aidez', ['prenom' => $autre, 'temps' => $temps]), 'classe' => 'text-green-600 dark:text-green-400']
            : ['emoji' => '🤝', 'texte' => __('messages.sens.on_vous_demande', ['prenom' => $autre, 'temps' => $temps]), 'classe' => 'text-amber-600 dark:text-amber-400'];
    }

    /**
     * UNE action principale, une secondaire, le reste au menu.
     *
     * Le bandeau alignait quatre boutons de meme poids : rien ne disait lequel
     * on attendait. Ici l'action principale est celle que le produit attend de
     * CE lecteur a CET instant ; « Proposer un autre temps » et les annulations
     * passent au menu « ⋯ », parce que ce sont les gestes rares.
     *
     * Aucune URL n'est construite : voir l'en-tete de classe.
     *
     * @return array{principale: ?array<string,mixed>, secondaire: ?array<string,mixed>, menu: list<array<string,mixed>>}
     */
    public static function actions(Transaction $echange, ?User $lecteur): array
    {
        $vide = ['principale' => null, 'secondaire' => null, 'menu' => []];

        if ($lecteur === null || $echange->isDirectConversation()) {
            return $vide;
        }

        $partie = $echange->estLAidant($lecteur) || $echange->estLAide($lecteur);

        if (! $partie) {
            return $vide;
        }

        $decide = $lecteur->id === $echange->decisionMakerId();
        $temps = TempsPartage::libelle($echange->points_agreed ?? $echange->points_proposed);

        $acte = fn (string $nom, string $cle, array $extra = []) => array_merge([
            'route' => 'transactions.'.$nom,
            'routeOrg' => 'organization.transactions.'.$nom,
            'libelle' => __('messages.actions.'.$cle),
            'marqueur' => $cle,
        ], $extra);

        return match ($echange->status) {
            'pending' => [
                'principale' => $decide ? $acte('approve', 'accept', ['ton' => 'vert']) : null,
                'secondaire' => $decide ? $acte('refuse', 'refuse') : null,
                'menu' => [
                    $acte('adjust', 'adjust', ['champTemps' => true]),
                    // Le proposant RETIRE sa proposition ; le decideur, lui,
                    // decline — il a deja son bouton secondaire.
                    $decide ? $acte('cancel', 'cancel_exchange') : $acte('cancel', 'cancel_withdraw'),
                ],
            ],
            'accepted' => [
                'principale' => $echange->estLAide($lecteur) ? $acte('complete', 'mark_done', ['ton' => 'indigo']) : null,
                'secondaire' => null,
                'menu' => [$acte('cancel', 'cancel_exchange')],
            ],
            'buyer_done' => [
                'principale' => $echange->estLAidant($lecteur)
                    ? $acte('confirm', 'confirm', ['ton' => 'vert', 'libelle' => __('messages.actions.confirm', ['temps' => $temps])])
                    : null,
                'secondaire' => $echange->estLAidant($lecteur) ? $acte('contest', 'contest') : null,
                'menu' => [],
            ],
            default => $vide,
        };
    }

    /** Le prenom de l'autre partie, ou un libelle neutre si le compte est parti. */
    private static function prenomDeLAutre(Transaction $echange, ?User $lecteur): string
    {
        $autre = $lecteur !== null && $echange->estLAide($lecteur) ? $echange->seller : $echange->buyer;

        return $autre?->first_name ?: ($autre?->name ?: __('profile.deactivated_user'));
    }
}
