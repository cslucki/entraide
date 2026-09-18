<?php

namespace App\Console\Commands;

use App\Support\AiLab\LabScenario;
use Illuminate\Console\Command;

/**
 * TASK-1590 / CDC-NIGHT L-B_CORE — `ai:lab:list` : le catalogue des scenarios
 * de Lab, VALIDES a la lecture. READ ONLY : aucune base, aucune execution.
 *
 * Un fichier invalide est nomme avec ses erreurs et rend la commande rouge :
 * un catalogue partiellement valide n'est pas un catalogue.
 */
class AiLabListCommand extends Command
{
    protected $signature = 'ai:lab:list
        {--dir= : Repertoire des scenarios (defaut : database/scenario-packs/ai-lab/scenarios)}
        {--json : Sortie JSON machine-readable}';

    protected $description = 'Liste et valide les scenarios de Lab (CDC-03 §5.1 / CDC-NIGHT catalogue CORE).';

    public function handle(): int
    {
        $directory = $this->option('dir') !== null ? (string) $this->option('dir') : LabScenario::directory();
        $files = glob(rtrim($directory, '/').'/*.json') ?: [];
        sort($files);

        $lignes = [];
        $erreurs = [];
        $cles = [];

        foreach ($files as $file) {
            $decoded = json_decode((string) file_get_contents($file), true);
            $errors = is_array($decoded) ? LabScenario::validate($decoded) : ['fichier non JSON'];
            $key = is_array($decoded) && is_string($decoded['lab_scenario_key'] ?? null) ? $decoded['lab_scenario_key'] : basename($file, '.json');

            if ($errors === [] && basename($file, '.json') !== $key) {
                $errors[] = "nom de fichier ≠ lab_scenario_key ({$key})";
            }
            if (isset($cles[$key])) {
                $errors[] = "cle dupliquee : {$key}";
            }
            $cles[$key] = true;

            $lignes[] = [
                'lab_scenario_key' => $key,
                'organization' => $decoded['organization'] ?? null,
                'loop' => $decoded['loop'] ?? null,
                'user' => $decoded['user'] ?? null,
                'turns' => is_array($decoded['turns'] ?? null) ? count($decoded['turns']) : null,
                'modes' => is_array($decoded['turns'] ?? null) ? implode('→', array_column($decoded['turns'], 'mode')) : null,
                'answer_class' => $decoded['expected']['answer']['class'] ?? null,
                'expected_turn' => $decoded['expected']['turn'] ?? null,
                'execution' => $decoded['execution'] ?? null,
                'valid' => $errors === [],
                'errors' => $errors,
                'file' => basename($file),
            ];

            if ($errors !== []) {
                $erreurs[$key] = $errors;
            }
        }

        if ($this->option('json')) {
            $this->line((string) json_encode(['directory' => $directory, 'count' => count($lignes), 'valid' => $erreurs === [], 'scenarios' => $lignes], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return $erreurs === [] ? self::SUCCESS : self::FAILURE;
        }

        $this->line('');
        $this->info(sprintf('── SCENARIOS DE LAB (%d) — %s', count($lignes), $directory));
        $this->table(
            ['clé', 'org', 'loop', 'user', 'tours', 'modes', 'classe', 'turn', 'exécution', 'valide'],
            array_map(static fn (array $l): array => [$l['lab_scenario_key'], $l['organization'], $l['loop'], $l['user'], $l['turns'], $l['modes'], $l['answer_class'], $l['expected_turn'], $l['execution'], $l['valid'] ? 'oui' : 'NON'], $lignes),
        );

        foreach ($erreurs as $key => $errors) {
            $this->error("  {$key} :");
            foreach ($errors as $error) {
                $this->line("    - {$error}");
            }
        }

        if ($lignes === []) {
            $this->warn('  aucun scenario.');
        }
        $this->line('');

        return $erreurs === [] && $lignes !== [] ? self::SUCCESS : self::FAILURE;
    }
}
