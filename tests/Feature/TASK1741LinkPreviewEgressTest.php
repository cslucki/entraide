<?php

namespace Tests\Feature;

use App\Services\UrlPreviewService;
use App\Support\Http\PublicHostPolicy;
use App\Support\Ops\SortieDeRecette;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * TASK-1741 — les apercus de lien peuvent sortir, et RIEN d'autre.
 *
 * Le garde `ExternalEgressGuard` n'est pas arme sur le banc (il s'arme sur le
 * jumeau de production, pas sur sqlite), et l'armer ici polluerait les 849
 * autres classes : `arm()` pose un drapeau STATIQUE et un middleware global
 * qui survivraient au test.
 *
 * On mesure donc la DECISION la ou elle est prise — `SortieDeRecette` — plutot
 * qu'une mise en scene du garde. C'est la meme regle, au meme endroit, et
 * elle est verifiable condition par condition.
 */
class TASK1741LinkPreviewEgressTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // L'environnement de recette, pose explicitement : la premiere des
        // quatre conditions.
        app()['env'] = 'local';
        config()->set('ops.sorties_de_recette.apercus_de_lien', true);
    }

    // ── Les quatre conditions, une par une ──────────────────────────────────

    public function test_un_apercu_vers_un_hote_public_est_autorise(): void
    {
        $this->assertTrue(SortieDeRecette::autorisee('https', 'fr.wikipedia.org', [
            SortieDeRecette::OPTION_APERCU_LIEN => true,
        ]));
    }

    public function test_une_requete_HTTP_ORDINAIRE_vers_le_meme_hote_reste_refusee(): void
    {
        // La condition que MASTER a posee mot pour mot. Sans le marqueur,
        // l'hote le plus anodin ne passe pas : ce n'est pas le DOMAINE qui est
        // admis, c'est le TRANSPORT.
        $this->assertFalse(SortieDeRecette::autorisee('https', 'fr.wikipedia.org', []));
        $this->assertFalse(SortieDeRecette::autorisee('https', 'fr.wikipedia.org', ['autre_marqueur' => true]));
    }

    public function test_sans_la_configuration_meme_un_apercu_est_refuse(): void
    {
        // La derogation est absente par defaut : personne ne l'obtient sans
        // l'avoir demandee.
        config()->set('ops.sorties_de_recette.apercus_de_lien', false);

        $this->assertFalse(SortieDeRecette::autorisee('https', 'fr.wikipedia.org', [
            SortieDeRecette::OPTION_APERCU_LIEN => true,
        ]));
    }

    #[DataProvider('environnementsNonLocaux')]
    public function test_hors_de_local_la_derogation_n_existe_pas(string $environnement): void
    {
        // Verifiee AVEC la configuration active et le marqueur pose : c'est
        // l'environnement seul qui doit suffire a fermer la porte.
        app()['env'] = $environnement;

        $this->assertFalse(SortieDeRecette::autorisee('https', 'fr.wikipedia.org', [
            SortieDeRecette::OPTION_APERCU_LIEN => true,
        ]));
    }

    /** @return array<string, array{string}> */
    public static function environnementsNonLocaux(): array
    {
        return [
            'production' => ['production'],
            'staging' => ['staging'],
            'ai-validation' => ['ai-validation'],
            'testing' => ['testing'],
        ];
    }

    // ── La protection SSRF, INTACTE ─────────────────────────────────────────

    #[DataProvider('hotesInternes')]
    public function test_un_apercu_vers_une_cible_INTERNE_reste_refuse(string $hote): void
    {
        // Le marqueur ouvre la sortie, il n'ouvre pas le reseau interne. Sans
        // cette quatrieme condition, poser le marqueur suffirait a joindre
        // n'importe quoi.
        $this->assertFalse(SortieDeRecette::autorisee('https', $hote, [
            SortieDeRecette::OPTION_APERCU_LIEN => true,
        ]), "l'hote interne {$hote} a ete admis");
    }

    /** @return array<string, array{string}> */
    public static function hotesInternes(): array
    {
        return [
            'bouclage par nom' => ['localhost'],
            'bouclage par IP' => ['127.0.0.1'],
            'adresse nulle' => ['0.0.0.0'],
            'IPv6 litterale' => ['[::1]'],
            'plage privee 10' => ['10.0.0.5'],
            'plage privee 172' => ['172.16.3.4'],
            'plage privee 192' => ['192.168.1.1'],
            'lien-local (metadonnees cloud)' => ['169.254.169.254'],
            'hote vide' => [''],
        ];
    }

    public function test_la_politique_d_hote_est_la_MEME_pour_les_deux_lecteurs(): void
    {
        // `UrlPreviewService` et le crochet `on_redirect` du garde doivent
        // lire la MEME regle. Deux copies auraient diverge au premier
        // correctif — et c'est precisement pour cela qu'elle a ete extraite.
        $service = new \ReflectionClass(UrlPreviewService::class);
        $source = file_get_contents($service->getFileName());

        $this->assertStringContainsString('PublicHostPolicy::estPublic', $source);
        $this->assertStringNotContainsString('isPrivateCidr', $source, 'une copie de la regle subsiste');
    }

    // ── Le garde consulte la regle AUX DEUX ENDROITS ────────────────────────

    public function test_le_garde_consulte_la_regle_a_la_destination_ET_aux_redirections(): void
    {
        // Mesure STRUCTURELLE, et assumee comme telle : armer le garde ici
        // poserait un drapeau statique et un middleware global qui
        // survivraient au test.
        //
        // Ce qu'elle protege est reel : un apercu suit jusqu'a trois
        // redirections, et `UrlPreviewService` ne les controle qu'APRES COUP,
        // en relisant l'historique Guzzle — a ce moment la requete vers l'hote
        // interne a deja ete faite. Si le crochet `on_redirect` cessait de
        // consulter la regle, la redirection deviendrait le trou.
        $source = file_get_contents(
            (new \ReflectionClass(\App\Support\Ops\ExternalEgressGuard::class))->getFileName()
        );

        // Les DEUX points d'application dans `garderHttp` : la destination
        // initiale et chaque saut de redirection. Le troisieme appel du
        // fichier est le delegue historique de T1740, qui ne compte pas ici.
        $this->assertStringContainsString(
            "SortieDeRecette::autorisee(\$requete->getUri()->getScheme(), \$hote, \$options)",
            $source,
            'la regle n\'est plus consultee a la destination initiale',
        );

        $this->assertStringContainsString(
            "SortieDeRecette::autorisee(\$uri->getScheme(), \$hoteSuivant, \$options)",
            $source,
            'la regle n\'est plus consultee a chaque redirection',
        );
    }

    // ── Le marqueur est bien pose, et par ce transport SEUL ─────────────────

    public function test_le_service_d_apercu_POSE_le_marqueur(): void
    {
        $vu = null;

        Http::fake(function ($request, $options) use (&$vu) {
            $vu = $options;

            return Http::response('<html><head><title>T</title></head></html>', 200);
        });

        (new UrlPreviewService)->fetchPreview('https://fr.wikipedia.org/wiki/Test');

        $this->assertNotNull($vu, 'aucune requete sortante');
        $this->assertTrue($vu[SortieDeRecette::OPTION_APERCU_LIEN] ?? false);
    }

    public function test_le_marqueur_n_est_pose_NULLE_PART_ailleurs(): void
    {
        // Un marqueur qui se repandrait dans le code perdrait tout son sens :
        // il ne designerait plus un transport, mais une habitude.
        $trouves = [];

        $racine = app_path();
        $fichiers = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($racine));

        foreach ($fichiers as $fichier) {
            if ($fichier->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($fichier->getPathname());

            if (str_contains($source, 'OPTION_APERCU_LIEN => true')) {
                $trouves[] = str_replace($racine.'/', '', $fichier->getPathname());
            }
        }

        $this->assertSame(['Services/UrlPreviewService.php'], $trouves);
    }

    // ── La politique publique elle-meme ─────────────────────────────────────

    public function test_un_hote_public_ordinaire_reste_accepte(): void
    {
        $this->assertTrue(PublicHostPolicy::estPublic('fr.wikipedia.org'));
        // Un point final est une forme valide d'un nom absolu.
        $this->assertTrue(PublicHostPolicy::estPublic('fr.wikipedia.org.'));
    }
}
