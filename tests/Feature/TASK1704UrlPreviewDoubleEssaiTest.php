<?php

namespace Tests\Feature;

use App\Services\UrlPreviewService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TASK-1704 correctif 3 — la vignette d'un lien partage.
 *
 * Cyril : « sur les ChatLoop si on partage un lien on a pas la vignette. Je
 * parle ici de liens vers des sites web […] qui ont forcement une vignette,
 * voire un titre et resume. »
 *
 * ## Ce que la capture montrait vraiment
 *
 * Une carte d'apercu RENDUE, portant « WWW.LEMONDE.FR » et le titre « Client
 * Challenge » — le titre de la page anti-robot de Cloudflare. Le mecanisme
 * fonctionnait donc de bout en bout ; c'est la page recuperee qui n'etait pas
 * l'article.
 *
 * Mesure sur l'URL exacte : **HTTP 200**, 3 038 octets, zero balise
 * OpenGraph. Cinq identites honnetes recoivent la meme page ; un navigateur
 * realiste recoit un 402 (paywall) ; un robot d'apercu connu recoit l'article
 * complet, 419 Ko, balises presentes.
 *
 * ## Ce que ce fichier NE mesure pas
 *
 * Le reseau. Aucune garde ici ne joint un site reel : un test qui dependrait
 * de la politique anti-robot d'un editeur rougirait le jour ou celui-ci la
 * change, sans qu'aucun defaut n'existe chez nous. Ce qui est fige, c'est la
 * STRATEGIE — combien d'essais, sous quelle identite, et lequel l'emporte.
 */
class TASK1704UrlPreviewDoubleEssaiTest extends TestCase
{
    private const CIBLE = 'https://exemple-editeur.test/article';

    /** La page anti-robot : un titre, et rien d'autre. */
    private function pageAntiRobot(): string
    {
        return '<html><head><title>Client Challenge</title></head><body>Verification…</body></html>';
    }

    /** L'article : les balises qui font un vrai apercu. */
    private function pageComplete(): string
    {
        return '<html><head>'
            .'<meta property="og:title" content="Un titre d\'article">'
            .'<meta property="og:description" content="Le chapeau de l\'article.">'
            .'<meta property="og:image" content="https://exemple-editeur.test/vignette.jpg">'
            .'</head><body>…</body></html>';
    }

    private function apercu(): ?array
    {
        return app(UrlPreviewService::class)->fetchPreview(self::CIBLE);
    }

    /** Les User-Agents reellement envoyes, dans l'ordre. */
    private function identitesEnvoyees(): array
    {
        return collect(Http::recorded())
            ->map(fn ($paire) => $paire[0]->header('User-Agent')[0] ?? '')
            ->all();
    }

    // ── 1. Le cas courant : un seul essai ───────────────────────────────────

    /**
     * Un site qui repond correctement n'est sollicite qu'UNE fois.
     *
     * C'est la garde qui empeche le correctif de doubler le trafic sortant du
     * produit pour corriger une minorite de cas.
     */
    public function test_un_site_qui_repond_n_est_sollicite_qu_une_fois(): void
    {
        Http::fake([self::CIBLE => Http::response($this->pageComplete(), 200)]);

        $apercu = $this->apercu();

        $this->assertSame('Un titre d\'article', $apercu['title']);
        $this->assertSame('https://exemple-editeur.test/vignette.jpg', $apercu['image']);
        $this->assertCount(1, Http::recorded(), 'Un second essai a eu lieu alors que le premier suffisait.');
    }

    /** Et il est sollicite sous NOTRE identite, qui nous nomme. */
    public function test_le_premier_essai_porte_notre_identite(): void
    {
        Http::fake([self::CIBLE => Http::response($this->pageComplete(), 200)]);

        $this->apercu();

        $identite = $this->identitesEnvoyees()[0];

        $this->assertStringContainsString('BoucleProBot', $identite);
        $this->assertStringNotContainsString('facebookexternalhit', $identite, 'Le premier essai ne doit pas porter le jeton de compatibilite.');
    }

    // ── 2. Le cas de Cyril : la page anti-robot ─────────────────────────────

    /**
     * Un titre nu declenche un second essai, et c'est lui qui l'emporte.
     *
     * Sans ce volet, la carte continuerait d'afficher « Client Challenge ».
     */
    public function test_une_page_anti_robot_declenche_un_second_essai(): void
    {
        $appels = 0;
        Http::fake([self::CIBLE => function () use (&$appels) {
            $appels++;

            return Http::response($appels === 1 ? $this->pageAntiRobot() : $this->pageComplete(), 200);
        }]);

        $apercu = $this->apercu();

        $this->assertSame(2, $appels, 'Le second essai n\'a pas eu lieu.');
        $this->assertSame('Un titre d\'article', $apercu['title'], 'La page anti-robot a ete conservee.');
        $this->assertSame('https://exemple-editeur.test/vignette.jpg', $apercu['image']);
    }

    /**
     * Le second essai nous nomme TOUJOURS en premier.
     *
     * L'ordre a ete mesure, il n'est pas cosmetique : le jeton place en tete
     * se fait refuser par Wikipedia en 403. Et le produit ne doit jamais se
     * presenter uniquement comme le robot d'un tiers.
     */
    public function test_le_second_essai_nous_nomme_toujours_en_premier(): void
    {
        $appels = 0;
        Http::fake([self::CIBLE => function () use (&$appels) {
            $appels++;

            return Http::response($appels === 1 ? $this->pageAntiRobot() : $this->pageComplete(), 200);
        }]);

        $this->apercu();

        $identite = $this->identitesEnvoyees()[1];

        $this->assertStringStartsWith('Mozilla/5.0 (compatible; BoucleProBot/1.0', $identite);
        $this->assertStringContainsString('+https://', $identite, 'L\'identite de repli ne porte pas d\'URL de contact.');
        $this->assertTrue(
            strpos($identite, 'BoucleProBot') < strpos($identite, 'facebookexternalhit'),
            'Le jeton de compatibilite precede notre nom : mesure comme refuse en 403.'
        );
    }

    // ── 3. Quand les deux echouent ──────────────────────────────────────────

    /**
     * Si aucun essai ne rapporte mieux, on garde le PREMIER.
     *
     * Celui-ci a ete obtenu sous notre identite propre. Rendre `null` ferait
     * disparaitre la carte alors qu'un titre et un domaine restent utiles ;
     * preferer le second reviendrait a privilegier le repli sans raison.
     */
    public function test_si_les_deux_echouent_le_premier_est_conserve(): void
    {
        // Les deux reponses doivent DIFFERER, sinon la preference est
        // indiscernable : un sabotage qui inversait l'ordre est reste VERT
        // tant que les deux essais rendaient la meme page.
        $appels = 0;
        Http::fake([self::CIBLE => function () use (&$appels) {
            $appels++;

            return Http::response(
                $appels === 1
                    ? $this->pageAntiRobot()
                    : '<html><head><title>Page du repli</title></head><body></body></html>',
                200
            );
        }]);

        $apercu = $this->apercu();

        $this->assertSame(2, $appels);
        $this->assertSame(
            'Client Challenge',
            $apercu['title'],
            'Le repli a ete prefere au resultat obtenu sous notre identite propre.'
        );
        $this->assertSame('exemple-editeur.test', $apercu['domain']);
    }

    /** Un refus franc des deux cotes ne rend rien, et n'insiste pas. */
    public function test_un_refus_des_deux_cotes_ne_rend_rien(): void
    {
        Http::fake([self::CIBLE => Http::response('', 403)]);

        $this->assertNull($this->apercu());
        $this->assertCount(2, Http::recorded(), 'Le produit a insiste au-dela du second essai.');
    }

    // ── 4. Les gardes SSRF ne sont pas contournees par le repli ─────────────

    /**
     * Le second essai n'est PAS une porte derobee.
     *
     * C'est le risque propre a ce correctif : ajouter un chemin de
     * recuperation, c'est risquer d'en ajouter un qui ne passe pas les memes
     * gardes. Une cible interne doit etre refusee AVANT le moindre appel, et
     * donc n'en declencher aucun — ni le premier, ni le second.
     */
    public function test_une_cible_interne_est_refusee_sans_aucun_appel(): void
    {
        Http::fake();

        foreach (['http://localhost/x', 'http://127.0.0.1/x', 'http://192.168.1.10/x', 'ftp://exemple.test/x'] as $interne) {
            $this->assertNull(
                app(UrlPreviewService::class)->fetchPreview($interne),
                "La cible interne {$interne} n'a pas ete refusee."
            );
        }

        $this->assertCount(0, Http::recorded(), 'Une cible interne a declenche un appel reseau.');
    }

    /**
     * Une redirection vers une cible interne est refusee aux DEUX essais.
     *
     * La re-validation de la chaine de redirection vivait dans le corps qui a
     * ete dedouble. Si elle n'avait ete conservee que dans le premier, le
     * repli deviendrait le chemin par lequel on atteint le reseau interne.
     */
    public function test_une_redirection_interne_est_refusee_aux_deux_essais(): void
    {
        Http::fake([self::CIBLE => Http::response($this->pageComplete(), 200, [
            'X-Guzzle-Redirect-History' => 'http://192.168.1.10/interne',
        ])]);

        $this->assertNull($this->apercu(), 'Une redirection vers le reseau interne a ete suivie.');
        $this->assertCount(2, Http::recorded(), 'Le second essai aurait du etre tente, et refuse lui aussi.');
    }
}
