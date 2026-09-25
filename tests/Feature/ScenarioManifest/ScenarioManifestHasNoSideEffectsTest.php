<?php

namespace Tests\Feature\ScenarioManifest;

use App\Support\ScenarioManifest\ManifestValidationResult;
use App\Support\ScenarioManifest\ScenarioManifestValidator;
use Illuminate\Support\Facades\DB;
use Tests\Support\ScenarioManifest\AmtReferenceManifest;
use Tests\TestCase;

/**
 * TASK-1641 — "Import != Load" (spec 5.2), prouve dans une application bootee.
 *
 * Les tests unitaires du Validator tournent sans conteneur : ils prouvent
 * qu'une ecriture y serait impossible. Ce test repond a la question
 * complementaire, la seule qui compte au moment ou le SuperAdmin collera un
 * document inconnu : une fois toutes les facades disponibles, une base
 * accessible et un disque montre, valider un manifeste — valide ou hostile —
 * ecrit-il quelque chose ?
 *
 * Les trois preuves sont independantes :
 *  - AUCUNE requete ne part vers la base, meme en lecture ;
 *  - AUCUN fichier n'apparait sous `storage/app` ;
 *  - AUCUN appel reseau ne part (la garde `Http::preventStrayRequests()` de
 *    `Tests\TestCase` jette avant d'atteindre le reseau, et le test statique
 *    ci-dessous montre qu'aucun transport n'existe dans le namespace).
 */
class ScenarioManifestHasNoSideEffectsTest extends TestCase
{
    private const NAMESPACE_DIRECTORY = 'app/Support/ScenarioManifest';

    public function test_validating_a_manifest_runs_no_database_query_at_all(): void
    {
        $queries = [];

        DB::listen(static function (object $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $result = (new ScenarioManifestValidator)->validate(AmtReferenceManifest::json());

        $this->assertSame(ManifestValidationResult::VALID, $result->verdict());
        $this->assertSame([], $queries, 'Validation must not touch the database, not even to read.');
    }

    public function test_validating_a_hostile_manifest_runs_no_database_query_either(): void
    {
        // Le cas qui compte vraiment : le rejet doit arriver AVANT toute
        // ecriture, pas a la place d'une ecriture deja faite.
        $hostile = AmtReferenceManifest::mutate(static function (\stdClass $manifest): void {
            $manifest->target_organization = 'main';
            $manifest->users[0]->organization_id = 1;
            $manifest->loops[0]->table = 'loops';
            $manifest->articles[0]->format = 'html';
            $manifest->articles[0]->content = '<script>fetch("https://evil.test")</script>';
        });

        $queries = [];

        DB::listen(static function (object $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $result = (new ScenarioManifestValidator)->validate($hostile);

        $this->assertSame(ManifestValidationResult::INVALID, $result->verdict());
        $this->assertContains('TENANT_TARGET_FORBIDDEN', $result->errorCodes());
        $this->assertSame([], $queries, 'A rejected manifest must be rejected before any write.');
    }

    public function test_validating_a_manifest_creates_no_file(): void
    {
        $before = $this->storageSnapshot();

        $validator = new ScenarioManifestValidator;
        $validator->validate(AmtReferenceManifest::json());
        $validator->validate(AmtReferenceManifest::mutate(static function (\stdClass $manifest): void {
            $manifest->files[0]->name = '../escaped.md';
            $manifest->files[1]->content = '<script>steal()</script>';
        }));

        $this->assertSame($before, $this->storageSnapshot(), 'Validation must not create, move or delete any file.');
    }

    /**
     * Garde statique : le namespace du Validator ne contient AUCUN transport
     * sortant, aucune facade et aucune ecriture de fichier.
     *
     * Le test runtime ci-dessus mesure ce qui se passe sur DEUX documents ; ce
     * test-ci ferme la question pour tous les autres, y compris ceux que
     * personne n'a encore imagines. C'est aussi lui qui rougira si une TASK
     * future branche le Validator sur un modele ou un provider — ce serait
     * alors une decision de revue, jamais un effet de bord silencieux.
     */
    public function test_the_validator_namespace_contains_no_write_and_no_transport(): void
    {
        $forbidden = [
            'DB::' => 'database facade',
            'Illuminate\\' => 'framework dependency',
            'Http::' => 'outgoing HTTP transport',
            'Storage::' => 'storage facade',
            'file_put_contents' => 'file write',
            'fopen(' => 'file handle',
            'fwrite(' => 'file write',
            'unlink(' => 'file deletion',
            'mkdir(' => 'directory creation',
            'curl_' => 'curl transport',
            'stream_socket' => 'socket transport',
            'fsockopen' => 'socket transport',
            'updateOrCreate' => 'Eloquent write',
            'firstOrCreate' => 'Eloquent write',
            '->save(' => 'Eloquent write',
            'Eloquent' => 'Eloquent model',
        ];

        $violations = [];
        $directory = dirname(__DIR__, 3).'/'.self::NAMESPACE_DIRECTORY;

        foreach (glob($directory.'/*.php') ?: [] as $file) {
            $source = (string) file_get_contents($file);
            $relative = self::NAMESPACE_DIRECTORY.'/'.basename($file);

            foreach ($forbidden as $needle => $reason) {
                if (str_contains($source, $needle)) {
                    $violations[] = sprintf('%s contains %s (%s)', $relative, $needle, $reason);
                }
            }
        }

        $this->assertSame([], $violations);
    }

    public function test_the_validator_namespace_reads_only_its_own_versioned_avatar_index(): void
    {
        // La seule lecture disque admise par la spec 9.1 est le "catalogue
        // local d'avatars". Elle est donc CONCENTREE dans une classe, pour
        // qu'aucune autre ne puisse lire un chemin sans que ce test le dise.
        $readers = [];
        $directory = dirname(__DIR__, 3).'/'.self::NAMESPACE_DIRECTORY;

        foreach (glob($directory.'/*.php') ?: [] as $file) {
            if (str_contains((string) file_get_contents($file), 'file_get_contents')) {
                $readers[] = basename($file);
            }
        }

        $this->assertSame(['ManifestAvatarBank.php'], $readers);
    }

    /**
     * @return list<string>
     */
    private function storageSnapshot(): array
    {
        $root = dirname(__DIR__, 3).'/storage/app';

        if (! is_dir($root)) {
            return [];
        }

        $paths = [];
        // CATCH_GET_CHILD : le `storage/app` d'un poste de developpement
        // contient des sous-dossiers que l'utilisateur courant ne peut pas
        // ouvrir. Les traverser ferait echouer le test pour une raison qui
        // n'a rien a voir avec le Validator ; le meme filtre s'applique avant
        // et apres, donc la comparaison reste exacte sur ce qui est lisible.
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
            \RecursiveIteratorIterator::CATCH_GET_CHILD,
        );

        foreach ($iterator as $entry) {
            $paths[] = $entry->getPathname();
        }

        sort($paths);

        return $paths;
    }
}
