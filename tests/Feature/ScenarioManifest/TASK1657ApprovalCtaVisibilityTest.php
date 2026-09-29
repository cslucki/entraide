<?php

namespace Tests\Feature\ScenarioManifest;

use App\Models\Organization;
use App\Models\ScenarioManifestVersion;
use App\Models\User;
use App\Support\ScenarioManager\ScenarioTemplateLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1657 — le CTA d'approbation doit porter un fond qui EXISTE.
 *
 * ## Le defaut que ce test garde
 *
 * Le bouton « Creer la sandbox et charger » portait `bg-green-700` et
 * `hover:bg-green-800`. **Aucune des deux n'etait generee** dans le CSS servi :
 * la page d'approbation etait le SEUL endroit du depot a employer
 * `bg-green-700` en classe NUE — les sept autres vues ne l'utilisent qu'en
 * `hover:`, variante qui, elle, existe.
 *
 * Tailwind ne produit que ce qu'il scanne. Une classe employee a un seul
 * endroit disparait donc de tout actif construit avant l'ecran qui l'emploie —
 * et le bouton rendait `text-white` SANS AUCUN FOND : blanc sur une carte
 * claire, invisible. Mesure au navigateur : `backgroundColor` valait
 * `rgba(0, 0, 0, 0)`.
 *
 * ## Ce que ce test peut, et ce qu'il ne peut pas
 *
 * Il ne mesure PAS le rendu — c'est le role de la recette navigateur, qui lit
 * la couleur calculee et le contraste reel (6.29:1 apres correction).
 *
 * Il garde la seule chose qu'un test PHP peut garder utilement : que le CTA
 * porte les classes du primaire CANONIQUE, celles que des centaines de vues
 * partagent et qu'aucun build ne peut donc omettre. Une couleur a usage unique
 * qui reviendrait ici echouerait a nouveau en silence, et personne ne le verrait
 * avant qu'un humain n'ouvre l'ecran.
 */
class TASK1657ApprovalCtaVisibilityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Le primaire canonique de l'outil SuperAdmin : la combinaison que porte
     * deja le bouton « approuver » de CETTE MEME page, et 212 vues du depot.
     */
    private const PRIMAIRE_CANONIQUE = ['bg-indigo-600', 'hover:bg-indigo-700', 'text-white'];

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
            'is_admin' => true,
        ]);
    }

    public function test_le_CTA_de_chargement_porte_le_primaire_CANONIQUE(): void
    {
        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.outils.scenarios.approval', $this->versionApprouvee()))
            ->assertOk()
            ->getContent();

        $classes = $this->classesDuCta($html);

        $this->assertNotNull($classes, 'Le CTA `data-cta="load"` est introuvable sur l ecran d approbation.');

        foreach (self::PRIMAIRE_CANONIQUE as $attendue) {
            $this->assertContains(
                $attendue,
                $classes,
                sprintf('Le CTA a perdu « %s » : sans le primaire canonique, son fond peut ne pas etre genere.', $attendue)
            );
        }
    }

    public function test_le_CTA_n_emploie_AUCUNE_couleur_de_fond_a_usage_unique(): void
    {
        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.outils.scenarios.approval', $this->versionApprouvee()))
            ->assertOk()
            ->getContent();

        $classes = $this->classesDuCta($html) ?? [];

        // Toute classe de fond du CTA doit etre employee AILLEURS dans les vues
        // du depot. C'est la propriete qui protege reellement : une couleur
        // utilisee nulle part ailleurs n'a aucune garantie d'etre generee.
        $fonds = array_values(array_filter(
            $classes,
            static fn (string $c): bool => str_starts_with($c, 'bg-') || str_starts_with($c, 'hover:bg-')
        ));

        $this->assertNotEmpty($fonds, 'Le CTA ne declare aucune couleur de fond.');

        foreach ($fonds as $classe) {
            $ailleurs = $this->vuesUtilisant($classe);

            $this->assertGreaterThan(
                1,
                $ailleurs,
                sprintf(
                    'La classe « %s » n est employee que par %d vue : rien ne garantit qu elle soit generee. '
                    .'C est exactement le defaut de T1657 — un fond absent, et un bouton invisible.',
                    $classe,
                    $ailleurs
                )
            );
        }
    }

    public function test_le_CTA_reste_identifiable_au_clavier(): void
    {
        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.outils.scenarios.approval', $this->versionApprouvee()))
            ->assertOk()
            ->getContent();

        $classes = $this->classesDuCta($html) ?? [];

        $anneau = array_filter($classes, static fn (string $c): bool => str_starts_with($c, 'focus:ring'));

        $this->assertNotEmpty(
            $anneau,
            'Le CTA ne declare aucun anneau de focus : il ne serait pas reperable au clavier.'
        );
    }

    // =====================================================================
    // Outils
    // =====================================================================

    /** @return list<string>|null */
    private function classesDuCta(string $html): ?array
    {
        if (preg_match('/data-cta="load"[^>]*?class="([^"]+)"/s', $html, $m) !== 1) {
            return null;
        }

        return preg_split('/\s+/', trim($m[1])) ?: null;
    }

    /** Combien de vues du depot emploient cette classe exacte ? */
    private function vuesUtilisant(string $classe): int
    {
        $vues = glob(base_path('resources/views/**/*.blade.php'), GLOB_BRACE) ?: [];

        // `glob` ne descend pas recursivement : on parcourt l'arborescence.
        $iterateur = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(base_path('resources/views'))
        );

        $total = 0;

        foreach ($iterateur as $fichier) {
            if (! $fichier->isFile() || ! str_ends_with($fichier->getFilename(), '.blade.php')) {
                continue;
            }

            $contenu = (string) file_get_contents($fichier->getPathname());

            // Frontiere de mot : `bg-green-7` ne doit pas compter pour
            // `bg-green-700`, ni `hover:bg-x` pour `bg-x`.
            if (preg_match('/(?<![\w:-])'.preg_quote($classe, '/').'(?![\w-])/', $contenu) === 1) {
                $total++;
            }
        }

        return $total;
    }

    private function versionApprouvee(): ScenarioManifestVersion
    {
        $version = new ScenarioManifestVersion([
            'scenario_key' => 'ofsh-approbation',
            'name' => 'OFSH approbation',
            'version' => '1.0.0',
            'usage' => ScenarioManifestVersion::USAGE_QA,
            'origin' => ScenarioManifestVersion::ORIGIN_TEMPLATE,
            'json_source' => (string) ScenarioTemplateLibrary::json('ofsh'),
            'created_by' => $this->superAdmin->id,
        ]);

        // `state` et l'approbation sont ecrits par le SYSTEME : le test les pose
        // comme la production le fera. Le CTA de chargement n'apparait qu'APRES
        // l'approbation.
        $version->forceFill([
            'state' => ScenarioManifestVersion::STATE_VALID,
            'digest' => str_repeat('b', 16),
            'approved_digest' => str_repeat('b', 16),
            'approved_at' => now(),
            'approved_by' => $this->superAdmin->id,
        ])->save();

        return $version;
    }
}
