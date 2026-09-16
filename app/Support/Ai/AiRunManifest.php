<?php

namespace App\Support\Ai;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * TASK-1583 / CDC-02 TRACE-1B — le MANIFESTE d'un run.
 *
 * Un run = un groupe de tours produits par une meme execution (serie CLI,
 * session navigateur, scenario de Lab). Son identite vit dans un fichier
 * JSON, `storage/app/ai-lab/runs/<run_id>.json` — aucune table, aucune
 * colonne (J6, DO NOT BUILD YET) : le manifeste est l'AUTORITE de la liste
 * des tours, le lien `turn.run` (schema 2) n'est que le secours.
 *
 * Le fichier ne porte aucun contenu de message, aucun secret (I9) : des ids,
 * des horodatages, la version de l'application.
 */
final class AiRunManifest
{
    public const DIRECTORY = 'ai-lab/runs';

    public const SCHEMA = 1;

    public static function path(string $runId): string
    {
        return storage_path('app/'.self::DIRECTORY.'/'.$runId.'.json');
    }

    public static function exists(string $runId): bool
    {
        return Str::isUuid($runId) && File::exists(self::path($runId));
    }

    /**
     * Ouvre (ou rouvre) le manifeste d'un run. Rejouer `start()` sur un run
     * existant ne l'ecrase pas : la serie CLI est une suite d'invocations.
     *
     * @return array<string, mixed>
     */
    public static function start(string $runId, string $kind, string $organizationId, ?string $labScenarioKey = null): array
    {
        if (! Str::isUuid($runId)) {
            throw new \InvalidArgumentException('run_id doit etre un uuid.');
        }

        $existant = self::load($runId);

        if ($existant !== null) {
            if ($existant['organization_id'] !== $organizationId) {
                // Un run appartient a UNE Organization : on ne le rouvre pas
                // depuis une autre, et on ne dit rien de plus (J7).
                throw new \RuntimeException('Ce run n\'appartient pas a cette Organization.');
            }

            // TASK-1588 — un run qu'on ne pourra pas COMPLETER (`addTurn` apres
            // le tour) est refuse A L'OUVERTURE : jamais un provider appele
            // pour un tour qui ne rejoindra pas son manifeste.
            if (! is_writable(self::path($runId))) {
                throw new \RuntimeException('Manifeste de run inaccessible en ecriture : fichier '.self::path($runId).' (proprietaire/mode ?).');
            }

            return $existant;
        }

        $manifeste = [
            'schema' => self::SCHEMA,
            'run_id' => $runId,
            'run_kind' => $kind,
            'lab_scenario_key' => $labScenarioKey,
            'organization_id' => $organizationId,
            'started_at' => now()->toIso8601String(),
            'ended_at' => null,
            'turns' => [],
            'tool_versions' => self::toolVersions(),
        ];

        self::save($manifeste);

        return $manifeste;
    }

    /**
     * Ajoute un tour au manifeste — des identifiants seulement.
     *
     * @param  array{turn_id?: ?string, interaction_id?: ?string, loop_message_id?: ?string, shell_message_id?: ?string}  $refs
     * @return array<string, mixed>
     */
    public static function addTurn(string $runId, array $refs): array
    {
        $manifeste = self::load($runId);

        if ($manifeste === null) {
            throw new \RuntimeException('Manifeste introuvable : start() d\'abord.');
        }

        $manifeste['turns'][] = [
            'order' => count($manifeste['turns']) + 1,
            'turn_id' => $refs['turn_id'] ?? null,
            'interaction_id' => $refs['interaction_id'] ?? null,
            'loop_message_id' => $refs['loop_message_id'] ?? null,
            'shell_message_id' => $refs['shell_message_id'] ?? null,
            'recorded_at' => now()->toIso8601String(),
        ];
        $manifeste['ended_at'] = now()->toIso8601String();

        self::save($manifeste);

        return $manifeste;
    }

    /**
     * Lit un manifeste ; `null` s'il n'existe pas ; leve s'il est CORROMPU
     * (s2 : refus explicite, jamais une lecture partielle).
     *
     * @return array<string, mixed>|null
     */
    public static function load(string $runId): ?array
    {
        if (! self::exists($runId)) {
            return null;
        }

        // TASK-1588 (review Opus F1) — un manifeste present mais ILLISIBLE
        // (mode/proprietaire) est dit en clair, jamais une ErrorException.
        if (! is_readable(self::path($runId))) {
            throw new \RuntimeException('Manifeste de run inaccessible en lecture : '.self::path($runId).' (proprietaire/mode ?).');
        }

        try {
            $brut = (string) File::get(self::path($runId));
        } catch (\Throwable $exception) {
            throw new \RuntimeException('Manifeste de run inaccessible en lecture : '.self::path($runId).' — '.$exception->getMessage(), 0, $exception);
        }
        $decode = json_decode($brut, true);

        if (! is_array($decode) || ($decode['run_id'] ?? null) !== $runId || ! is_array($decode['turns'] ?? null) || ! is_string($decode['organization_id'] ?? null)) {
            throw new \RuntimeException("Manifeste corrompu : {$runId}");
        }

        return $decode;
    }

    /**
     * Mode des repertoires et fichiers du manifeste : le meme contrat que le
     * disque `dossier_files` (`config/filesystems.php`, `private => 0770`) —
     * les repertoires de `storage/` portent le setgid du groupe du serveur web,
     * et la CLI (l'operateur) comme HTTP (`www-data`) doivent pouvoir ecrire
     * le MEME manifeste. Sans cela, un repertoire cree par la CLI (umask 022 ->
     * 0755) est illisible en ecriture pour le serveur web : `Permission denied`
     * en HTTP, page 500 (TASK-1588, constate sur test.laravel).
     */
    public const DIRECTORY_MODE = 0770;

    public const FILE_MODE = 0660;

    /**
     * Ecrit le manifeste — ou LEVE une `RuntimeException` explicite. Une
     * impossibilite d'ecrire (repertoire non creable, non inscriptible, disque
     * plein) n'est jamais une `ErrorException` brute : l'appelant doit pouvoir
     * refuser proprement AVANT tout appel provider (`start()` precede toute
     * execution dans `AiTurnExecutor`).
     *
     * @param  array<string, mixed>  $manifeste
     */
    private static function save(array $manifeste): void
    {
        $path = self::path($manifeste['run_id']);
        $directory = dirname($path);
        $json = (string) json_encode($manifeste, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        try {
            if (! is_dir($directory)) {
                // `mkdir` applique l'umask (022 -> 0750) : le mode voulu est
                // pose EXPLICITEMENT sur chaque repertoire cree ici.
                $crees = [];
                for ($d = $directory; ! is_dir($d) && $d !== dirname($d); $d = dirname($d)) {
                    $crees[] = $d;
                }
                File::ensureDirectoryExists($directory, self::DIRECTORY_MODE);
                foreach ($crees as $d) {
                    @chmod($d, self::DIRECTORY_MODE);
                }
            }

            if (! is_dir($directory) || ! is_writable($directory)) {
                throw new \RuntimeException("Manifeste de run inaccessible en ecriture : repertoire {$directory} (proprietaire/mode ?).");
            }

            if (file_exists($path) && ! is_writable($path)) {
                throw new \RuntimeException("Manifeste de run inaccessible en ecriture : fichier {$path} (proprietaire/mode ?).");
            }

            $etaitAbsent = ! file_exists($path);

            if (File::put($path, $json) === false) {
                throw new \RuntimeException("Manifeste de run non ecrit : {$path}.");
            }

            if ($etaitAbsent) {
                // Le fichier appartient a celui qui l'a cree ; le groupe (serveur
                // web ou operateur) doit pouvoir le completer (`addTurn`).
                @chmod($path, self::FILE_MODE);
            }
        } catch (\RuntimeException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            // `mkdir`/`file_put_contents` remontent des avertissements que le
            // handler transforme en ErrorException : on les dit en clair.
            throw new \RuntimeException("Manifeste de run inaccessible en ecriture : {$path} — ".$exception->getMessage(), 0, $exception);
        }
    }

    /** @return array{app_version: ?string, sha: ?string} */
    private static function toolVersions(): array
    {
        $version = File::exists(base_path('VERSION')) ? trim((string) File::get(base_path('VERSION'))) : null;
        $head = base_path('.git/HEAD');
        $sha = null;

        if (File::exists($head)) {
            $ref = trim((string) File::get($head));
            $sha = str_starts_with($ref, 'ref: ') && File::exists(base_path('.git/'.substr($ref, 5)))
                ? trim((string) File::get(base_path('.git/'.substr($ref, 5))))
                : $ref;
        }

        return ['app_version' => $version, 'sha' => $sha !== null ? substr($sha, 0, 12) : null];
    }
}
