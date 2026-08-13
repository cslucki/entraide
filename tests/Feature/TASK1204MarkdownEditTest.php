<?php

namespace Tests\Feature;

use App\Models\ArticleSeries;
use App\Models\Dossier;
use App\Models\DossierFile;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Modifier une note Markdown depuis le Drive (TASK-1204, sujet 1).
 *
 * Le point de la tache n'est pas « pouvoir editer » : c'est **editer sans
 * dupliquer**. Une note modifiee doit rester la MEME ligne `dossier_files` —
 * meme identifiant, meme dossier, meme nom, meme place dans une Serie — avec
 * seulement son contenu, sa taille et son empreinte remis a jour.
 *
 * Ces tests gardent donc, dans l'ordre :
 * - l'unicite de la ligne et la stabilite de tout ce qui l'identifie ;
 * - la coherence de ce qui DECRIT le contenu (taille, checksum, updated_at) ;
 * - la bornage au Markdown — aucun autre type ne devient inscriptible ;
 * - les refus : tenant, dossier, droits, doublon de contenu.
 */
class TASK1204MarkdownEditTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $proprietaire;

    private User $intrus;

    private Dossier $dossier;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('dossier_files');

        $this->org = Organization::factory()->create(['is_active' => true, 'is_default' => true]);
        $this->proprietaire = User::factory()->create(['organization_id' => $this->org->id]);
        $this->intrus = User::factory()->create(['organization_id' => $this->org->id]);

        $this->dossier = Dossier::create([
            'organization_id' => $this->org->id,
            'owner_id' => $this->proprietaire->id,
            'name' => 'Notes',
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);

        app()->instance('current_organization', $this->org);
    }

    private function note(string $nom = 'reunion.md', string $contenu = "# Reunion\n\nOrdre du jour.", ?Dossier $dossier = null): DossierFile
    {
        $dossier ??= $this->dossier;
        $chemin = 'dossier-files/'.$dossier->id.'/'.\Illuminate\Support\Str::random(20).'.md';
        Storage::disk('dossier_files')->put($chemin, $contenu);

        return DossierFile::create([
            'organization_id' => $dossier->organization_id,
            'dossier_id' => $dossier->id,
            'uploaded_by' => $this->proprietaire->id,
            'disk' => 'dossier_files',
            'path' => $chemin,
            'original_name' => $nom,
            'display_name' => $nom,
            'mime_type' => 'text/markdown',
            'size_bytes' => strlen($contenu),
            'checksum_sha256' => hash('sha256', $contenu),
            'source' => 'upload',
        ]);
    }

    private function lire(DossierFile $file, ?User $acteur = null): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($acteur ?? $this->proprietaire)->getJson(
            route('organization.dossiers.files.markdown', [
                'organization' => $this->org->slug,
                'dossier' => $file->dossier_id,
                'file' => $file->getKey(),
            ])
        );
    }

    private function ecrire(DossierFile $file, string $contenu, ?User $acteur = null, ?Dossier $via = null): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($acteur ?? $this->proprietaire)->patchJson(
            route('organization.dossiers.files.markdown.update', [
                'organization' => $this->org->slug,
                'dossier' => ($via ?? $this->dossier)->getKey(),
                'file' => $file->getKey(),
            ]),
            ['content' => $contenu],
        );
    }

    // ── Lire pour rouvrir l'editeur ──────────────────────────────────────────

    public function test_the_note_content_can_be_read_back(): void
    {
        $note = $this->note(contenu: "# Titre\n\nDeux lignes.");

        $this->lire($note)
            ->assertOk()
            ->assertJsonPath('content', "# Titre\n\nDeux lignes.")
            ->assertJsonPath('display_name', 'reunion.md');
    }

    public function test_reading_is_never_cached(): void
    {
        // Une note peut avoir ete modifiee ailleurs depuis l'affichage de la
        // liste : la relecture doit toujours venir du serveur.
        $this->lire($this->note())
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    // ── Ecrire : la MEME ligne, jamais une seconde ───────────────────────────

    public function test_editing_updates_the_same_row_and_never_creates_another(): void
    {
        $note = $this->note();
        $cheminAvant = $note->path;

        $this->ecrire($note, "# Reunion\n\nNouveau contenu.")->assertOk();

        $this->assertSame(1, DossierFile::where('dossier_id', $this->dossier->id)->count());

        $note->refresh();
        $this->assertSame($cheminAvant, $note->path, 'Le fichier doit rester au meme endroit.');
        $this->assertSame($this->dossier->id, $note->dossier_id);
        $this->assertSame('reunion.md', $note->display_name);
        $this->assertSame('reunion.md', $note->original_name);
        $this->assertSame('text/markdown', $note->mime_type);
    }

    public function test_the_content_is_really_written_to_disk(): void
    {
        $note = $this->note();

        $this->ecrire($note, 'Contenu remplace.')->assertOk();

        $this->assertSame('Contenu remplace.', Storage::disk('dossier_files')->get($note->fresh()->path));
    }

    public function test_size_and_checksum_follow_the_content(): void
    {
        $note = $this->note();
        $nouveau = "# Reunion\n\nUn contenu nettement plus long, pour que la taille change vraiment.";

        $this->ecrire($note, $nouveau)->assertOk();

        $note->refresh();
        $this->assertSame(strlen($nouveau), $note->size_bytes);
        $this->assertSame(hash('sha256', $nouveau), $note->checksum_sha256);
    }

    public function test_an_unchanged_content_does_not_touch_the_row(): void
    {
        $note = $this->note(contenu: 'Inchange.');
        $avant = $note->updated_at;
        $this->travel(2)->minutes();

        $this->ecrire($note, 'Inchange.')->assertOk();

        // Sans cette garde, « Modifie le » avancerait a chaque ouverture suivie
        // d'une fermeture, sans qu'un mot ait bouge.
        $this->assertEquals($avant, $note->fresh()->updated_at);
    }

    public function test_a_note_may_be_emptied(): void
    {
        $note = $this->note();

        $this->ecrire($note, '')->assertOk();

        $this->assertSame(0, $note->fresh()->size_bytes);
    }

    public function test_series_membership_is_untouched(): void
    {
        // Une Serie du Dossier ne doit pas etre affectee : on reecrit un
        // contenu, on ne deplace ni ne recree rien.
        $note = $this->note();
        $serie = ArticleSeries::create([
            'organization_id' => $this->org->id,
            'dossier_id' => $this->dossier->id,
            'root_blog_post_id' => null,
            'created_by' => $this->proprietaire->id,
            'name' => 'Sequence',
        ]);

        $this->ecrire($note, 'Autre chose.')->assertOk();

        $this->assertDatabaseHas('article_series', ['id' => $serie->id, 'dossier_id' => $this->dossier->id]);
        $this->assertSame($this->dossier->id, $note->fresh()->dossier_id);
    }

    // ── Borne au Markdown ────────────────────────────────────────────────────

    public function test_a_non_markdown_file_is_not_writable(): void
    {
        $pdf = $this->note('facture.pdf');
        $pdf->update(['mime_type' => 'application/pdf']);

        $this->ecrire($pdf->fresh(), 'contenu')->assertStatus(404);
        $this->lire($pdf->fresh())->assertStatus(404);
    }

    public function test_a_markdown_file_declared_as_plain_text_is_still_editable(): void
    {
        // Un import depuis un poste Windows arrive parfois en `text/plain` :
        // l'ecran le presente comme une note, l'endpoint doit le reconnaitre.
        $note = $this->note();
        $note->update(['mime_type' => 'text/plain']);

        $this->ecrire($note->fresh(), 'Toujours une note.')->assertOk();
    }

    // ── Les refus ────────────────────────────────────────────────────────────

    public function test_a_file_of_another_dossier_is_refused(): void
    {
        $autre = Dossier::create([
            'organization_id' => $this->org->id,
            'owner_id' => $this->proprietaire->id,
            'name' => 'Ailleurs',
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);
        $note = $this->note(dossier: $autre);

        $this->ecrire($note, 'contenu', via: $this->dossier)->assertStatus(404);
    }

    public function test_someone_without_rights_cannot_write(): void
    {
        $note = $this->note();

        $this->ecrire($note, 'contenu', acteur: $this->intrus)->assertStatus(403);
        $this->assertSame("# Reunion\n\nOrdre du jour.", Storage::disk('dossier_files')->get($note->path));
    }

    public function test_a_duplicate_content_in_the_same_dossier_is_refused(): void
    {
        $premiere = $this->note('a.md', 'Contenu identique.');
        $seconde = $this->note('b.md', 'Autre contenu.');

        $this->ecrire($seconde, 'Contenu identique.')
            ->assertStatus(422)
            ->assertJsonPath('message', __('dossiers.file_duplicate_content'));

        $this->assertSame('Autre contenu.', Storage::disk('dossier_files')->get($seconde->fresh()->path));
        $this->assertSame(hash('sha256', 'Contenu identique.'), $premiere->fresh()->checksum_sha256);
    }
}
