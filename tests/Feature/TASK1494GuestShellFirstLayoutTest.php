<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Services\GuestShell\GuestShellPolicyService;
use App\Support\GuestShell\GuestShellDisplayMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1494 — en mode SHELL FIRST, le Shell EST l'experience ; la landing
 * marketing ne se deroule plus derriere.
 *
 * ## Ce contrat en SUPERSEDE un autre, ecrit et assume
 *
 * `guest-shell-overlay.blade.php` documente depuis TASK-1443 :
 *
 * > « shell_first : le Shell est l'experience principale, inclus en HAUT
 * >   (`position` = top), LE CONTENU PUBLIC CLASSIQUE RESTE ENTIER JUSTE EN
 * >   DESSOUS. »
 *
 * Ce que Cyril a mesure — le grand Shell affiche ET la landing complete
 * derriere — etait donc l'intention d'origine. MASTER l'a arbitre autrement :
 * dedoubler l'experience n'est pas la remplacer.
 *
 * Ces tests ecrivent le nouveau contrat, et surtout ils protegent les DEUX
 * autres modes, qui ne changent pas.
 */
class TASK1494GuestShellFirstLayoutTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Le meme montage que `TASK1442GuestShellPublicOverlayTest` : politique via
     * `GuestShellPolicyService`, credential d'Organization, plafond plateforme
     * en config. Reutilise plutot que reinvente — un Shell « pret » a des
     * conditions precises, et les deviner en produirait un faux.
     */
    private function organization(string $mode, bool $enabled = true): Organization
    {
        config([
            'ai.guest_shell.platform_monthly_ceiling_usd' => 5.0,
            'ai.guest_shell.economic_guard.monthly_budget_usd' => 2.0,
        ]);

        $organization = Organization::factory()->create([
            'is_public' => true,
            'is_active' => true,
            'is_default' => true,
            'slug' => 't1494-org',
            'name' => 'Organisation T1494',
            'locale' => 'fr',
        ]);

        app(GuestShellPolicyService::class)->update($organization, [
            'enabled' => $enabled,
            'max_messages' => 5,
            'display_mode' => $mode,
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

    /**
     * Le coeur du contrat : en SHELL FIRST le Shell est monte, et les blocs
     * marketing de la landing classique ne sont PAS rendus.
     *
     * On mesure des reperes structurels — le composeur du Shell, le bloc
     * Ateliers, le pied de page — plutot qu'un texte, parce qu'un libelle
     * change et qu'une structure porte la decision.
     */
    public function test_shell_first_renders_the_shell_without_the_marketing_landing(): void
    {
        $organization = $this->organization(GuestShellDisplayMode::SHELL_FIRST);

        $response = $this->get(route('organization.home', $organization))->assertOk();
        $body = $response->getContent();

        // `bpgs-form` est le composeur du Shell ; `bpsf-page` la racine de la
        // vue Shell First. Deux reperes STRUCTURELS : un libelle change, une
        // structure porte la decision.
        $this->assertStringContainsString('bpsf-page', $body, 'La vue Shell First n\'est pas rendue.');
        $this->assertStringContainsString('bpgs-form', $body, 'Le Shell n\'est pas monte en mode shell_first.');

        // La landing classique a un titre ; la page Shell n'en a aucun. C'est
        // ce repere-la qui dit « rien n'est rendu derriere ». TASK-1500 a
        // retire l'ancien temoin `max-w-7xl` : le pied de page BouclePro, voulu
        // sur desktop, le porte legitimement.
        $this->assertStringNotContainsString('<h1', $body, 'La landing classique est rendue derriere le Shell.');

        // TASK-1500 : sur desktop, la mention « Echange public sans compte »
        // cede la place au pied de page BouclePro — mentions legales,
        // gouvernance, depot, version. Le CSS le masque sous 768 px ; ici on
        // mesure qu'il est bien MONTE, par sa marque structurelle.
        $this->assertStringContainsString('data-footer-mycelium', $body, 'Le pied de page BouclePro n\'est pas monte en shell-first.');
    }

    /**
     * Le chrome minimal exige par MASTER : marque, langue, connexion.
     *
     * L'assertion sur `bpsf-page` n'est pas decorative : sans elle, ce test
     * resterait VERT si la bascule Shell First disparaissait — la landing
     * classique porte elle aussi une marque, un selecteur de langue et un lien
     * de connexion. Mesure faite au sabotage, et c'est ce qui l'a montre.
     */
    public function test_shell_first_keeps_a_minimal_chrome(): void
    {
        $body = $this->get(route('organization.home', $this->organization(GuestShellDisplayMode::SHELL_FIRST)))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('bpsf-page', $body, 'Ce test mesurerait le chrome de la landing classique.');
        $this->assertStringContainsString('BouclePro', $body);
        $this->assertStringContainsString('Organisation T1494', $body);
        $this->assertStringContainsString(route('locale.switch', ['locale' => 'en']), $body);
        $this->assertStringContainsString(route('organization.login', ['organization' => 't1494-org']), $body);
    }

    /**
     * TASK-1496 puis TASK-1497 — le Shell EST la page, pas un widget dedans.
     *
     * TASK-1494 avait retire la landing ; la mesure au navigateur a montre
     * qu'il restait une carte : 67 % de large en 1440 avec 240 px de marge de
     * chaque cote, et sur mobile une carte a coins arrondis flottant dans la
     * page. « Une petite carte perdue », et c'etait juste.
     *
     * TASK-1496 avait rendu la hauteur et le bord a bord mobile, mais gardait
     * `max-width:1100px` sur le conteneur. Recette de Cyril : **76 % de
     * largeur** en 1440, 170 px de marge de chaque cote, rayon 16 px, ombre.
     * Une carte centree, pas un plein ecran.
     *
     * L'erreur etait de placer la contrainte de LECTURE sur le conteneur
     * d'application. Le souci etait juste — une ligne de conversation trop
     * large devient illisible — mais il appartient aux MESSAGES. Le conteneur
     * prend 100 %, `.bpgs-msg` porte la largeur de lecture.
     *
     * Mesure apres TASK-1497 : RATIO_W = 1.000 et RATIO_H = 1.000 aux deux
     * tailles, rayon 0, ombre none, bordure 0, largeur de message 704 px
     * centree en 1440.
     *
     * Ce test verrouille ces marques cote serveur. Il ne remplace pas la
     * recette navigateur — c'est elle qui a trouve le defaut, deux fois —,
     * il empeche la regression.
     */
    public function test_shell_first_is_the_page_and_not_a_widget_inside_it(): void
    {
        $body = $this->get(route('organization.home', $this->organization(GuestShellDisplayMode::SHELL_FIRST)))
            ->assertOk()
            ->getContent();

        // TASK-1497 : le CONTENEUR prend toute la largeur — plus de `max-width`
        // sur le cadre. La contrainte de LECTURE a ete deplacee sur les
        // messages, ou elle appartient.
        $this->assertStringContainsString('max-width:none;margin:0;padding:0}', $body, 'le conteneur du Shell est encore borne en largeur');
        $this->assertStringContainsString('border:0;border-radius:0;box-shadow:none', $body, 'le Shell est encore rendu comme une carte');
        // La largeur de lecture est portee par le CONTENEUR du fil, jamais par
        // chaque bulle : `margin:auto` sur `.bpgs-msg` ecraserait le
        // `align-self:flex-end` des messages visiteur et centrerait tout.
        // Recette de Cyril : les pastilles se retrouvaient au milieu.
        $this->assertStringContainsString('.bpgs-log{width:100%;max-width:min(90vw,1400px);margin-left:auto;margin-right:auto}', $body, 'la lane conversationnelle n\'est pas portee par le fil');
        $this->assertStringNotContainsString('.bpgs-msg,\n  #bp-guest-shell.bpgs-first .bpgs-note', $body, 'la largeur de lecture est repassee sur les bulles');
        $this->assertStringContainsString('.bpgs-log{flex:1;min-height:0;overflow-y:auto}', $body, 'le fil ne defile pas sous un composeur fixe');

        // La mention de confidentialite de PAGE a ete retiree : le Shell porte
        // deja la sienne, et les deux ensemble mangeaient 97 px de page utile.
        $this->assertStringNotContainsString('bpsf-privacy', $body, 'la mention de confidentialite est dupliquee');
    }

    /**
     * WP-C / TASK-1498 — la LANE conversationnelle occupe l'espace desktop.
     *
     * Mesure avec un fil PEUPLE, avant correctif : la lane restait a 44rem =
     * 704 px, soit **49 % du viewport en 1440 et 37 % en 1920**. L'application
     * occupait bien 100 %, mais la conversation laissait un vide considerable.
     * La recette de Cyril rejetait cela, et la mesure lui donnait raison.
     *
     * `min(90vw, 1400px)` tient les trois tailles avec UNE expression : 1440 ->
     * 1296 px (90 %), 1920 -> 1400 px (plafond de lecture), 390 -> pleine
     * largeur via la regle mobile.
     *
     * A 1920 la lane fait 73 % du viewport et non 80-90 % : c'est le plafond
     * que WP-C §2 autorise explicitement (« un cap raisonnable vers 1400 px »),
     * et le critere chiffre — lane >= 1280 — est tenu.
     *
     * Les bulles sont bornees DANS la lane : l'assistant a 1100 px (88 % d'une
     * lane de 1400 ferait 1232 px, au-dela du confort de lecture), le visiteur
     * a 70 % (WP-C §2 ; ses messages sont courts, une pastille large casserait
     * le fil).
     */
    public function test_the_desktop_conversation_lane_uses_the_available_width(): void
    {
        $body = $this->get(route('organization.home', $this->organization(GuestShellDisplayMode::SHELL_FIRST)))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('max-width:min(90vw,1400px)', $body, 'la lane ne suit pas le viewport');
        $this->assertStringContainsString('.bpgs-msg-assistant{max-width:min(88%,1100px)}', $body, 'la bulle assistant n\'est pas plafonnee');
        $this->assertStringContainsString('.bpgs-msg-user{max-width:70%}', $body, 'la bulle visiteur n\'est pas bornee a 70% de la lane');
        $this->assertStringNotContainsString('max-width:44rem', $body, 'la colonne etroite de TASK-1497 est encore la');
    }

    /**
     * UNE SEULE barre de navigation, et le logo a gauche.
     *
     * Recette de Cyril : deux barres se superposaient — celle de la page
     * (« BouclePro / FR EN / Connexion ») et l'entete interne du Shell
     * (« Assistant de BouclePro »). L'entete du Shell est masque en
     * shell_first ; il reste utile en overlay, ou le panneau flotte et a besoin
     * de son propre titre et de son bouton de fermeture.
     */
    public function test_shell_first_has_a_single_top_bar_with_the_logo(): void
    {
        $body = $this->get(route('organization.home', $this->organization(GuestShellDisplayMode::SHELL_FIRST)))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('.bpgs-first .bpgs-head{display:none}', $body, 'l\'entete du Shell fait une seconde barre');
        $this->assertStringContainsString('brand/bouclepro-symbol-64.png', $body, 'le logo manque dans la barre');
        $this->assertStringContainsString('class="bpsf-logo"', $body);
    }

    /**
     * L'AUTRE moitie du contrat : le mode OVERLAY ne change pas d'un pixel.
     * C'est ce test qui empeche cette TASK de devenir une refonte de la
     * page d'accueil.
     */
    public function test_overlay_mode_still_renders_the_full_landing(): void
    {
        $organization = $this->organization(GuestShellDisplayMode::OVERLAY);

        $body = $this->get(route('organization.home', $organization))->assertOk()->getContent();

        $this->assertStringNotContainsString('bpsf-page', $body, 'Le mode overlay a bascule sur la vue Shell First.');
        $this->assertStringContainsString('max-w-7xl', $body, 'Le mode overlay a perdu la landing classique.');
    }

    /**
     * Shell desactive : la landing complete revient, entiere. Une Organization
     * qui coupe son Shell ne doit pas se retrouver sans accueil.
     */
    public function test_a_disabled_shell_still_renders_the_full_landing(): void
    {
        $organization = $this->organization(GuestShellDisplayMode::SHELL_FIRST, enabled: false);

        $body = $this->get(route('organization.home', $organization))->assertOk()->getContent();

        $this->assertStringNotContainsString('bpsf-page', $body, 'Une Organization au Shell coupe bascule sur la vue Shell First.');
        $this->assertStringContainsString('max-w-7xl', $body, 'Une Organization au Shell coupe n\'a plus d\'accueil.');
    }
}
