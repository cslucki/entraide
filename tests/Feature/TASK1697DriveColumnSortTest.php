<?php

namespace Tests\Feature;

use App\Models\Dossier;
use App\Models\DossierFile;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1697 (2) — trier le tableau de fichiers en cliquant sur une colonne.
 *
 * Tout le moteur existait : `sortBy`, `sortDirection`, `toggleSort()` et le tri
 * serveur. **Il manquait la poignée** — rien ne déclenchait le tri.
 *
 * Ces tests visent le SERVEUR, qui ordonne, et non le clic : les lignes sont
 * rendues en Alpine depuis la charge utile, et c'est son ordre qui compte.
 */
class TASK1697DriveColumnSortTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $proprietaire;

    private Dossier $dossier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['slug' => 'org-1697b', 'is_active' => true]);
        app()->instance('current_organization', $this->organization);

        $this->proprietaire = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->dossier = Dossier::factory()->create([
            'organization_id' => $this->organization->id,
            'owner_id' => $this->proprietaire->id,
            'visibility' => 'private',
        ]);

        // Noms, extensions et tailles DÉLIBÉRÉMENT décorrélés : un tri qui
        // retomberait sur l'ordre d'insertion passerait sinon par hasard.
        $this->fichier('zebre.pdf', 'application/pdf', 300);
        $this->fichier('alpha.png', 'image/png', 100);
        $this->fichier('milieu.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 200);
    }

    private function fichier(string $nom, string $mime, int $taille): DossierFile
    {
        return DossierFile::factory()->create([
            'organization_id' => $this->organization->id,
            'dossier_id' => $this->dossier->id,
            'uploaded_by' => $this->proprietaire->id,
            'original_name' => $nom,
            'display_name' => $nom,
            'mime_type' => $mime,
            'size_bytes' => $taille,
        ]);
    }

    /** @return array<int, string> les noms, dans l'ordre rendu */
    private function ordre(string $tri, string $sens = 'asc'): array
    {
        $json = $this->actingAs($this->proprietaire)
            ->getJson(route('organization.dossiers.files.index', [
                'organization' => $this->organization->slug,
                'dossier' => $this->dossier->id,
                'sort' => $tri,
                'direction' => $sens,
            ]))
            ->assertOk()
            ->json();

        return collect(data_get($json, 'files.data', data_get($json, 'data', [])))
            ->pluck('display_name')
            ->all();
    }

    public function test_sorting_by_name(): void
    {
        $this->assertSame(['alpha.png', 'milieu.docx', 'zebre.pdf'], $this->ordre('name'));
        $this->assertSame(['zebre.pdf', 'milieu.docx', 'alpha.png'], $this->ordre('name', 'desc'));
    }

    public function test_sorting_by_size(): void
    {
        $this->assertSame(['alpha.png', 'milieu.docx', 'zebre.pdf'], $this->ordre('size'));
        $this->assertSame(['zebre.pdf', 'milieu.docx', 'alpha.png'], $this->ordre('size', 'desc'));
    }

    /**
     * « Type » trie sur l'EXTENSION, pas sur le type MIME.
     *
     * C'est l'extension que la colonne affiche. Trier sur `mime_type` aurait
     * rendu `application/pdf` avant `application/vnd.openxml…`, soit **PDF
     * avant DOCX** — une colonne visiblement désordonnée.
     *
     * Attendu ici : docx, pdf, png.
     */
    public function test_sorting_by_type_follows_the_displayed_extension(): void
    {
        $this->assertSame(['milieu.docx', 'zebre.pdf', 'alpha.png'], $this->ordre('type'));
        $this->assertSame(['alpha.png', 'zebre.pdf', 'milieu.docx'], $this->ordre('type', 'desc'));
    }

    /** Une clé inconnue retombe sur la date, sans lever. */
    public function test_an_unknown_sort_key_falls_back(): void
    {
        $this->assertCount(3, $this->ordre('n_importe_quoi'));
    }

    /** Les en-têtes cliquables sont rendus. */
    public function test_the_headers_are_clickable(): void
    {
        $html = $this->actingAs($this->proprietaire)
            ->get(route('organization.dossiers.show', [
                'organization' => $this->organization->slug,
                'dossier' => $this->dossier->id,
            ]))
            ->assertOk()
            ->getContent();

        foreach (['name', 'type', 'date', 'size'] as $colonne) {
            // Le marqueur ET le déclencheur. Un en-tête qui porte l'un sans
            // l'autre est une poignée qui ne commande rien — le défaut exact
            // rencontré sur le FAB « Nouveau » (T1695), où le marqueur était
            // là et le fil coupé derrière.
            // `@js()` rend des apostrophes SIMPLES, pas `&quot;` — vérifié sur
            // le rendu réel avant d'écrire l'assertion, pas supposé.
            $this->assertMatchesRegularExpression(
                '/<button[^>]*toggleSort\(\x27'.$colonne.'\x27\)[^>]*data-drive-sort="'.$colonne.'"/s',
                $html,
                "L'en-tête $colonne doit porter son déclencheur.",
            );
        }
    }
}
