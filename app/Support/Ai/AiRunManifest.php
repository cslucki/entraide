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

        $brut = (string) File::get(self::path($runId));
        $decode = json_decode($brut, true);

        if (! is_array($decode) || ($decode['run_id'] ?? null) !== $runId || ! is_array($decode['turns'] ?? null) || ! is_string($decode['organization_id'] ?? null)) {
            throw new \RuntimeException("Manifeste corrompu : {$runId}");
        }

        return $decode;
    }

    /** @param  array<string, mixed>  $manifeste */
    private static function save(array $manifeste): void
    {
        File::ensureDirectoryExists(dirname(self::path($manifeste['run_id'])));
        File::put(self::path($manifeste['run_id']), (string) json_encode($manifeste, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
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
