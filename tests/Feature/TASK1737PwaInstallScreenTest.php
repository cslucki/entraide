<?php

namespace Tests\Feature;

use App\Support\Pwa\PwaInstallContext;
use App\Support\Pwa\PwaInstallPolicy;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Tests\TestCase;

/**
 * TASK-1737 — qui voit l'invitation a installer, et sous quelle forme.
 *
 * ## Ce que ces tests defendent, et pourquoi chacun existe
 *
 * L'ecran d'installation est la seule fonctionnalite de ce depot qui se
 * superpose au contenu de pages PUBLIQUES et INDEXEES. Chaque refus a donc son
 * test, parce qu'un refus oublie produit soit une impasse pour quelqu'un, soit
 * un interstitiel servi a Googlebot.
 *
 * Les trois qui comptent le plus :
 *
 * 1. **Un robot ne voit rien.** `organization.home` est au sitemap en priorite
 *    1.0 : un interstitiel servi a un crawler penaliserait la surface
 *    d'acquisition numero un du produit.
 * 2. **Un lien d'invitation n'est jamais interrompu.** Quelqu'un a recu une
 *    invitation nominative et clique ; interposer un ecran ferait perdre
 *    l'invitation.
 * 3. **Le renoncement est respecte.** Mesure du 31/10/2026 : il ne l'etait pas,
 *    `EncryptCookies` elaguait le cookie pose en JavaScript. Le test de bout en
 *    bout ci-dessous est ce qui l'a revele.
 */
class TASK1737PwaInstallScreenTest extends TestCase
{
    private const UA_TELEPHONE = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Mobile Safari/537.36';

    private const UA_IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';

    private const UA_TABLETTE = 'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';

    private const UA_ORDINATEUR = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

    private const UA_ROBOT = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';

    private function avecPolitique(string $mode): void
    {
        config()->set('pwa.install.policy', $mode);
    }

    // ── La politique ────────────────────────────────────────────────────────

    public function test_the_policy_falls_back_to_the_default_rather_than_switching_off(): void
    {
        // Une variable mal orthographiee ne doit pas eteindre la fonction en
        // silence : personne ne verrait jamais que le reglage ne prend pas.
        $this->assertSame(PwaInstallPolicy::DEFAULT, PwaInstallPolicy::resolve('baner'));
        $this->assertSame(PwaInstallPolicy::DEFAULT, PwaInstallPolicy::resolve(null));
        $this->assertSame(PwaInstallPolicy::DEFAULT, PwaInstallPolicy::resolve(''));
        $this->assertSame(PwaInstallPolicy::DEFAULT, PwaInstallPolicy::resolve(42));

        // Le defaut est le BANDEAU, jamais le plein ecran : une variable absente
        // ne doit pas poser un interstitiel sur une vitrine indexee.
        $this->assertSame(PwaInstallPolicy::BANNER, PwaInstallPolicy::DEFAULT);
        $this->assertFalse(PwaInstallPolicy::showsFullScreenOnEntry(null));
    }

    public function test_the_strict_mode_of_the_cdc_does_not_exist(): void
    {
        // DEC-03 : un drapeau qu'on ne doit jamais activer est du code mort qui
        // porte un risque. Il n'est donc pas ecrit, et ce test l'atteste —
        // sinon quelqu'un le rajouterait « pour plus tard ».
        $this->assertSame(
            [PwaInstallPolicy::OFF, PwaInstallPolicy::BANNER, PwaInstallPolicy::INSTALL_FIRST],
            PwaInstallPolicy::MODES
        );

        foreach (['strict', 'experimental_strict', 'hard_gate'] as $inexistant) {
            $this->assertFalse(PwaInstallPolicy::isValid($inexistant));
            $this->assertSame(PwaInstallPolicy::DEFAULT, PwaInstallPolicy::resolve($inexistant));
        }
    }

    // ── Le kill-switch ──────────────────────────────────────────────────────

    public function test_the_kill_switch_silences_every_surface(): void
    {
        $this->avecPolitique(PwaInstallPolicy::OFF);

        foreach (['/installer', '/about'] as $uri) {
            $this->withHeader('User-Agent', self::UA_TELEPHONE)
                ->get($uri)
                ->assertOk()
                ->assertDontSee('data-bp-pwa-install', false);
        }
    }

    // ── L'appareil ──────────────────────────────────────────────────────────

    public function test_a_crawler_never_receives_the_install_markup(): void
    {
        $this->avecPolitique(PwaInstallPolicy::INSTALL_FIRST);

        // Le cas qui protege le classement de la surface d'acquisition.
        $this->withHeader('User-Agent', self::UA_ROBOT)
            ->get('/about')
            ->assertOk()
            ->assertDontSee('data-bp-pwa-install', false);
    }

    public function test_a_desktop_visitor_never_receives_the_install_markup(): void
    {
        $this->avecPolitique(PwaInstallPolicy::INSTALL_FIRST);

        $this->withHeader('User-Agent', self::UA_ORDINATEUR)
            ->get('/about')
            ->assertOk()
            ->assertDontSee('data-bp-pwa-install', false);
    }

    public function test_phones_and_tablets_both_count_as_mobile(): void
    {
        // Une tablette a un ecran d'accueil elle aussi.
        $this->assertTrue(PwaInstallContext::estUnMobile(self::UA_TELEPHONE));
        $this->assertTrue(PwaInstallContext::estUnMobile(self::UA_IPHONE));
        $this->assertTrue(PwaInstallContext::estUnMobile(self::UA_TABLETTE));

        $this->assertFalse(PwaInstallContext::estUnMobile(self::UA_ORDINATEUR));
        $this->assertFalse(PwaInstallContext::estUnMobile(self::UA_ROBOT));
        $this->assertFalse(PwaInstallContext::estUnMobile('curl/8.5.0'));
        $this->assertFalse(PwaInstallContext::estUnMobile(null));
    }

    // ── La forme, selon la politique ────────────────────────────────────────

    public function test_the_default_policy_serves_a_non_blocking_banner(): void
    {
        $this->avecPolitique(PwaInstallPolicy::BANNER);

        $reponse = $this->withHeader('User-Agent', self::UA_TELEPHONE)->get('/about');

        $reponse->assertOk()
            ->assertSee('data-bp-pwa-install="banner"', false)
            ->assertDontSee('data-bp-pwa-install="full"', false);

        // Le bandeau MENE a l'ecran dedie, et c'est un LIEN : il fonctionne
        // sans JavaScript.
        $reponse->assertSee('href="'.route('pwa.install').'"', false);
    }

    public function test_install_first_promotes_the_banner_to_a_full_screen(): void
    {
        $this->avecPolitique(PwaInstallPolicy::INSTALL_FIRST);

        $this->withHeader('User-Agent', self::UA_TELEPHONE)
            ->get('/about')
            ->assertOk()
            ->assertSee('data-bp-pwa-install="full"', false)
            ->assertDontSee('data-bp-pwa-install="banner"', false);
    }

    // ── La route dediee ─────────────────────────────────────────────────────

    public function test_the_dedicated_route_is_public_and_always_full_screen(): void
    {
        // Meme en politique `banner`, et meme depuis un ordinateur : on arrive
        // ici en l'ayant demande, y compris pour lire les instructions.
        $this->avecPolitique(PwaInstallPolicy::BANNER);

        $this->withHeader('User-Agent', self::UA_ORDINATEUR)
            ->get('/installer')
            ->assertOk()
            ->assertSee('data-bp-pwa-install="full"', false);
    }

    public function test_the_dedicated_route_asks_search_engines_to_stay_away(): void
    {
        // Ce n'est pas du contenu, c'est un geste : rien a indexer.
        $this->get('/installer')
            ->assertOk()
            ->assertSee('name="robots" content="noindex, follow"', false);
    }

    public function test_the_dedicated_route_still_says_something_from_the_installed_app(): void
    {
        // L'ecran est masque en `display-mode: standalone`. Sans repli, la page
        // serait VIDE pour qui l'ouvre depuis l'application installee.
        $this->get('/installer')
            ->assertOk()
            ->assertSee('bp-installer-fallback', false)
            ->assertSee(__('pwa.installing_body'), false);
    }

    // ── Les parcours qu'on n'interrompt pas ─────────────────────────────────

    public function test_authentication_journeys_are_never_interrupted(): void
    {
        $this->avecPolitique(PwaInstallPolicy::INSTALL_FIRST);

        foreach (['/login', '/register'] as $uri) {
            $this->withHeader('User-Agent', self::UA_TELEPHONE)
                ->get($uri)
                ->assertOk()
                ->assertDontSee('data-bp-pwa-install', false);
        }
    }

    public function test_every_excluded_route_name_actually_exists(): void
    {
        // Une exclusion qui designe une route disparue ne protege plus rien, et
        // personne ne le verrait. `logout` est en POST : on ne l'exige pas en
        // GET, mais son nom doit exister.
        foreach (PwaInstallContext::ROUTES_EXCLUES as $nom) {
            $this->assertTrue(
                \Illuminate\Support\Facades\Route::has($nom),
                "La route exclue [{$nom}] n'existe plus : l'exclusion ne protege plus rien."
            );
        }
    }

    public function test_an_unnamed_route_is_excluded(): void
    {
        // Les gabarits d'erreur passent par la : une erreur serveur n'est pas
        // un moment d'acquisition.
        $this->assertTrue(PwaInstallContext::routeEstExclue(null));
    }

    public function test_platform_administration_is_excluded_by_prefix(): void
    {
        $this->assertTrue(PwaInstallContext::routeEstExclue('admin.users.index'));
        $this->assertTrue(PwaInstallContext::routeEstExclue('admin.notifications-cockpit'));
        $this->assertFalse(PwaInstallContext::routeEstExclue('organization.home'));
    }

    // ── Le renoncement ──────────────────────────────────────────────────────

    /**
     * LE test qui a revele le defaut.
     *
     * Il part du cookie TEL QUE LE NAVIGATEUR L'ENVOIE — en clair, parce que
     * c'est le JavaScript qui le pose. Un test qui aurait utilise un cookie
     * chiffre par Laravel serait passe au vert sur un code casse.
     */
    public function test_a_dismissal_cookie_stops_the_invitation_end_to_end(): void
    {
        $this->avecPolitique(PwaInstallPolicy::INSTALL_FIRST);

        $this->withHeader('User-Agent', self::UA_TELEPHONE)
            ->withUnencryptedCookie(PwaInstallContext::COOKIE_RENONCEMENT, '1')
            ->get('/about')
            ->assertOk()
            ->assertDontSee('data-bp-pwa-install', false);
    }

    /**
     * Et la cause racine, nommee.
     *
     * Sans cette exemption, `EncryptCookies` met le cookie a `null` et le test
     * de bout en bout ci-dessus redevient rouge. Le declarer ici evite d'avoir
     * a re-diagnostiquer la meme chose dans six mois.
     */
    public function test_the_dismissal_cookie_is_exempt_from_encryption(): void
    {
        $this->assertTrue(
            (new EncryptCookies(app('encrypter')))->isDisabled(PwaInstallContext::COOKIE_RENONCEMENT),
            'Le cookie de renoncement n\'est pas exempte de `EncryptCookies` : pose en '
            .'JavaScript il n\'est pas chiffre, donc il sera elague et le renoncement '
            .'ne prendra jamais. Declarer l\'exemption dans `bootstrap/app.php`.'
        );
    }

    // ── Les promesses tenues ────────────────────────────────────────────────

    /**
     * CDC section 3.2 : « Ne pas promettre la reception de Push si elle n'est
     * pas encore livree. » La meme regle vaut pour le PARTAGE, qui attend la
     * TASK suivante.
     *
     * Ce test est une garde de PRODUIT, pas de code : il empechera d'ajouter un
     * benefice en avance sur sa capacite.
     */
    public function test_the_screen_promises_nothing_that_is_not_delivered_yet(): void
    {
        $this->avecPolitique(PwaInstallPolicy::INSTALL_FIRST);

        $html = $this->withHeader('User-Agent', self::UA_TELEPHONE)
            ->get('/installer')
            ->assertOk()
            ->getContent();

        foreach (['notification', 'Notification', 'push', 'Push', 'partage', 'Partage'] as $promesse) {
            $this->assertStringNotContainsString(
                $promesse,
                $html,
                "L'ecran d'installation parle de [{$promesse}], capacite non encore livree. "
                .'Les benefices doivent etre vrais AUJOURD\'HUI (CDC 3.2).'
            );
        }
    }

    /**
     * Aucun bouton factice — CDC section 3.3.
     *
     * Le bouton existe dans le balisage, mais il est `display: none` tant que
     * `beforeinstallprompt` n'a pas ete capture. Ce sont les instructions
     * manuelles qui tiennent l'ecran par defaut.
     */
    public function test_the_install_button_stays_hidden_until_a_real_prompt_exists(): void
    {
        $this->avecPolitique(PwaInstallPolicy::INSTALL_FIRST);

        $html = $this->withHeader('User-Agent', self::UA_TELEPHONE)
            ->get('/installer')
            ->assertOk()
            ->getContent();

        // L'etat initial ne porte PAS `data-bp-prompt`.
        //
        // La mesure porte sur la BALISE, pas sur la page : la chaine
        // `data-bp-prompt="ready"` apparait legitimement dans le selecteur CSS
        // qui revele le bouton et dans le script qui pose l'attribut. Un
        // premier jet cherchait la chaine dans tout le HTML et rougissait sur
        // du code juste — l'assertion etait fausse, pas le composant.
        preg_match('/<div class="bp-pwa bp-pwa--full"[^>]*>/', $html, $balise);

        $this->assertNotEmpty($balise, 'La balise racine de l\'ecran plein format est introuvable.');
        $this->assertStringNotContainsString(
            'data-bp-prompt',
            $balise[0],
            'La balise racine porte deja `data-bp-prompt` au rendu serveur : le bouton '
            .'d\'installation serait visible avant qu\'aucun prompt n\'existe.'
        );

        // La regle qui le masque, et celle qui le revele, existent toutes deux.
        $this->assertStringContainsString('.bp-pwa__cta {', $html);
        $this->assertStringContainsString('[data-bp-prompt="ready"] .bp-pwa__cta { display: block; }', $html);

        // `prompt()` n'est appele que derriere un clic ET un evenement capture.
        $this->assertStringContainsString("geste === 'install' && differe", $html);
    }

    /**
     * Le masquage dans l'application installee est en CSS, pas en JavaScript.
     *
     * Un masquage par script aurait affiche l'ecran puis l'aurait retire sous
     * les yeux de la personne — le flash que le CDC 3.3 interdit.
     */
    public function test_an_installed_app_is_shielded_by_css_not_by_script(): void
    {
        $this->avecPolitique(PwaInstallPolicy::INSTALL_FIRST);

        $html = $this->withHeader('User-Agent', self::UA_TELEPHONE)
            ->get('/installer')
            ->assertOk()
            ->getContent();

        // `display: none` par defaut, et SEUL `display-mode: browser` allume.
        $this->assertStringContainsString('@media all and (display-mode: browser)', $html);
        $this->assertStringContainsString('html[data-bp-standalone] .bp-pwa { display: none !important; }', $html);
    }

    /**
     * Le bandeau ne couvre pas la barre de navigation basse.
     *
     * Les deux barres du depot (`mobile-bottom-nav`, `discovery-bottom-nav`)
     * sont `fixed bottom-0` en `z-index: 40`, et le bandeau est bien au-dessus :
     * sans decalage, la navigation de la page deviendrait inatteignable.
     */
    public function test_the_banner_offsets_itself_above_an_existing_bottom_bar(): void
    {
        $this->avecPolitique(PwaInstallPolicy::BANNER);

        $html = $this->withHeader('User-Agent', self::UA_TELEPHONE)
            ->get('/about')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('bottom: var(--bp-pwa-bottom, 0px);', $html);

        // La mesure passe par le selecteur que le depot declare STABLE sur les
        // DEUX barres, plutot que par une connaissance page par page.
        $this->assertStringContainsString("querySelector('[data-bp-mobile-nav]')", $html);
    }

    /** Accessibilite : l'ecran plein format est un dialogue, et il se ferme. */
    public function test_the_full_screen_is_an_accessible_dialog(): void
    {
        $this->avecPolitique(PwaInstallPolicy::INSTALL_FIRST);

        $html = $this->withHeader('User-Agent', self::UA_TELEPHONE)
            ->get('/installer')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('role="dialog"', $html);
        $this->assertStringContainsString('aria-modal="true"', $html);
        $this->assertStringContainsString('aria-labelledby="bp-pwa-title"', $html);

        // `Echap` ferme, et la tabulation ne sort pas de l'ecran tant qu'il
        // couvre la page.
        $this->assertStringContainsString("evenement.key === 'Escape'", $html);
        $this->assertStringContainsString("evenement.key !== 'Tab'", $html);

        // Le repli reste atteignable, et n'est jamais dissimule (CDC 3.2).
        // Sur la page dediee son libelle est « Retour au site » — voir le test
        // suivant pour la raison.
        $this->assertStringContainsString(__('pwa.back_to_site'), $html);
    }

    /**
     * Sur `/installer`, l'action secondaire est un RETOUR, pas un renoncement.
     *
     * Defaut trouve en recette par Cyril le 31/10/2026, et il avait DEUX
     * symptomes pour une seule cause :
     *
     * 1. **Ecran noir.** Sur la page dediee, la superposition EST la page : il
     *    n'y a rien dessous. La retirer laissait un corps vide sur fond
     *    `#0b0b10`.
     * 2. **Le bandeau disparaissait de tout le site.** Le geste posait un
     *    cookie de 30 jours — alors que la personne avait touche « Installer »
     *    dans le bandeau, regarde la page, et voulait simplement revenir. Une
     *    curiosite etait lue comme un refus.
     *
     * Le refus garde sa porte : la croix du bandeau, elle, renonce bien.
     */
    public function test_the_dedicated_page_offers_a_return_not_a_dismissal(): void
    {
        $html = $this->withHeader('User-Agent', self::UA_TELEPHONE)
            ->get('/installer')
            ->assertOk()
            ->getContent();

        // Un vrai LIEN vers l'accueil : il fonctionne sans JavaScript.
        $this->assertMatchesRegularExpression(
            '/<a class="bp-pwa__dismiss" href="'.preg_quote(url('/'), '/').'\/?">\s*'
            .preg_quote(__('pwa.back_to_site'), '/').'/s',
            $html,
            'La page dediee doit offrir un lien de RETOUR vers l\'accueil.'
        );

        // Et surtout : AUCUN geste de renoncement sur cette page.
        $this->assertStringNotContainsString(
            'data-bp-pwa-action="dismiss"',
            $html,
            'La page dediee ne doit poser aucun renoncement : en repartir ne refuse rien.'
        );

        // Le script le sait aussi, sinon `Echap` reproduirait l'ecran noir.
        $this->assertStringContainsString('data-bp-pwa-dedicated="1"', $html);
        $this->assertStringContainsString('if (pageDediee) {', $html);
    }

    /**
     * A l'inverse, superpose a une VRAIE page, le geste reste un renoncement.
     *
     * Il y a quelque chose a lire dessous, et la personne demande a y acceder :
     * la retirer en place est le bon comportement, et le cookie aussi.
     */
    public function test_an_overlay_over_real_content_still_dismisses(): void
    {
        $this->avecPolitique(PwaInstallPolicy::INSTALL_FIRST);

        $html = $this->withHeader('User-Agent', self::UA_TELEPHONE)
            ->get('/about')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-bp-pwa-action="dismiss"', $html);
        $this->assertStringContainsString(__('pwa.continue_in_browser'), $html);

        // La mesure porte sur la BALISE racine, pas sur la page : le script
        // mentionne `data-bp-pwa-dedicated` de toute facon, puisqu'il le lit.
        // Chercher la chaine dans tout le HTML rougirait sur du code juste —
        // c'est la deuxieme fois sur cette TASK que la nuance compte.
        preg_match('/<div class="bp-pwa bp-pwa--full"[^>]*>/', $html, $balise);

        $this->assertNotEmpty($balise);
        $this->assertStringNotContainsString(
            'data-bp-pwa-dedicated',
            $balise[0],
            'Une superposition posee sur une vraie page ne doit pas se croire sur la page dediee.'
        );
    }

    /** Les instructions iOS existent, et aucune n'est servie a l'aveugle. */
    public function test_each_platform_gets_its_own_instructions(): void
    {
        $this->avecPolitique(PwaInstallPolicy::INSTALL_FIRST);

        $html = $this->withHeader('User-Agent', self::UA_IPHONE)
            ->get('/installer')
            ->assertOk()
            ->getContent();

        // Les trois blocs sont rendus `hidden` ; le script revele CELUI qui
        // correspond a l'appareil reellement observe. Aucune detection de
        // plateforme cote serveur : le serveur decide d'emettre, pas de quoi.
        foreach (['ios-safari', 'ios-other', 'generic'] as $plateforme) {
            $this->assertStringContainsString('data-bp-pwa-platform="'.$plateforme.'" hidden', $html);
        }

        // Le cas iOS hors Safari est traite : beaucoup de gens utilisent Chrome
        // sur iPhone, et le menu de Safari n'y existe pas.
        $this->assertStringContainsString('CriOS', $html);
        $this->assertStringContainsString(__('pwa.ios_other_browser.title'), $html);
    }
}
