<?php

namespace App\Support\Homepage;

/**
 * TASK-1506 — ce que sert la RACINE de la plateforme.
 *
 * Demande de Cyril : « quand on tape test.laravel, on tombe sur un de ces
 * modules » — accueil traditionnel, Shell Welcome, blog, annuaire, boucles.
 * La fonctionnalite n'est attachee a AUCUN nom de domaine : elle repond a la
 * route `/`, quel que soit l'hote.
 *
 * La racine resout deja l'Organization PAR DEFAUT (`is_default`, repli
 * `main`) : le choix vit donc sur cette Organization, a cote de
 * `homepage_template` — qui repond a une AUTRE question (quel gabarit de
 * landing), et que ce reglage ne remplace pas.
 *
 * ## Ce que chaque modalite sert, et a qui
 *
 * `HOMEPAGE` conserve le comportement historique au bit pres : gabarit hero
 * -> landing de l'Organization, sinon la vue `home`. C'est le defaut, et ce
 * que voit une plateforme qui n'a jamais ouvert cette page.
 *
 * `SHELL_WELCOME` sert la landing de l'Organization par defaut, ou le mode
 * d'affichage du Guest Shell (TASK-1500) decide seul de la forme.
 *
 * `BLOG` et `LOOPS` sont PUBLICS : un visiteur anonyme les voit.
 *
 * `DIRECTORY` ne l'est pas. TASK-1479 (P0 privacy) a ferme l'annuaire aux
 * anonymes — noms, villes, biographies et affiliations etaient servis en 200
 * a un visiteur anonyme, Organizations privees comprises. Cette TASK ne
 * rouvre pas ce trou : la destination reste derriere `auth`, et l'anonyme
 * passe par la connexion avant d'y revenir. `requiresAuthentication()` dit
 * lesquelles sont dans ce cas, pour que l'ecran d'administration l'annonce
 * AVANT le choix plutot que de laisser decouvrir un mur de connexion.
 */
final class RootDestination
{
    public const HOMEPAGE = 'homepage';

    public const SHELL_WELCOME = 'shell_welcome';

    public const BLOG = 'blog';

    public const DIRECTORY = 'directory';

    public const LOOPS = 'loops';

    /** @var list<string> */
    public const MODES = [
        self::HOMEPAGE,
        self::SHELL_WELCOME,
        self::BLOG,
        self::DIRECTORY,
        self::LOOPS,
    ];

    public const DEFAULT = self::HOMEPAGE;

    /**
     * Les destinations fermees aux visiteurs anonymes.
     *
     * @var list<string>
     */
    public const AUTHENTICATED_MODES = [self::DIRECTORY];

    /** La route nommee servie, ou `null` quand la racine se rend elle-meme. */
    public static function routeFor(string $mode): ?string
    {
        return match ($mode) {
            self::BLOG => 'blog.index',
            self::DIRECTORY => 'members.index',
            self::LOOPS => 'boucles.index',
            default => null,
        };
    }

    public static function isValid(mixed $mode): bool
    {
        return is_string($mode) && in_array($mode, self::MODES, true);
    }

    /**
     * TASK-1628 — le SuperAdmin a-t-il VRAIMENT choisi, ou la colonne n'a-t-elle
     * jamais ete ecrite ?
     *
     * `normalize()` repond « quelle destination servir » et rabat donc NULL sur
     * `HOMEPAGE` : apres lui, « jamais choisi » et « choisi Accueil
     * traditionnel » sont le MEME mot. C'est cet aplatissement qui laissait la
     * surcouche `homepage_template` hero annuler un choix explicite.
     *
     * La distinction est FACTUELLE, pas devinee : la migration TASK-1506 a
     * ajoute la colonne `nullable()`, sans defaut ni backfill, et le SEUL
     * ecrivain du depot est `AdminRootDestinationController::update()`, qui
     * valide contre `MODES` — aucune factory, aucun seeder ne l'ecrit. Une
     * valeur presente et valide ne peut donc venir que de `/admin/homepage`.
     *
     * Une valeur inconnue restee en base (import, retrait d'une modalite) n'est
     * PAS un choix : elle retombe en historique, comme `normalize()`.
     */
    public static function isExplicitChoice(mixed $stored): bool
    {
        return self::isValid($stored);
    }

    /**
     * TASK-1628 — ce que la racine sert REELLEMENT, en une seule autorite.
     *
     * `normalize()` dit ce qui est STOCKE ; celle-ci dit ce qui est SERVI, en
     * appliquant le repli historique. Les deux different sur un seul cas, et
     * c'est precisement celui qui faisait mentir l'ecran d'administration :
     * sans choix explicite, un gabarit hero envoie sur la landing de
     * l'Organization — donc sur le Shell quand il est en shell-first — alors
     * que `normalize()` repond « Accueil traditionnel ».
     *
     * `SHELL_WELCOME` est la bonne reponse pour ce repli, et pas un a-peu-pres :
     * cette modalite est definie comme « la landing de l'Organization, ou le
     * mode d'affichage du Shell Welcome decide seul de la forme ». Elle couvre
     * donc aussi bien le Shell que le gabarit hero rendu sans Shell.
     *
     * Volontairement PURE : deux scalaires, aucun modele, aucune lecture de
     * `GuestShellPolicy`. `HomeController` et `AdminRootDestinationController`
     * appellent la meme fonction — c'est ce qui garantit que l'ecran ne peut
     * plus diverger de `GET /`.
     */
    public static function effective(mixed $stored, ?string $homepageTemplate): string
    {
        if (self::isExplicitChoice($stored)) {
            return $stored;
        }

        if ($homepageTemplate === 'bouclepro_hero_v2' || $homepageTemplate === 'artscilab_hero') {
            return self::SHELL_WELCOME;
        }

        return self::DEFAULT;
    }

    public static function normalize(mixed $mode): string
    {
        return self::isValid($mode) ? $mode : self::DEFAULT;
    }

    public static function requiresAuthentication(string $mode): bool
    {
        return in_array($mode, self::AUTHENTICATED_MODES, true);
    }
}
