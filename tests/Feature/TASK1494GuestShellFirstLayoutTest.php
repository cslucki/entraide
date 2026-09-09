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

        // La landing classique passe par `x-app-layout` et son pied de page ;
        // aucune de ses marques ne doit apparaitre.
        $this->assertStringNotContainsString('max-w-7xl', $body, 'Le pied de page de la landing classique est rendu derriere le Shell.');
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
     * TASK-1496 — le Shell prend la PAGE UTILE, il n'est pas une carte posee
     * dessus.
     *
     * TASK-1494 avait retire la landing ; la mesure au navigateur a montre
     * qu'il restait une carte : 67 % de large en 1440 avec 240 px de marge de
     * chaque cote, et sur mobile une carte a coins arrondis flottant dans la
     * page. « Une petite carte perdue », et c'etait juste.
     *
     * Ce test verrouille les trois marques de la correction, cote serveur :
     * la largeur utile, le bord a bord mobile, et le fil qui defile sous un
     * composeur fixe. Il ne remplace pas la recette navigateur — c'est elle
     * qui a trouve le defaut —, il empeche la regression.
     */
    public function test_shell_first_takes_the_usable_page_and_is_not_a_card(): void
    {
        $body = $this->get(route('organization.home', $this->organization(GuestShellDisplayMode::SHELL_FIRST)))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('max-width:1100px', $body, 'la largeur utile du fil n\'est pas posee');
        $this->assertStringContainsString('border-radius:0', $body, 'le bord a bord mobile n\'est pas pose');
        $this->assertStringContainsString('.bpgs-log{flex:1;min-height:0;overflow-y:auto}', $body, 'le fil ne defile pas sous un composeur fixe');

        // La mention de confidentialite de PAGE a ete retiree : le Shell porte
        // deja la sienne, et les deux ensemble mangeaient 97 px de page utile.
        $this->assertStringNotContainsString('bpsf-privacy', $body, 'la mention de confidentialite est dupliquee');
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
