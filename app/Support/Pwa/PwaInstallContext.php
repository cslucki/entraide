<?php

namespace App\Support\Pwa;

use App\Support\Devices\DeviceKind;
use Illuminate\Http\Request;

/**
 * TASK-1737 — cette requete doit-elle voir l'invitation a installer, et sous
 * quelle forme ?
 *
 * ## Deux couches, et c'est ce qui leve la tension du CDC
 *
 * Le CDC demande a la fois une detection progressive et de **ne pas fonder le
 * verrouillage sur le seul user-agent** (section 3.5). Les deux tiennent
 * ensemble parce que la decision est coupee en deux :
 *
 * - **ICI, cote serveur** : faut-il EMETTRE le balisage ? L'user-agent n'y sert
 *   qu'a s'abstenir — pour un robot, pour un ordinateur — jamais a verrouiller
 *   quoi que ce soit. Un faux negatif ne prive personne de contenu.
 * - **En CSS, dans le composant** : le balisage emis est-il VISIBLE ?
 *   `@media (display-mode: browser)` l'affiche, `@media (display-mode:
 *   standalone)` le masque. Zero JavaScript, zero flash : une PWA installee ne
 *   voit jamais l'ecran, pas meme une fraction de seconde.
 *
 * Emettre cote serveur et masquer en CSS serait suffisant. Mais s'abstenir
 * d'emettre quand on sait deja que c'est inutile evite le poids, et surtout
 * evite d'envoyer un interstitiel a un crawler.
 *
 * ## Pourquoi le cookie est lu ICI et pas en JavaScript
 *
 * « Continuer dans le navigateur » pose `bp_pwa_dismissed`. Le serveur le lit
 * et cesse d'emettre : ni flash, ni decalage de mise en page, ni travail
 * inutile. Un `localStorage` lu apres le rendu aurait affiche l'ecran puis
 * l'aurait retire sous les yeux de la personne — et il leve une exception en
 * navigation privee.
 *
 * ## La collision que DEC-02bis laissait ouverte, et comment elle est tranchee
 *
 * « Plein ecran sur les entrees applicatives, bandeau sur les vitrines
 * publiques indexees » suppose que les deux soient distinctes. Mesure du
 * 31/10/2026 : elles ne le sont PAS. `GET /` rend un 302 vers
 * `/org/main` — la racine de BouclePro **est** la vitrine, et cette vitrine est
 * declaree au sitemap en priorite 1.0.
 *
 * Aucune heuristique ne distingue honnetement « arrive par la racine » de
 * « arrive depuis une recherche Google » : le `Referer` n'est pas fiable, et
 * servir deux contenus selon sa valeur serait du cloaking.
 *
 * La tranche est donc produite, pas technique :
 *
 * - le DEFAUT (`banner`) sert un bandeau partout — tres visible, non bloquant,
 *   le contenu reste lisible et scrollable, aucun risque de classement ;
 * - `/installer` est plein format PAR NATURE : c'est sa raison d'etre, et c'est
 *   la que le bandeau mene ;
 * - `install_first`, qui se DECLARE, promeut le bandeau en plein ecran sur les
 *   pages d'entree. C'est le mode de la premiere recette demandee par le CDC
 *   (section 3.6), et il reste mesurable avant d'etre adopte.
 *
 * Deux gestes au lieu d'un, pour zero risque d'acquisition — et le mode
 * agressif reste a un reglage de distance.
 */
final class PwaInstallContext
{
    /** Rien n'est emis. */
    public const NONE = 'none';

    /** Bandeau bas, non bloquant. */
    public const BANNER = 'banner';

    /** Ecran plein format. */
    public const FULL = 'full';

    /** La route de l'ecran dedie : plein format par nature. */
    public const ROUTE_DEDIEE = 'pwa.install';

    /**
     * Le cookie de renoncement. Une CONSTANTE, pas un reglage — et la raison
     * a coute une mesure.
     *
     * Ce nom doit etre connu de TROIS endroits a la fois :
     *
     *   1. le JavaScript qui le pose (`<x-pwa-install />`) ;
     *   2. ce contexte, qui le lit ;
     *   3. **`bootstrap/app.php`, qui l'EXEMPTE de `EncryptCookies`.**
     *
     * Le troisieme est celui qu'on oublie, et son echec est silencieux.
     * Mesure du 31/10/2026 : avec le cookie pose, le bandeau revenait quand
     * meme. Cause — `EncryptCookies::decrypt()` attrape la `DecryptException`
     * et fait `$request->cookies->set($key, null)`. Un cookie pose en
     * JavaScript n'est pas chiffre : il etait donc elague avant d'arriver ici,
     * et `$request->cookie()` rendait `null`. Rien ne plantait ; le
     * renoncement ne prenait simplement jamais.
     *
     * Un nom en configuration aurait aggrave le piege : `bootstrap/app.php`
     * s'execute AVANT le chargement de la configuration, donc l'exemption
     * n'aurait pas pu la lire et les deux auraient diverge en silence. Une
     * constante lue par les trois endroits ne peut pas diverger.
     *
     * Le cookie ne porte qu'un `1` : aucun secret, donc rien a chiffrer.
     * `XSRF-TOKEN` est exempte par Laravel pour exactement la meme raison.
     */
    public const COOKIE_RENONCEMENT = 'bp_pwa_dismissed';

    /**
     * Les routes qui ne doivent JAMAIS rien voir.
     *
     * Liste NOMMEE, relevee sur `route:list` le 31/10/2026, jamais un motif
     * large : chacune porte un parcours qu'une invitation a installer
     * interromprait au pire moment.
     *
     * CDC section 3.5 : « URLs de connexion, deconnexion, verification email,
     * reinitialisation, invitations, acceptation d'invitation, changement de
     * compte et liens directs sensibles. »
     *
     * @var list<string>
     */
    public const ROUTES_EXCLUES = [
        // Connexion et inscription, dans les deux formes — bornee a une
        // Organization et universelle.
        'login',
        'organization.login',
        'register',
        'organization.register',
        'logout',

        // Mot de passe : demander, reinitialiser, confirmer, et le cas
        // particulier du compte cree par invitation qui doit poser le sien.
        'password.request',
        'password.reset',
        'password.confirm',
        'invitation.password.create',

        // Verification d'email : la personne est dans un couloir, on ne la
        // detourne pas.
        'verification.notice',
        'verification.verify',

        // LIENS ENTRANTS. Les plus importants de cette liste : quelqu'un a
        // recu une invitation nominative et clique. Interposer un ecran
        // d'installation ici ferait perdre l'invitation.
        'loop-invitations.show',
        'organization-invitations.show',

        // Identite : bascule entre comptes freres et confirmation d'association.
        'account.switch',
        'my-organizations.confirm',
    ];

    /**
     * Les prefixes de noms de routes exclus.
     *
     * `admin.*` : une surface d'administration plateforme n'est pas un lieu
     * d'acquisition. On n'invite pas un super-administrateur a installer
     * l'application pendant qu'il arbitre un signalement.
     *
     * @var list<string>
     */
    public const PREFIXES_EXCLUS = ['admin.'];

    public function __construct(private readonly string $mode) {}

    /**
     * Ce que cette requete doit voir.
     *
     * L'ordre des refus est volontaire : du moins cher au plus cher, et du plus
     * general au plus particulier.
     */
    public static function for(Request $request): self
    {
        $politique = PwaInstallPolicy::current();

        // 1 — kill-switch, ou mode inconnu retombe sur le defaut.
        if (! PwaInstallPolicy::isVisible($politique)) {
            return new self(self::NONE);
        }

        // 2 — seules les navigations comptent. Un POST, une requete Livewire,
        //     un appel attendant du JSON : rien a y interposer.
        if (! $request->isMethod('GET') || $request->expectsJson() || $request->ajax()) {
            return new self(self::NONE);
        }

        // 3 — la route dediee est plein format, quelle que soit la politique
        //     (hors `off`, deja traite) et quel que soit l'appareil : on y
        //     arrive en l'ayant demande, y compris depuis un ordinateur pour
        //     lire les instructions.
        //
        //     ELLE PASSE AVANT LE RENONCEMENT, et l'ordre inverse etait un
        //     defaut (recette Cyril, 31/10/2026, seconde porte du meme defaut
        //     que la veille). Un « plus tard » touche une fois supprimait
        //     `/installer` pendant trente jours : la page dont la superposition
        //     EST le contenu rendait alors un ecran vide, et la personne qui
        //     avait change d'avis n'avait plus aucun chemin vers l'installation
        //     — sauf effacer les donnees du site, ce que personne ne devine.
        //
        //     Un renoncement porte sur une INVITATION, pas sur une DESTINATION.
        //     Taper l'adresse, suivre un signet ou revenir par un lien est une
        //     demande explicite, et elle est plus recente que le refus : elle
        //     gouverne. Le cookie reste pose — on ne le consomme pas sur un
        //     GET — donc le bandeau reste eteint ailleurs, ce qui est bien ce
        //     que la personne avait demande.
        if ($request->route()?->getName() === self::ROUTE_DEDIEE) {
            return new self(self::FULL);
        }

        // 4 — la personne a dit « plus tard ». Lu avant le reste du travail,
        //     mais apres la route dediee pour la raison ci-dessus.
        if ($request->cookie(self::COOKIE_RENONCEMENT) !== null) {
            return new self(self::NONE);
        }

        // 5 — les parcours qu'on n'interrompt pas.
        if (self::routeEstExclue($request->route()?->getName())) {
            return new self(self::NONE);
        }

        // 6 — l'appareil. Un robot et un ordinateur ne voient rien : le premier
        //     parce que le contenu public doit rester indexable sans
        //     interstitiel, le second parce qu'il n'a pas d'ecran d'accueil a
        //     garnir. `DeviceKind` (TASK-1694) examine les outils et les robots
        //     EN PREMIER, avant toute signature de systeme.
        if (! self::estUnMobile($request->userAgent())) {
            return new self(self::NONE);
        }

        return new self(
            PwaInstallPolicy::showsFullScreenOnEntry($politique) ? self::FULL : self::BANNER
        );
    }

    public static function routeEstExclue(?string $nom): bool
    {
        if ($nom === null) {
            // Une page sans route nommee : les gabarits d'erreur passent par
            // la. On s'abstient — une erreur serveur n'est pas le moment.
            return true;
        }

        if (in_array($nom, self::ROUTES_EXCLUES, true)) {
            return true;
        }

        foreach (self::PREFIXES_EXCLUS as $prefixe) {
            if (str_starts_with($nom, $prefixe)) {
                return true;
            }
        }

        return false;
    }

    /** Telephone ou tablette : les deux ont un ecran d'accueil. */
    public static function estUnMobile(?string $userAgent): bool
    {
        return in_array(
            DeviceKind::fromUserAgent($userAgent),
            [DeviceKind::Phone, DeviceKind::Tablet],
            true
        );
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function emetQuelqueChose(): bool
    {
        return $this->mode !== self::NONE;
    }

    public function estPleinEcran(): bool
    {
        return $this->mode === self::FULL;
    }

    public function estBandeau(): bool
    {
        return $this->mode === self::BANNER;
    }

    /**
     * Sommes-nous SUR `/installer`, ou superposes a une vraie page ?
     *
     * La distinction gouverne le sens de l'action secondaire, et elle a ete
     * payee par un defaut en recette (Cyril, 31/10/2026).
     *
     * Superpose a une page, « Continuer dans le navigateur » veut dire :
     * *retire cet ecran, je veux lire ce qu'il y a dessous* — et c'est un
     * renoncement, qui pose le cookie.
     *
     * Sur `/installer`, la superposition **EST** la page : il n'y a rien
     * dessous. La retirer laissait un ecran NOIR — le corps de la page n'a pas
     * d'autre contenu visible en mode navigateur. Et le renoncement de 30 jours
     * etait faux : quelqu'un qui a touche « Installer » dans le bandeau, puis
     * revient, n'a rien refuse du tout. Le bandeau disparaissait pourtant de
     * tout le site.
     *
     * Sur la page dediee, l'action secondaire est donc un RETOUR, pas un
     * renoncement : un lien vers l'accueil, qui ne pose aucun cookie. Le refus
     * garde sa porte — la croix du bandeau.
     */
    public function estPageDediee(): bool
    {
        return $this->estPleinEcran()
            && request()->route()?->getName() === self::ROUTE_DEDIEE;
    }
}
