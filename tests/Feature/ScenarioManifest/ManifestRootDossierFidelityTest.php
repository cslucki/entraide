<?php

namespace Tests\Feature\ScenarioManifest;

use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\Organization;
use App\Models\ScenarioManifestVersion;
use App\Models\User;
use App\Support\ScenarioManager\Capture\ScenarioCaptureService;
use App\Support\ScenarioManager\ScenarioLifecycleService;
use App\Support\ScenarioManifest\ScenarioManifestValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

/**
 * TASK-1653 — `ROOT_DOSSIER_LOADER_FIDELITY`.
 *
 * Le Manifest redevient la source de verite pour ce qu'il declare sur le
 * Dossier racine et son document. Jusqu'ici le Loader posait le gabarit
 * produit et n'appliquait jamais la declaration : la divergence etait
 * PERMANENTE, et elle est devenue visible le jour ou un ecran de comparaison
 * l'a donnee a lire.
 *
 * Ces tests verifient les deux moities : ce qui est applique, et ce qui n'est
 * SURTOUT pas duplique.
 */
class ManifestRootDossierFidelityTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        App::setLocale('fr');

        $this->superAdmin = User::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
            'is_admin' => true,
            'preferred_locale' => 'fr',
        ]);
    }

    public function test_le_Load_applique_EXACTEMENT_ce_que_le_manifeste_declare(): void
    {
        [$version, $sandbox, $source] = $this->charger();

        foreach ($source['dossiers'] as $declare) {
            $dossier = $this->dossierParNom($sandbox, (string) $declare['name']);

            $this->assertNotNull($dossier, "Le Dossier « {$declare['name']} » doit porter son nom declare.");

            // Les invariants du produit sont conserves.
            $this->assertNull($dossier->parent_id, 'Un Dossier racine n a pas de parent.');
            $this->assertNotNull($dossier->loop_id, 'Un Dossier racine appartient a sa Boucle.');
            $this->assertNotNull($dossier->root_blog_post_id, 'Un Dossier racine porte son document.');

            $post = BlogPost::query()->withoutGlobalScopes()->findOrFail($dossier->root_blog_post_id);
            $declareDocument = $declare['root_document'];

            $this->assertSame($declareDocument['title'], (string) $post->title);
            $this->assertSame($declareDocument['content'], (string) $post->content);

            // L AUTEUR declare, resolu vers le persona reel.
            $auteur = User::query()->findOrFail($post->user_id);
            $emailDeclare = $this->emailDeclare($source, (string) $declareDocument['author']);
            $this->assertStringStartsWith(
                explode('@', $emailDeclare)[0].'@',
                (string) $auteur->email,
                'L auteur du document racine doit etre le persona DECLARE.'
            );
        }
    }

    public function test_aucun_second_Dossier_racine_ni_second_document_n_est_cree(): void
    {
        [, $sandbox, $source] = $this->charger();

        // Une racine par Boucle, et pas davantage.
        $racines = Dossier::query()->withoutGlobalScopes()
            ->where('organization_id', $sandbox->id)
            ->whereNull('deleted_at')
            ->whereNotNull('root_blog_post_id')
            ->get();

        $this->assertCount(count($source['loops']), $racines);
        $this->assertSame(
            $racines->count(),
            $racines->pluck('loop_id')->unique()->count(),
            'Deux Boucles ne partagent pas un Dossier racine.'
        );
        $this->assertSame(
            $racines->count(),
            $racines->pluck('root_blog_post_id')->unique()->count(),
            'Deux Dossiers racines ne partagent pas un document.'
        );
    }

    public function test_deux_Boucles_gardent_des_Dossiers_racines_DISTINCTS(): void
    {
        [, $sandbox, $source] = $this->charger();

        $noms = array_column($source['dossiers'], 'name');
        $this->assertCount(2, array_unique($noms), 'La fixture doit declarer deux noms distincts.');

        foreach ($noms as $nom) {
            $this->assertNotNull($this->dossierParNom($sandbox, $nom), "« {$nom} » doit exister tel quel.");
        }
    }

    public function test_un_RESET_reapplique_les_valeurs_DECLAREES(): void
    {
        // Reset reconstruit le monde : il doit le reconstruire FIDELE, pas
        // retomber sur le gabarit produit.
        [$version, $sandbox, $source] = $this->charger();

        $nomDeclare = (string) $source['dossiers'][0]['name'];
        $dossier = $this->dossierParNom($sandbox, $nomDeclare);
        $dossier->forceFill(['name' => 'Nom saccage dans la sandbox'])->save();

        app(ScenarioLifecycleService::class)->reset($version->fresh(), $this->superAdmin);

        $this->assertNotNull(
            $this->dossierParNom($sandbox, $nomDeclare),
            'Reset doit reappliquer le nom declare par le manifeste charge.'
        );
    }

    public function test_apres_le_Load_le_Preview_immediat_ne_montre_AUCUN_changement(): void
    {
        // Le critere produit de T1653 : c'est ce que ce correctif rend
        // atteignable. Sans lui, un Load suivi d'un Preview affichait
        // « 2 Dossiers modifies » sur un monde ou personne n'avait rien fait.
        [$version] = $this->charger();

        $diff = app(ScenarioCaptureService::class)->comparer($version);

        $this->assertTrue(
            $diff->estVide(),
            'Load puis Preview immediat doit rendre zero changement : '.json_encode($diff->famillesModifiees())
        );
    }

    // =====================================================================
    // Outillage
    // =====================================================================

    private function dossierParNom(Organization $sandbox, string $nom): ?Dossier
    {
        return Dossier::query()->withoutGlobalScopes()
            ->where('organization_id', $sandbox->id)
            ->whereNull('deleted_at')
            ->where('name', $nom)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function emailDeclare(array $source, string $clefPersona): string
    {
        foreach ($source['users'] as $persona) {
            if ($persona['key'] === $clefPersona) {
                return (string) $persona['email'];
            }
        }

        $this->fail("Le persona « {$clefPersona} » n existe pas dans la fixture.");
    }

    /**
     * @return array{0: ScenarioManifestVersion, 1: Organization, 2: array<string, mixed>}
     */
    private function charger(): array
    {
        $json = file_get_contents(base_path('tests/Fixtures/ScenarioManifest/amt-formation-ia.json'));
        $resultat = app(ScenarioManifestValidator::class)->validate($json);
        $this->assertTrue($resultat->isValid(), 'La fixture AMT doit etre valide.');

        $version = new ScenarioManifestVersion([
            'scenario_key' => 'amt-formation-ia',
            'name' => 'AMT — Formation IA',
            'version' => '1.0.0',
            'usage' => ScenarioManifestVersion::USAGE_DOGFOODING,
            'origin' => ScenarioManifestVersion::ORIGIN_IMPORT,
            'json_source' => $json,
            'created_by' => $this->superAdmin->id,
        ]);

        $version->forceFill([
            'state' => ScenarioManifestVersion::STATE_VALID,
            'digest' => $resultat->digest(),
            'validation_summary' => $resultat->toArray(),
        ])->save();

        $service = app(ScenarioLifecycleService::class);
        $service->approve($version, $this->superAdmin);
        $resultatLoad = $service->load($version->fresh());

        return [$version->fresh(), $resultatLoad->organization, json_decode($json, true)];
    }
}
