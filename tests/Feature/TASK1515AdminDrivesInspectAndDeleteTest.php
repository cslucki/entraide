<?php

namespace Tests\Feature;

use App\Jobs\IndexDossierFileChunks;
use App\Models\Dossier;
use App\Models\DossierChunk;
use App\Models\DossierFile;
use App\Models\Organization;
use App\Models\User;
use App\Services\Dossiers\DossierFileIndexingDispatcher;
use App\Services\Dossiers\OrganizationRagOverview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-1515 — `/admin/drives` : voir les extraits indexes, supprimer un fichier.
 *
 * Ce que ces tests gardent, dans l'ordre de ce qui ferait le plus de degats :
 *
 *  1. le PERIMETRE. `DossierFile` ne porte AUCUN scope global de tenant : le
 *     route-model-binding accepterait l'UUID d'un fichier d'une autre
 *     Organization. Une URL forgee doit rendre 404 — pour LIRE comme pour
 *     SUPPRIMER. C'est le seul garde-fou, il est explicite, il est teste deux
 *     fois ;
 *  2. le DROIT. Il vient du middleware `admin`, et de lui seul ;
 *  3. le VECTEUR. L'ecran repond a « qu'est-ce que l'IA a retenu de ce
 *     document ? », jamais a « donne-moi ses flottants ». Re-attestation ;
 *  4. la SUPPRESSION. Un seul geste dans tout le produit, doux en base, dur
 *     sur le stockage, et qui laisse les voisins intacts.
 *
 * Aucun fichier reel n'est touche : la suite tourne sur une base jetable et un
 * disque `Storage::fake`.
 */
class TASK1515AdminDrivesInspectAndDeleteTest extends TestCase
{
    use RefreshDatabase;

    /** Une valeur reconnaissable : si elle sort, le vecteur a fuit. */
    private const EMBEDDING_WITNESS = 0.4242424242;

    private Organization $orgA;

    private Organization $orgB;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('dossier_files');

        $this->orgA = Organization::factory()->create(['slug' => 'org-a-1515', 'name' => 'Alpha 1515', 'is_active' => true]);
        $this->orgB = Organization::factory()->create(['slug' => 'org-b-1515', 'name' => 'Beta 1515', 'is_active' => true]);

        $platform = Organization::factory()->create(['slug' => 'plateforme-1515']);
        $this->superAdmin = User::factory()->create(['is_admin' => true, 'organization_id' => $platform->id]);
    }

    // ── Fixtures dediees. JAMAIS un document reel. ──────────────────────────

    private function file(Organization $organization, string $name, string $mime = 'text/plain'): DossierFile
    {
        $owner = User::factory()->create(['organization_id' => $organization->id]);
        $dossier = Dossier::factory()->create(['organization_id' => $organization->id, 'owner_id' => $owner->id]);
        $path = 'dossier-files/'.Str::uuid().'.txt';

        Storage::disk('dossier_files')->put($path, 'contenu de fixture — jamais un document reel');

        return DossierFile::create([
            'organization_id' => $organization->id,
            'dossier_id' => $dossier->id,
            'uploaded_by' => $owner->id,
            'disk' => 'dossier_files',
            'path' => $path,
            'original_name' => $name,
            'display_name' => $name,
            'mime_type' => $mime,
            'size_bytes' => 512,
            'checksum_sha256' => hash('sha256', $name.Str::uuid()),
            'source' => 'upload',
        ]);
    }

    private function chunk(DossierFile $file, int $index, string $content): void
    {
        // `vector(1536)` en pgsql : une chaine courte y serait refusee, et le
        // defaut serait invisible en SQLite (colonne texte). Les valeurs sont
        // toutes DISTINCTES : un vecteur de zeros s'encoderait en entiers et
        // rendrait la garde « suite de flottants » impossible a faire rougir.
        $embedding = array_map(static fn (int $i): float => round(0.1 + $i / 10000, 6), range(0, 1535));
        $embedding[0] = self::EMBEDDING_WITNESS;

        DossierChunk::create([
            'organization_id' => $file->organization_id,
            'dossier_id' => $file->dossier_id,
            'blog_post_id' => null,
            'dossier_file_id' => $file->id,
            'chunk_index' => $index,
            'content' => $content,
            'content_hash' => hash('sha256', $content.$file->id.$index),
            'token_count' => 7,
            'embedding' => $embedding,
            'embedding_provider' => 'openrouter',
            'embedding_model' => 'openai/text-embedding-3-small',
            'indexed_at' => now(),
        ]);
    }

    private function indexedFile(Organization $organization, string $name, int $chunks = 1): DossierFile
    {
        $file = $this->file($organization, $name);

        for ($i = 0; $i < $chunks; $i++) {
            $this->chunk($file, $i, 'extrait '.$i.' de '.$name);
        }

        return $file;
    }

    private function page(array $query = []): string
    {
        return $this->actingAs($this->superAdmin)
            ->get(route('admin.drives', $query))
            ->assertOk()
            ->getContent();
    }

    private function chunksUrl(Organization $organization, DossierFile $file): string
    {
        return route('admin.drives.chunks', ['organization' => $organization->slug, 'file' => $file->id]);
    }

    private function destroyUrl(Organization $organization, DossierFile $file): string
    {
        return route('admin.drives.destroy', ['organization' => $organization->slug, 'file' => $file->id]);
    }

    // ── 1. Le perimetre : une URL forgee ne franchit rien ───────────────────

    public function test_a_forged_cross_organization_url_cannot_read_the_excerpts(): void
    {
        $foreign = $this->indexedFile($this->orgB, 'chez-beta.txt');

        $this->actingAs($this->superAdmin)
            ->get(route('admin.drives.chunks', ['organization' => $this->orgA->slug, 'file' => $foreign->id]))
            ->assertNotFound();
    }

    public function test_a_forged_cross_organization_url_cannot_delete_and_the_file_survives(): void
    {
        $foreign = $this->indexedFile($this->orgB, 'chez-beta.txt');
        $path = $foreign->path;

        $this->actingAs($this->superAdmin)
            ->delete(route('admin.drives.destroy', ['organization' => $this->orgA->slug, 'file' => $foreign->id]))
            ->assertNotFound();

        $this->assertNotSoftDeleted('dossier_files', ['id' => $foreign->id]);
        Storage::disk('dossier_files')->assertExists($path);
    }

    // ── 2. Le droit vient du middleware, de lui seul ────────────────────────

    public function test_a_non_super_admin_cannot_read_the_excerpts(): void
    {
        $file = $this->indexedFile($this->orgA, 'un.txt');
        $member = User::factory()->create(['organization_id' => $this->orgA->id, 'is_admin' => false]);

        $this->actingAs($member)->get($this->chunksUrl($this->orgA, $file))->assertForbidden();
    }

    public function test_a_non_super_admin_cannot_delete(): void
    {
        $file = $this->indexedFile($this->orgA, 'un.txt');
        $member = User::factory()->create(['organization_id' => $this->orgA->id, 'is_admin' => false]);

        $this->actingAs($member)->delete($this->destroyUrl($this->orgA, $file))->assertForbidden();

        $this->assertNotSoftDeleted('dossier_files', ['id' => $file->id]);
        Storage::disk('dossier_files')->assertExists($file->path);
    }

    // ── 3. Voir extraits ────────────────────────────────────────────────────

    public function test_the_action_appears_only_for_a_file_that_carries_excerpts(): void
    {
        $indexed = $this->indexedFile($this->orgA, 'indexe.txt');
        $bare = $this->file($this->orgA, 'sans-extrait.txt');

        $html = $this->page();

        $this->assertSame(1, preg_match('/<tr[^>]*data-drives-row="'.preg_quote($indexed->id, '/').'".*?<\/tr>/s', $html, $withChunks));
        $this->assertSame(1, preg_match('/<tr[^>]*data-drives-row="'.preg_quote($bare->id, '/').'".*?<\/tr>/s', $html, $without));

        $this->assertStringContainsString('data-drives-inspect', $withChunks[0], 'un fichier indexe doit offrir « Voir extraits »');
        $this->assertStringNotContainsString('data-drives-inspect', $without[0], 'sans extrait, le tiroir serait vide et ferait croire a une panne');
    }

    public function test_the_endpoint_renders_the_stored_text_of_this_file_and_of_no_other(): void
    {
        $mine = $this->file($this->orgA, 'le-mien.txt');
        $this->chunk($mine, 0, 'MON-EXTRAIT-A-MOI');

        $neighbour = $this->file($this->orgA, 'le-voisin.txt');
        $this->chunk($neighbour, 0, 'EXTRAIT-DU-VOISIN');

        $fragment = $this->actingAs($this->superAdmin)
            ->get($this->chunksUrl($this->orgA, $mine))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('MON-EXTRAIT-A-MOI', $fragment);
        $this->assertStringNotContainsString('EXTRAIT-DU-VOISIN', $fragment);
        $this->assertStringContainsString('data-source-chunk="0"', $fragment);
    }

    /**
     * Re-attestation de TASK-1307 sur une porte NEUVE : la liste blanche de
     * colonnes est la seule chose qui empeche le vecteur de sortir.
     */
    public function test_the_endpoint_never_exposes_the_embedding(): void
    {
        $file = $this->indexedFile($this->orgA, 'un.txt');

        $fragment = $this->actingAs($this->superAdmin)
            ->get($this->chunksUrl($this->orgA, $file))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('0.4242424242', $fragment, 'le vecteur embedding a fuit dans le fragment');
        // Un vecteur, c'est une SUITE de flottants — pas le mot « embedding »,
        // qui appartient a l'anglais courant et figure legitimement dans la
        // prose de certains documents (mesure faite sur un document reel).
        $this->assertSame(0, preg_match('/-?\d\.\d+,\s*-?\d\.\d+,\s*-?\d\.\d+/', $fragment), 'une suite de flottants est sortie dans le fragment');
    }

    public function test_the_endpoint_forbids_every_cache(): void
    {
        $file = $this->indexedFile($this->orgA, 'un.txt');

        $response = $this->actingAs($this->superAdmin)->get($this->chunksUrl($this->orgA, $file))->assertOk();

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    /**
     * Un document de plusieurs milliers d'extraits ne doit ni saturer la
     * memoire ni produire une page illisible — et l'ecran doit DIRE qu'il
     * n'a pas tout montre.
     */
    public function test_the_excerpts_are_bounded_and_the_screen_says_how_many_it_hides(): void
    {
        $file = $this->indexedFile($this->orgA, 'volumineux.txt', 25);

        $fragment = $this->actingAs($this->superAdmin)
            ->get($this->chunksUrl($this->orgA, $file))
            ->assertOk()
            ->getContent();

        $this->assertSame(20, substr_count($fragment, 'data-source-chunk='), 'la borne d affichage n est pas appliquee');
        $this->assertStringContainsString('data-source-total="25"', $fragment, 'le total annonce doit rester le total REEL');
        $this->assertStringContainsString('data-source-shown="20"', $fragment);
        $this->assertStringContainsString('data-source-truncated', $fragment, 'une troncature muette ferait croire au document entier');
    }

    public function test_a_file_without_excerpts_says_so_instead_of_showing_an_empty_list(): void
    {
        $bare = $this->file($this->orgA, 'sans-extrait.txt');

        $fragment = $this->actingAs($this->superAdmin)
            ->get($this->chunksUrl($this->orgA, $bare))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-source-not-indexed', $fragment);
        $this->assertStringNotContainsString('data-source-chunks', $fragment);
    }

    /**
     * `layouts/admin` n'emet AUCUN jeton de theme : le fragment est rendu
     * SOUS ce layout, une couleur `var(--bp-*)` y serait un no-op silencieux.
     */
    public function test_the_fragment_never_relies_on_theme_tokens_the_admin_layout_does_not_emit(): void
    {
        $file = $this->indexedFile($this->orgA, 'un.txt');

        $fragment = $this->actingAs($this->superAdmin)
            ->get($this->chunksUrl($this->orgA, $file))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('var(--bp-', $fragment);
    }

    // ── 4. Supprimer ────────────────────────────────────────────────────────

    public function test_deleting_erases_the_blob_and_soft_deletes_the_row(): void
    {
        $file = $this->indexedFile($this->orgA, 'a-supprimer.txt');
        $path = $file->path;

        Storage::disk('dossier_files')->assertExists($path);

        $this->actingAs($this->superAdmin)
            ->delete($this->destroyUrl($this->orgA, $file))
            ->assertRedirect();

        Storage::disk('dossier_files')->assertMissing($path);
        $this->assertSoftDeleted('dossier_files', ['id' => $file->id]);
        // Doux, JAMAIS force : deux cles etrangeres cascadent depuis
        // `dossier_files` (elements de series, sources de manifeste).
        $this->assertDatabaseHas('dossier_files', ['id' => $file->id]);
    }

    public function test_deleting_queues_the_cleanup_of_its_excerpts_on_the_dedicated_queue(): void
    {
        // L'Observer dispatche deja a la creation : on fige la file APRES la
        // fixture, sinon on mesurerait SON job et non celui de la suppression.
        $file = $this->indexedFile($this->orgA, 'a-supprimer.txt');
        Queue::fake();

        $this->actingAs($this->superAdmin)
            ->delete($this->destroyUrl($this->orgA, $file))
            ->assertRedirect();

        Queue::assertPushedOn(DossierFileIndexingDispatcher::DEDICATED_QUEUE, IndexDossierFileChunks::class);
    }

    /**
     * Le nettoyage des extraits est asynchrone. Ce qui compte, c'est que la
     * console cesse IMMEDIATEMENT de les montrer — sinon la latence de queue
     * deviendrait une fuite.
     */
    public function test_a_deleted_file_stops_exposing_its_excerpts_immediately(): void
    {
        $file = $this->indexedFile($this->orgA, 'a-supprimer.txt');
        $url = $this->chunksUrl($this->orgA, $file);

        $this->actingAs($this->superAdmin)->get($url)->assertOk();

        $this->actingAs($this->superAdmin)->delete($this->destroyUrl($this->orgA, $file))->assertRedirect();

        $this->actingAs($this->superAdmin)->get($url)->assertNotFound();

        $this->assertNull(
            app(OrganizationRagOverview::class)->chunksFor((string) $this->orgA->id, 'file', (string) $file->id),
            'la primitive canonique doit ignorer un fichier supprime',
        );
    }

    public function test_deleting_one_file_leaves_its_neighbours_and_the_other_tenant_alone(): void
    {
        $target = $this->indexedFile($this->orgA, 'cible.txt');
        $neighbour = $this->indexedFile($this->orgA, 'voisin.txt');
        $elsewhere = $this->indexedFile($this->orgB, 'ailleurs.txt');

        $this->actingAs($this->superAdmin)
            ->delete($this->destroyUrl($this->orgA, $target))
            ->assertRedirect();

        $this->assertNotSoftDeleted('dossier_files', ['id' => $neighbour->id]);
        $this->assertNotSoftDeleted('dossier_files', ['id' => $elsewhere->id]);
        Storage::disk('dossier_files')->assertExists($neighbour->path);
        Storage::disk('dossier_files')->assertExists($elsewhere->path);
        $this->assertDatabaseHas('dossier_chunks', ['dossier_file_id' => $neighbour->id]);
        $this->assertDatabaseHas('dossier_chunks', ['dossier_file_id' => $elsewhere->id]);
    }

    /** Aucune destruction ne doit etre atteignable par une simple navigation. */
    public function test_deletion_is_not_reachable_by_get(): void
    {
        $file = $this->indexedFile($this->orgA, 'un.txt');

        $this->actingAs($this->superAdmin)
            ->get($this->destroyUrl($this->orgA, $file))
            ->assertStatus(405);

        $this->assertNotSoftDeleted('dossier_files', ['id' => $file->id]);
    }

    public function test_the_confirmation_names_the_file_and_the_form_carries_the_delete_verb(): void
    {
        $file = $this->file($this->orgA, 'contrat-2026.txt');

        $html = $this->page();

        $this->assertStringContainsString('data-drives-delete-name="contrat-2026.txt"', $html, 'sans le nom, rien ne distingue la ligne voulue de sa voisine');
        $this->assertStringContainsString('data-drives-delete-dialog', $html);
        $this->assertStringContainsString('name="_method" value="DELETE"', $html);
        // CSRF n'est pas mesurable en test HTTP (middleware desarme) : ce qui
        // se mesure, c'est que le formulaire porte bien le jeton.
        $this->assertStringContainsString('name="_token"', $html);
    }

    /**
     * Le bouton de la ligne ne doit RIEN soumettre : le bouton de soumission
     * vit dans la fenetre de confirmation. Sans JavaScript, l'action est
     * indisponible — jamais accidentelle.
     */
    public function test_the_row_button_never_submits_on_its_own(): void
    {
        $file = $this->file($this->orgA, 'un.txt');

        $html = $this->page();

        $this->assertSame(1, preg_match('/<button[^>]*data-drives-delete[^>]*>/', $html, $m));
        $this->assertStringContainsString('type="button"', $m[0]);
        $this->assertStringNotContainsString('type="submit"', $m[0]);
    }

    // ── 5. Les deux langues ─────────────────────────────────────────────────

    public function test_both_locales_carry_the_new_keys(): void
    {
        $fr = require lang_path('fr/drives.php');
        $en = require lang_path('en/drives.php');

        $this->assertSame(array_keys($en), array_keys($fr), 'les deux langues doivent porter exactement les memes cles');

        foreach (['action_view_chunks', 'action_delete', 'inspect_title', 'inspect_close', 'inspect_loading',
            'delete_title', 'delete_body', 'delete_cancel', 'delete_submit', 'delete_done'] as $key) {
            $this->assertArrayHasKey($key, $fr, "cle {$key} absente du francais");
            $this->assertNotSame('', trim((string) $fr[$key]));
        }

        $this->assertStringContainsString(':name', (string) $fr['delete_body'], 'la confirmation doit nommer le fichier');
        $this->assertStringContainsString('effacé', (string) $fr['delete_body'], 'texte visible en francais : les accents ne sont pas facultatifs');
        $this->assertStringContainsString('supprimé', (string) $fr['delete_done']);
    }

    /**
     * La page rend en francais aussi : une apostrophe non echappee dans
     * `lang/fr` resterait invisible d'une suite qui tourne en anglais.
     */
    public function test_the_page_renders_in_french_too(): void
    {
        $this->file($this->orgA, 'un.txt');

        app()->setLocale('fr');

        $html = $this->page();

        $this->assertStringContainsString(e(__('drives.action_delete')), $html);
        $this->assertStringContainsString(e(__('drives.delete_submit')), $html);
    }
}
