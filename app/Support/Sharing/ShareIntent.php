<?php

namespace App\Support\Sharing;

use App\Services\UrlPreviewService;
use Illuminate\Http\Request;

/**
 * TASK-1738 — une intention de partage, telle qu'elle arrive du dehors.
 *
 * ## Pourquoi un objet, et pas trois parametres de requete
 *
 * La feuille de partage d'Android envoie `url`, `text` et `title`, et aucune
 * des trois n'est fiable : certaines applications mettent l'adresse dans
 * `text`, d'autres y ajoutent un titre, LinkedIn y met parfois les deux
 * collees. Ce qui arrive est donc une matiere brute, dont il faut EXTRAIRE une
 * URL — pas un parametre qu'on peut lire tel quel.
 *
 * Cet objet est le seul endroit ou cette extraction a lieu, et le seul endroit
 * ou l'on decide qu'une intention est recevable. Le controleur ne valide rien
 * lui-meme : il recoit un objet deja juge.
 *
 * ## La duree de vie, et pourquoi elle est courte
 *
 * L'intention vit en SESSION, jamais dans l'URL — le CDC section 4.5 l'exige :
 * « eviter de laisser durablement la ressource partagee en query string
 * visible ». Une adresse partagee peut etre privee ; elle n'a rien a faire
 * dans l'historique du navigateur, dans un `Referer`, ni dans les journaux
 * d'acces du serveur.
 *
 * Elle porte donc un horodatage et expire. Une intention qui traine une heure
 * n'est plus une intention : c'est un residu qui republierait un lien oublie
 * au prochain passage sur la page.
 */
final class ShareIntent
{
    /** La cle de session. Une seule intention a la fois : la derniere gagne. */
    public const CLE_SESSION = 'bp_share_intent';

    /**
     * Au-dela, l'intention est perimee.
     *
     * Quinze minutes couvrent largement un detour par la connexion — le cas
     * long, ou quelqu'un partage depuis une application sans etre connecte,
     * doit saisir son mot de passe, et revient. Au-dela, republier un lien que
     * la personne a oublie serait une surprise, pas un service.
     */
    public const EXPIRATION_SECONDES = 900;

    /**
     * Longueur maximale d'une adresse acceptee.
     *
     * Les navigateurs s'arretent autour de 2 000 caracteres pour une URL
     * entiere. On borne plus bas : au-dela, ce n'est plus un lien qu'on
     * partage, et une adresse de cette taille ne sert qu'a gonfler une session
     * ou un journal.
     */
    public const LONGUEUR_MAX_URL = 1500;

    /** Le commentaire reste FACULTATIF — decision produit, CDC section 4.1. */
    public const LONGUEUR_MAX_COMMENTAIRE = 2000;

    private function __construct(
        public readonly string $url,
        public readonly ?string $titre,
        public readonly int $recueA,
        /**
         * Le jeton d'usage unique de CETTE intention.
         *
         * Né avec l'intention, il circule du formulaire au POST de
         * publication, où il est consommé par `Cache::add()` — une pose
         * atomique, pas un « lire puis supprimer » (CDC section 4.5). Deux
         * confirmations concurrentes se disputent la même pose ; une seule
         * la gagne, l'autre ne publie rien.
         */
        public readonly string $jeton,
    ) {}

    /**
     * Lire ce qu'une feuille de partage vient d'envoyer.
     *
     * Rend `null` quand rien d'exploitable n'en sort — et c'est un cas
     * ORDINAIRE, pas une erreur : quelqu'un peut partager un texte sans lien,
     * ou une application peut envoyer un format qu'on ne sait pas lire. Le
     * controleur mene alors vers la saisie manuelle, qui est aussi le chemin
     * des plateformes sans `share_target`.
     */
    public static function depuisLaRequete(Request $request): ?self
    {
        $candidats = array_filter([
            $request->query('url'),
            $request->query('text'),
            $request->query('title'),
        ], static fn ($valeur): bool => is_string($valeur) && $valeur !== '');

        foreach ($candidats as $candidat) {
            $url = self::extraireUneUrlSure($candidat);

            if ($url !== null) {
                $titre = $request->query('title');

                return new self(
                    url: $url,
                    titre: is_string($titre) && $titre !== '' && self::extraireUneUrlSure($titre) === null
                        ? mb_substr(trim($titre), 0, 200)
                        : null,
                    recueA: now()->timestamp,
                    jeton: bin2hex(random_bytes(16)),
                );
            }
        }

        return null;
    }

    /** Reconstruire une intention depuis la session, si elle est encore valide. */
    public static function depuisLaSession(?array $stocke): ?self
    {
        if (! is_array($stocke) || ! isset($stocke['url'], $stocke['recueA'], $stocke['jeton'])) {
            return null;
        }

        $intention = new self(
            url: (string) $stocke['url'],
            titre: isset($stocke['titre']) ? (string) $stocke['titre'] : null,
            recueA: (int) $stocke['recueA'],
            jeton: (string) $stocke['jeton'],
        );

        return $intention->estPerimee() ? null : $intention;
    }

    public function estPerimee(): bool
    {
        // `now()->timestamp`, jamais `time()` : le premier suit
        // `Carbon::setTestNow()`, le second l'ignore et rend les tests de duree
        // immesurables.
        return now()->timestamp - $this->recueA > self::EXPIRATION_SECONDES;
    }

    /** @return array{url: string, titre: ?string, recueA: int, jeton: string} */
    public function pourLaSession(): array
    {
        return ['url' => $this->url, 'titre' => $this->titre, 'recueA' => $this->recueA, 'jeton' => $this->jeton];
    }

    /**
     * Extraire d'un texte libre une adresse qu'on accepte de manipuler.
     *
     * ## Ce que cette garde protege, et ce qu'elle ne protege PAS
     *
     * Elle juge la FORME de l'adresse : schema, longueur, absence
     * d'identifiants. Elle ne juge pas sa DESTINATION — savoir si un hote est
     * interne, prive, ou derriere une redirection hostile est le travail de
     * `UrlPreviewService`, qui resout le DNS, refuse les plages privees et
     * revalide chaque saut de redirection.
     *
     * La separation est volontaire : ici on decide si l'on garde la chaine,
     * la-bas si l'on ose la RECUPERER. Le CDC section 4.5 le dit dans ces
     * termes — « hotes internes/prives autorises eventuellement comme texte,
     * jamais comme cible de recuperation de metadonnees serveur ».
     */
    public static function extraireUneUrlSure(string $texte): ?string
    {
        // `extractFirstUrl()` ne reconnait que `http://` et `https://`. Un
        // `javascript:` ou un `data:` n'est donc jamais extrait — et plutot
        // que de s'y fier en silence, le test
        // `TASK1738ShareTargetTest::test_a_dangerous_scheme_is_never_extracted`
        // l'atteste, pour que la garde survive a un changement de cette
        // expression reguliere.
        $url = UrlPreviewService::extractFirstUrl($texte);

        if ($url === null) {
            return null;
        }

        $url = rtrim($url, '.,;:!?)]}\'"');

        if (mb_strlen($url) > self::LONGUEUR_MAX_URL) {
            return null;
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host']) || $parts['host'] === '') {
            return null;
        }

        if (! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return null;
        }

        // Une URL porteuse d'identifiants (`https://jean:motdepasse@site`) est
        // REFUSEE, pas nettoyee. La nettoyer publierait un lien que la personne
        // n'a pas voulu partager sous cette forme ; la refuser lui laisse le
        // choix. Le CDC la cite nommement parmi les controles a documenter.
        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        return $url;
    }
}
