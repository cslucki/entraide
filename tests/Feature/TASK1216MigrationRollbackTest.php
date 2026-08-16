<?php

namespace Tests\Feature;

use App\Models\Dossier;
use App\Models\DossierChunk;
use App\Models\DossierFile;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Revue MASTER (TASK-1216) : le `down()` de
 * `2026_08_16_171840_add_dossier_file_source_to_dossier_chunks` doit rester
 * executable meme quand des chunks fichier (`blog_post_id` NULL) existent —
 * remettre `blog_post_id` NOT NULL sans les retirer d'abord echouerait (ou
 * corromprait ces lignes). Verifie sur les deux drivers ; ne necessite pas
 * pgvector (le rollback ne touche pas la colonne `embedding`).
 *
 * `RefreshDatabase` enveloppe ce test dans une transaction, et Postgres
 * comme SQLite supportent le DDL transactionnel : le `down()`/`up()`
 * executes ici sont annules a la fin du test comme n'importe quelle
 * ecriture — aucun etat de schema durable n'est laisse derriere.
 */
class TASK1216MigrationRollbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_rollback_succeeds_with_existing_file_chunks_and_up_is_reversible(): void
    {
        $migration = require database_path('migrations/2026_08_16_171840_add_dossier_file_source_to_dossier_chunks.php');

        $organization = Organization::factory()->create();
        $owner = User::factory()->create(['organization_id' => $organization->id]);
        $dossier = Dossier::create([
            'organization_id' => $organization->id,
            'owner_id' => $owner->id,
            'name' => 'TASK1216 rollback dossier',
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);
        $file = DossierFile::create([
            'organization_id' => $organization->id,
            'dossier_id' => $dossier->id,
            'uploaded_by' => $owner->id,
            'disk' => 'dossier_files',
            'path' => 'dossier-files/'.$dossier->id.'/rollback-test.txt',
            'original_name' => 'rollback-test.txt',
            'display_name' => 'rollback-test.txt',
            'mime_type' => 'text/plain',
            'size_bytes' => 10,
            'checksum_sha256' => hash('sha256', 'rollback-test'),
            'source' => 'upload',
        ]);

        // Etat de depart : la migration est deja appliquee par
        // RefreshDatabase (schema courant du projet). On y insere un chunk
        // fichier realiste (blog_post_id NULL, dossier_file_id renseigne)
        // pour reproduire exactement la situation qui inquiete MASTER.
        DossierChunk::create([
            'organization_id' => $organization->id,
            'dossier_id' => $dossier->id,
            'blog_post_id' => null,
            'dossier_file_id' => $file->id,
            'chunk_index' => 0,
            'content' => 'contenu de test',
            'content_hash' => hash('sha256', 'contenu de test'),
            'token_count' => 3,
            'embedding' => $this->embeddingValue(),
            'embedding_provider' => 'openai',
            'embedding_model' => 'text-embedding-3-small',
            'indexed_at' => now(),
        ]);

        $this->assertSame(1, DB::table('dossier_chunks')->whereNotNull('dossier_file_id')->count());

        // Le rollback ne doit jamais lever — c'est exactement ce que la
        // revue MASTER signalait comme potentiellement impossible.
        $migration->down();

        $this->assertFalse(Schema::hasColumn('dossier_chunks', 'dossier_file_id'));
        $this->assertSame(0, DB::table('dossier_chunks')->count(), 'le chunk fichier perime doit avoir ete retire par le rollback, pas laisse orphelin');

        // Remonter doit fonctionner de nouveau (symetrie up apres down).
        $migration->up();

        $this->assertTrue(Schema::hasColumn('dossier_chunks', 'dossier_file_id'));

        // blog_post_id doit etre redevenu nullable : verifie en inserant une
        // ligne fichier sans lever d'exception (preuve fonctionnelle, plus
        // fiable qu'une introspection de schema qui differe SQLite/Postgres).
        DossierChunk::create([
            'organization_id' => $organization->id,
            'dossier_id' => $dossier->id,
            'blog_post_id' => null,
            'dossier_file_id' => $file->id,
            'chunk_index' => 0,
            'content' => 'contenu apres remontee',
            'content_hash' => hash('sha256', 'contenu apres remontee'),
            'token_count' => 3,
            'embedding' => $this->embeddingValue(),
            'embedding_provider' => 'openai',
            'embedding_model' => 'text-embedding-3-small',
            'indexed_at' => now(),
        ]);

        $this->assertSame(1, DB::table('dossier_chunks')->count());
    }

    /**
     * @return string|array<int, float>
     */
    private function embeddingValue(): string|array
    {
        $dimensions = config('database.default') === 'pgsql' ? 1536 : 8;

        return array_fill(0, $dimensions, 0.1);
    }
}
