<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\GuestShell\GuestShellPolicyService;
use App\Support\GuestShell\GuestShellDisplayMode;
use App\Support\Homepage\RootDestination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1628 — le choix explicite du SuperAdmin est SOUVERAIN sur la racine.
 *
 * ## Le defaut mesure
 *
 * Cyril ouvre `/admin/homepage`, choisit « Accueil traditionnel », l'ecran
 * confirme l'enregistrement — et un visiteur deconnecte arrive sur le Shell.
 *
 * La valeur etait pourtant bien en base. Ce qui l'annulait, c'est une chaine
 * de trois surcouches ecrites AVANT que ce choix existe :
 *
 *   GET /  -> HomeController::index()
 *          -> RootDestination::normalize()      NULL et 'homepage' se
 *                                               confondent apres cet appel
 *          -> homepage_template hero            redirige vers organization.home
 *          -> OrganizationLandingController
 *          -> GuestShellPolicy shell_first      rend le Shell
 *
 * Les quatre AUTRES modalites sortaient de `index()` par un `return` avant
 * d'atteindre la surcouche hero : elles n'ont jamais souffert du defaut. Seul
 * `HOMEPAGE`, qui est aussi la valeur de repli de `normalize()`, y tombait.
 *
 * ## Pourquoi la correction ne casse rien
 *
 * La migration TASK-1506 a ajoute `root_destination` en `nullable()`, sans
 * defaut ni backfill, et le SEUL ecrivain du depot est
 * `AdminRootDestinationController::update()`. NULL veut donc dire « personne
 * n'a jamais ouvert cet ecran », et la surcouche hero lui reste acquise au bit
 * pres. C'est ce que garde `test_legacy_*`, sans quoi cette TASK eteindrait
 * silencieusement la landing de toutes les plateformes historiques.
 */
class TASK1628RootDestinationExplicitChoiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Le montage EXACT du defaut rapporte : gabarit hero + Shell shell-first
     * pret a servir. Recette reprise de `TASK1494GuestShellFirstLayoutTest`
     * plutot que devinee — un Shell « pret » a des conditions precises
     * (plafond plateforme, credential d'Organization), et les inventer
     * produirait un Shell eteint, donc un faux vert.
     */
    private function defaultOrganization(?string $destination, string $template = 'bouclepro_hero_v2'): Organization
    {
        config([
            'ai.guest_shell.platform_monthly_ceiling_usd' => 5.0,
            'ai.guest_shell.economic_guard.monthly_budget_usd' => 2.0,
        ]);

        $organization = Organization::factory()->create([
            'slug' => 'main',
            'name' => 'Organisation T1628',
            'is_active' => true,
            'is_default' => true,
            'is_public' => true,
            'locale' => 'fr',
            'homepage_template' => $template,
            'root_destination' => $destination,
        ]);

        app(GuestShellPolicyService::class)->update($organization, [
            'enabled' => true,
            'max_messages' => 5,
            'display_mode' => GuestShellDisplayMode::SHELL_FIRST,
        ]);

        OrganizationAiSetting::create([
            'organization_id' => $organization->id,
            'provider' => 'openrouter',
            'model' => 'openai/gpt-4o-mini',
            'api_key' => 'sk-test-not-a-real-key',
            'is_enabled' => true,
        ]);

        return $organization->fresh();
    }

    private function superAdmin(): User
    {
        $platform = Organization::factory()->create(['slug' => 'plateforme-1628']);

        return User::factory()->create(['is_admin' => true, 'organization_id' => $platform->id]);
    }

    /**
     * La premisse du defaut, mesuree et non supposee : avec ce montage, la
     * landing de l'Organization rend bien le Shell.
     *
     * Sans ce test, `test_explicit_homepage_*` resterait vert si le Shell
     * s'eteignait pour une raison sans rapport (garde economique, credential
     * manquant) — il mesurerait alors l'absence de Shell, pas la souverainete
     * du choix.
     */
    public function test_the_premise_holds_the_landing_really_serves_the_shell(): void
    {
        $organization = $this->defaultOrganization(null);

        $body = $this->get(route('organization.home', $organization))->assertOk()->getContent();

        $this->assertStringContainsString('bpsf-page', $body, 'Premisse fausse : la landing ne rend PAS le Shell, le defaut ne peut pas se reproduire.');
    }

    /**
     * LE defaut. Choix explicite « Accueil traditionnel », gabarit hero et
     * Shell shell-first en face : la racine sert l'Accueil traditionnel, sans
     * redirection.
     *
     * AVANT la correction : 302 vers `/org/main`, puis le Shell.
     */
    public function test_explicit_homepage_is_served_directly_despite_hero_template_and_shell_first(): void
    {
        $this->defaultOrganization(RootDestination::HOMEPAGE);

        $this->get('/')
            ->assertOk()
            ->assertViewIs('home');
    }

    /**
     * La meme chose vue du visiteur : en suivant les redirections, il n'atterrit
     * ni sur la landing de l'Organization ni sur le Shell.
     *
     * `assertViewIs` ci-dessus dit quelle vue rend ; celui-ci dit ou l'on ARRIVE.
     * Les deux ensemble ferment la porte : une redirection vers une autre page
     * rendant `home` passerait le premier, pas le second.
     */
    public function test_explicit_homepage_never_lands_the_guest_on_the_shell(): void
    {
        $organization = $this->defaultOrganization(RootDestination::HOMEPAGE);

        $body = $this->followingRedirects()->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('bpsf-page', $body, 'Le visiteur arrive sur le Shell malgre un choix explicite « Accueil traditionnel ».');
        $this->assertStringNotContainsString('bpgs-form', $body, 'Le composeur du Shell est monte malgre un choix explicite.');
        $this->assertSame(url('/'), url()->current(), 'La racine a redirige ailleurs au lieu de servir l Accueil traditionnel.');
        $this->assertNotSame(route('organization.home', $organization), url()->current());
    }

    /**
     * GARDE D — le comportement HISTORIQUE, la regression la plus couteuse de
     * cette TASK.
     *
     * Une plateforme qui n'a JAMAIS ouvert `/admin/homepage` garde
     * `root_destination` a NULL. Pour elle, rien ne doit bouger : le gabarit
     * hero continue de rediriger vers la landing de l'Organization.
     */
    public function test_legacy_null_keeps_the_hero_template_redirect(): void
    {
        $organization = $this->defaultOrganization(null);

        $this->get('/')->assertRedirect(route('organization.home', $organization));
    }

    /**
     * GARDE D (suite) — l'autre gabarit hero, et une valeur inconnue restee en
     * base.
     *
     * Une chaine qui n'est pas une modalite (import, retrait d'une modalite)
     * n'est PAS un choix explicite : elle retombe en historique. Sans cette
     * mesure, `isExplicitChoice()` pourrait se contenter d'un `!== null` et
     * traiter un residu de base comme une decision de SuperAdmin.
     */
    public function test_legacy_unknown_stored_value_is_not_an_explicit_choice(): void
    {
        $organization = $this->defaultOrganization(null, template: 'artscilab_hero');
        $organization->forceFill(['root_destination' => 'annuaire-des-licornes'])->saveQuietly();

        $this->get('/')->assertRedirect(route('organization.home', $organization));
    }

    /**
     * Sans gabarit hero, l'historique servait deja l'Accueil traditionnel : la
     * correction ne le change pas non plus.
     */
    public function test_legacy_null_without_hero_template_still_serves_the_classic_home(): void
    {
        $this->defaultOrganization(null, template: 'default');

        $this->get('/')->assertOk()->assertViewIs('home');
    }

    /**
     * GARDE A — `SHELL_WELCOME` explicite continue de servir le Shell.
     *
     * C'est la garde symetrique : rendre `HOMEPAGE` souverain ne doit pas
     * rendre le Shell inatteignable depuis la racine. On suit la redirection
     * jusqu'au Shell reellement rendu, plutot que de s'arreter au 302.
     */
    public function test_explicit_shell_welcome_still_serves_the_shell(): void
    {
        $organization = $this->defaultOrganization(RootDestination::SHELL_WELCOME);

        $this->get('/')->assertRedirect(route('organization.home', $organization));

        $body = $this->followingRedirects()->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('bpsf-page', $body, 'Le choix explicite « Shell Welcome » ne sert plus le Shell.');
    }

    /** GARDE B — `BLOG` explicite continue de servir le blog. */
    public function test_explicit_blog_still_serves_the_blog(): void
    {
        $this->defaultOrganization(RootDestination::BLOG);

        $this->get('/')->assertRedirect(route('blog.index'));
        $this->get(route('blog.index'))->assertOk();
    }

    /**
     * GARDE C — les autres destinations ne regressent pas, y compris la garde
     * de privacy TASK-1479 : l'annuaire reste ferme aux anonymes.
     */
    public function test_the_other_explicit_destinations_do_not_regress(): void
    {
        foreach ([RootDestination::LOOPS => 'boucles.index', RootDestination::DIRECTORY => 'members.index'] as $mode => $route) {
            $organization = $this->defaultOrganization($mode);

            $this->get('/')->assertRedirect(route($route));

            if ($mode === RootDestination::DIRECTORY) {
                // TASK-1479 (P0 privacy) : la garde se mesure Organization
                // VIVANTE. Apres `forceDelete()`, `members.index` rend
                // `members.setup-required` en 200 — un vert qui ne dirait plus
                // rien de la fermeture de l'annuaire.
                $this->get(route('members.index'))->assertRedirect(route('login'));
            }

            $organization->forceDelete();
        }
    }

    /**
     * GARDE E — invite ou connecte, la MEME decision.
     *
     * `HomeController::index()` ne consulte jamais `auth()` : la resolution de
     * la racine ne depend pas de l'identite. Ce test l'ecrit, pour qu'ajouter
     * plus tard une branche authentifiee doive le dire.
     */
    public function test_the_decision_is_the_same_for_a_guest_and_a_signed_in_member(): void
    {
        $organization = $this->defaultOrganization(RootDestination::HOMEPAGE);
        $member = User::factory()->create(['organization_id' => $organization->id]);

        $this->get('/')->assertOk()->assertViewIs('home');
        $this->actingAs($member)->get('/')->assertOk()->assertViewIs('home');
    }

    /**
     * L'ecran d'administration ne doit plus annoncer un etat qui diverge de
     * `GET /` : le badge « Actuellement servi » se pose sur « Accueil
     * traditionnel », et c'est bien ce que la racine rend.
     *
     * Pas de moteur de simulation : la valeur stockee etant redevenue
     * souveraine, l'ecran et la racine lisent la MEME chose.
     */
    public function test_the_admin_badge_matches_what_the_root_actually_serves(): void
    {
        $organization = $this->defaultOrganization(RootDestination::HOMEPAGE);

        $screen = $this->actingAs($this->superAdmin())
            ->get(route('admin.homepage'))
            ->assertOk()
            ->getContent();

        // Blade laisse une espace la ou se tenait le `@if` : on mesure le lien
        // entre les deux attributs, pas leur mise en page exacte.
        $this->assertMatchesRegularExpression(
            '/data-root-destination-option="'.RootDestination::HOMEPAGE.'"[^>]*data-current="true"/',
            $screen,
            'Le badge « Actuellement servi » ne se pose pas sur « Accueil traditionnel ».'
        );

        $this->get('/')->assertOk()->assertViewIs('home');

        $this->assertSame(RootDestination::HOMEPAGE, $organization->fresh()->root_destination);
    }

    /**
     * LE defaut d'affichage corrige par MASTER : sans choix explicite, gabarit
     * hero et Shell shell-first, l'ecran annoncait « Accueil traditionnel »
     * ACTUELLEMENT SERVI pendant que le visiteur arrivait sur le Shell.
     *
     * Le badge doit maintenant se poser sur « Shell Welcome », et la case
     * cochee rester sur « Accueil traditionnel » : ce sont deux notions
     * differentes, et les recoller ferait enregistrer un choix jamais fait.
     */
    public function test_the_badge_tells_the_truth_on_the_legacy_hero_plus_shell_first_case(): void
    {
        $this->defaultOrganization(null);

        $screen = $this->actingAs($this->superAdmin())->get(route('admin.homepage'))->assertOk()->getContent();

        // La racine aboutit au Shell : le badge le dit.
        $this->assertMatchesRegularExpression(
            '/data-root-destination-option="'.RootDestination::SHELL_WELCOME.'"[^>]*data-current="true"/',
            $screen,
            'Le badge « Actuellement servi » ne suit pas la destination reellement servie.'
        );

        // Et surtout, il ne ment plus sur l'Accueil traditionnel.
        $this->assertDoesNotMatchRegularExpression(
            '/data-root-destination-option="'.RootDestination::HOMEPAGE.'"[^>]*data-current="true"/',
            $screen,
            'L ecran pretend encore servir l Accueil traditionnel alors que la racine sert le Shell.'
        );

        // La case cochee, elle, reste sur le choix STOCKE (repli HOMEPAGE).
        $this->assertMatchesRegularExpression(
            '/data-root-destination-option="'.RootDestination::HOMEPAGE.'"[^>]*data-selected="true"/',
            $screen,
            'La case cochee doit suivre le choix stocke, pas le repli historique.'
        );

        // Et la racine fait bien ce que le badge annonce.
        $this->get('/')->assertRedirect(route('organization.home', Organization::where('is_default', true)->first()));
    }

    /**
     * La contre-epreuve : avec un choix explicite « Accueil traditionnel », le
     * badge et la case se REJOIGNENT sur la meme carte, et la racine sert bien
     * cette page. C'est le cas nominal apres correction.
     */
    public function test_an_explicit_choice_puts_the_badge_and_the_selection_on_the_same_card(): void
    {
        $this->defaultOrganization(RootDestination::HOMEPAGE);

        $screen = $this->actingAs($this->superAdmin())->get(route('admin.homepage'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/data-root-destination-option="'.RootDestination::HOMEPAGE.'"[^>]*data-current="true"/',
            $screen
        );
        $this->assertMatchesRegularExpression(
            '/data-root-destination-option="'.RootDestination::HOMEPAGE.'"[^>]*data-selected="true"/',
            $screen
        );

        $this->get('/')->assertOk()->assertViewIs('home');
    }

    /**
     * Sans gabarit hero et sans choix, la racine sert bien l'Accueil
     * traditionnel : le badge doit alors s'y poser. Sans ce test, le
     * precedent resterait vert si le badge disparaissait purement et
     * simplement.
     */
    public function test_the_badge_still_points_at_the_classic_home_when_that_is_what_is_served(): void
    {
        $this->defaultOrganization(null, template: 'default');

        $screen = $this->actingAs($this->superAdmin())->get(route('admin.homepage'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/data-root-destination-option="'.RootDestination::HOMEPAGE.'"[^>]*data-current="true"/',
            $screen
        );

        $this->get('/')->assertOk()->assertViewIs('home');
    }

    /**
     * §3 — quand le Shell Welcome est concerne, l'ecran dit son etat, son mode,
     * et renvoie a l'ecran qui le configure. Sans recopier provider, modele,
     * budget, retention ni statistiques : `/admin/homepage` reste un cockpit.
     */
    public function test_the_screen_shows_a_synthetic_shell_state_and_links_to_its_own_screen(): void
    {
        $this->defaultOrganization(null);

        $screen = $this->actingAs($this->superAdmin())->get(route('admin.homepage'))->assertOk()->getContent();

        $this->assertStringContainsString('data-root-destination-shell', $screen, 'Le bloc Shell Welcome est absent alors que la racine y aboutit.');
        $this->assertStringContainsString('data-root-destination-shell-state="active"', $screen);
        $this->assertStringContainsString('data-root-destination-shell-mode="'.GuestShellDisplayMode::SHELL_FIRST.'"', $screen);
        $this->assertStringContainsString(route('admin.shell-welcome-config'), $screen, 'Le lien « Configurer le Shell Welcome » est absent.');
    }

    /**
     * Le cockpit reste un cockpit : aucune reprise de la configuration qui vit
     * sur `shell-welcome-config`. Mesure par les libelles de ces reglages, pas
     * par une impression.
     */
    public function test_the_screen_does_not_duplicate_the_shell_configuration(): void
    {
        $this->defaultOrganization(null);

        $screen = $this->actingAs($this->superAdmin())->get(route('admin.homepage'))->assertOk()->getContent();

        foreach (['guest_shell_retention_days', 'guest_shell_monthly_budget', 'guest_shell_max_messages'] as $key) {
            $label = __('admin.'.$key);

            if ($label === 'admin.'.$key) {
                continue; // cette clef n'existe pas : rien a garder.
            }

            $this->assertStringNotContainsString($label, $screen, $key.' est recopie sur /admin/homepage : le cockpit duplique shell-welcome-config.');
        }
    }

    /**
     * Quand la racine ne passe PAS par le Shell, le bloc n'a rien a dire : il
     * ne s'affiche pas. Un cockpit qui affiche tout n'informe plus.
     */
    public function test_the_shell_block_stays_hidden_when_the_root_does_not_reach_the_shell(): void
    {
        $this->defaultOrganization(RootDestination::HOMEPAGE);

        $screen = $this->actingAs($this->superAdmin())->get(route('admin.homepage'))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-root-destination-shell', $screen);
    }

    /**
     * L'autorite partagee, a la maille de l'unite : c'est elle qui garantit que
     * l'ecran et `GET /` ne peuvent plus diverger.
     */
    public function test_effective_resolves_the_explicit_choice_then_the_historical_fallback(): void
    {
        // Un choix explicite gagne, quel que soit le gabarit.
        foreach (RootDestination::MODES as $mode) {
            $this->assertSame($mode, RootDestination::effective($mode, 'bouclepro_hero_v2'));
            $this->assertSame($mode, RootDestination::effective($mode, null));
        }

        // Sans choix : le gabarit hero envoie sur la landing de l'Organization.
        $this->assertSame(RootDestination::SHELL_WELCOME, RootDestination::effective(null, 'bouclepro_hero_v2'));
        $this->assertSame(RootDestination::SHELL_WELCOME, RootDestination::effective(null, 'artscilab_hero'));
        $this->assertSame(RootDestination::SHELL_WELCOME, RootDestination::effective('valeur-inconnue', 'artscilab_hero'));

        // Sans choix et sans gabarit hero : l'accueil classique.
        $this->assertSame(RootDestination::HOMEPAGE, RootDestination::effective(null, null));
        $this->assertSame(RootDestination::HOMEPAGE, RootDestination::effective(null, 'default'));
        $this->assertSame(RootDestination::HOMEPAGE, RootDestination::effective('valeur-inconnue', 'default'));
    }

    /**
     * §4 — les CTA de l'Accueil traditionnel menent aux BOUCLES.
     *
     * Cette page n'etait quasiment jamais servie avant que le choix redevienne
     * souverain : ses CTA renvoyaient vers l'inscription et une liste publique,
     * pas vers ce que la plateforme fait.
     *
     * Les liens pointent DANS l'Organization par defaut (lecon TASK-1608 : un
     * CTA global sort le visiteur du contexte que la racine vient de resoudre).
     */
    public function test_the_classic_home_cta_point_at_the_loops_of_the_default_organization(): void
    {
        $organization = $this->defaultOrganization(RootDestination::HOMEPAGE);

        $body = $this->get('/')->assertOk()->assertViewIs('home')->getContent();

        $this->assertMatchesRegularExpression(
            '/href="'.preg_quote(route('organization.loops.index', $organization), '/').'"[^>]*data-home-cta="loops-index"/',
            $body,
            'Le CTA « Rejoignez les boucles » ne pointe pas sur les boucles de l Organization par defaut.'
        );
        $this->assertMatchesRegularExpression(
            '/href="'.preg_quote(route('organization.loops.create', $organization), '/').'"[^>]*data-home-cta="loops-create"/',
            $body,
            'Le CTA « Creez vos boucles » ne pointe pas sur la creation de boucle.'
        );
    }

    /**
     * Invite et connecte recoivent les deux MEMES CTA : la page ne se dedouble
     * plus en un parcours d'acquisition et un parcours membre.
     */
    public function test_both_cta_are_identical_for_a_guest_and_a_signed_in_member(): void
    {
        $organization = $this->defaultOrganization(RootDestination::HOMEPAGE);
        $member = User::factory()->create(['organization_id' => $organization->id]);

        $guestBody = $this->get('/')->assertOk()->getContent();
        $memberBody = $this->actingAs($member)->get('/')->assertOk()->getContent();

        foreach ([$guestBody, $memberBody] as $body) {
            $this->assertStringContainsString('data-home-cta="loops-index"', $body);
            $this->assertStringContainsString('data-home-cta="loops-create"', $body);
            $this->assertSame(2, substr_count($body, 'data-home-cta="'), 'L Accueil traditionnel doit porter exactement deux CTA.');
        }
    }

    /**
     * Les ANCIENS CTA ne doivent plus etre des CTA. Mesure sur le bloc lui-meme
     * (les marqueurs `data-home-cta`) et sur les libelles : `boucles.index`
     * reste legitimement present ailleurs dans la page, dans la grille des
     * fonctionnalites — l'y chercher produirait un rouge qui ne dit rien.
     */
    public function test_the_previous_cta_are_gone(): void
    {
        $this->defaultOrganization(RootDestination::HOMEPAGE);

        $body = $this->get('/')->assertOk()->getContent();

        preg_match_all('/<a[^>]*data-home-cta="[^"]*"[^>]*>/', $body, $matches);
        $ctaTags = implode(' ', $matches[0]);

        foreach ([route('register'), route('explorer'), route('boucles.index')] as $goneTarget) {
            $this->assertStringNotContainsString('href="'.$goneTarget.'"', $ctaTags, $goneTarget.' est encore une cible de CTA.');
        }

        $this->assertStringNotContainsString(__('navigation.join_loop'), $body, 'L ancien libelle « Rejoindre la Boucle » est encore rendu.');
    }

    /**
     * §4, la verification demandee : que vit un INVITE qui clique « Creez vos
     * boucles » ?
     *
     * Les deux routes sont derriere `Authenticate` : il rencontre la connexion
     * puis revient. Flux propre, deja gere — ni 403 ni 404 brut, donc rien a
     * contourner. Ce test l'ECRIT, pour qu'un changement de garde doive le dire.
     */
    public function test_a_guest_clicking_the_cta_meets_the_login_screen_not_a_raw_refusal(): void
    {
        $organization = $this->defaultOrganization(RootDestination::HOMEPAGE);

        foreach (['organization.loops.index', 'organization.loops.create'] as $route) {
            $response = $this->get(route($route, $organization));

            // Et c'est le login DE L'ORGANIZATION, pas le login global : le
            // visiteur ne sort pas du contexte que la racine vient de resoudre.
            $response->assertRedirect(route('organization.login', ['organization' => $organization->slug]));
            $this->assertNotContains($response->getStatusCode(), [403, 404], $route.' refuse un invite au lieu de l envoyer se connecter.');
        }

        // Et l'ecran de connexion repond bien, plutot qu'une boucle ou un mur.
        $this->get(route('organization.login', ['organization' => $organization->slug]))->assertOk();
    }

    /**
     * Sans Organization par defaut, `home.blade.php` recoit NULL : les CTA
     * retombent sur les routes globales, qui portent exactement les memes
     * middlewares. Sans ce repli, `route()` leverait sur la page d'accueil.
     */
    public function test_the_cta_fall_back_to_the_global_routes_without_a_default_organization(): void
    {
        // Aucune Organization par defaut, et aucune `main` active : le
        // controleur resout NULL.
        Organization::factory()->create(['slug' => 'sans-defaut-1628', 'is_default' => false, 'is_active' => true]);

        $body = $this->get('/')->assertOk()->assertViewIs('home')->getContent();

        $this->assertStringContainsString('href="'.route('loops.index').'"', $body);
        $this->assertStringContainsString('href="'.route('loops.create').'"', $body);
    }

    /** Les deux libelles existent dans les DEUX locales, et disent deux choses. */
    public function test_the_two_cta_labels_exist_in_both_locales(): void
    {
        foreach (['fr', 'en'] as $locale) {
            $navigation = require base_path('lang/'.$locale.'/navigation.php');

            foreach (['join_loops', 'create_your_loops'] as $key) {
                $this->assertArrayHasKey($key, $navigation, $key.' manque en '.$locale);
                $this->assertNotSame('', trim((string) $navigation[$key]));
            }

            $this->assertNotSame(
                $navigation['join_loops'],
                $navigation['create_your_loops'],
                'Deux CTA differents doivent porter deux libelles differents ('.$locale.')'
            );
        }

        $fr = require base_path('lang/fr/navigation.php');
        $this->assertStringContainsString('é', $fr['create_your_loops'], 'accent manquant : « Créez vos boucles »');
    }

    /**
     * Le predicat lui-meme, a la maille de l'unite : il distingue un choix
     * d'une absence de choix, et ne prend pas un residu pour une decision.
     */
    public function test_is_explicit_choice_separates_a_decision_from_an_absence(): void
    {
        $this->assertFalse(RootDestination::isExplicitChoice(null));
        $this->assertFalse(RootDestination::isExplicitChoice(''));
        $this->assertFalse(RootDestination::isExplicitChoice('annuaire-des-licornes'));

        foreach (RootDestination::MODES as $mode) {
            $this->assertTrue(RootDestination::isExplicitChoice($mode), $mode.' est une modalite valide, donc un choix explicite');
        }
    }
}
