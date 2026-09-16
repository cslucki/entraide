<?php

namespace App\Console\Commands;

use App\Support\AiLab\LabRunner;
use App\Support\AiLab\LabScenario;
use App\Support\AiLab\LabVerdict;
use Illuminate\Console\Command;

/**
 * TASK-1591 / CDC-NIGHT L-C_CORE — `ai:lab:run` : UN scenario, UN run, UN
 * verdict PASS | FAIL | UNAVAILABLE avec `first_failed_component`.
 *
 * La commande n'est qu'une ENVELOPPE de `LabRunner` (jamais `Artisan::call`
 * comme API interne, aucun pipeline duplique). Elle APPELLE des fournisseurs
 * IA et FACTURE l'Organization du Lab au tarif d'un membre : aucune economie
 * speciale. Un FAIL est un resultat, pas une erreur de commande : le code de
 * sortie ne dit rouge que si le scenario est introuvable ou invalide.
 */
class AiLabRunCommand extends Command
{
    protected $signature = 'ai:lab:run
        {--scenario= : Cle du scenario (ex. LAB.POSITIVE_SIMPLE_1)}
        {--dir= : Repertoire des scenarios (defaut : database/scenario-packs/ai-lab/scenarios)}
        {--json : Sortie JSON machine-readable}';

    protected $description = 'Execute UN scenario de Lab sur les vrais services et rend PASS | FAIL | UNAVAILABLE (CDC-NIGHT L-C_CORE).';

    public function handle(LabRunner $runner): int
    {
        $key = (string) $this->option('scenario');
        if ($key === '') {
            $this->error('--scenario=<KEY> est obligatoire.');

            return self::INVALID;
        }

        $directory = $this->option('dir') !== null ? (string) $this->option('dir') : LabScenario::directory();
        try {
            $scenario = LabScenario::find($key, $directory);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        }
        if (! $scenario instanceof LabScenario) {
            $this->error("Scenario introuvable : {$key} ({$directory}).");

            return self::INVALID;
        }

        $result = $runner->run($scenario);

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line('');
        $this->info("── LAB RUN — {$result['lab_scenario_key']}");
        $this->table(['champ', 'valeur'], [
            ['organization / loop / user', "{$result['organization']} / {$result['loop']} / {$result['user']}"],
            ['surface.mode', $result['surface_mode']],
            ['run_id', $result['run_id'] ?? '(aucun)'],
            ['PRECONDITIONS_MATCH_EXPECTED', $result['PRECONDITIONS_MATCH_EXPECTED']],
            ['attendu', "class={$result['expected']['answer_class']} turn={$result['expected']['turn']} access={$result['expected']['access']['status']}"],
            ['RESULT', $result['result']],
            ['first_failed_component', $result['first_failed_component'] ?? '(aucun)'],
            ['divergence_class', $result['divergence_class'] === [] ? '(aucune)' : implode(',', $result['divergence_class'])],
            ['leak', $result['leak'] ? 'OUI — STOP' : 'non'],
            ['unavailable_reason', $result['unavailable_reason'] ?? '(aucune)'],
        ]);

        foreach ($result['preconditions']['checks'] ?? [] as $name => $check) {
            $this->line(sprintf('  precondition %-22s %-12s attendu=%s observe=%s%s', $name, $check['status'], is_scalar($check['expected']) ? $check['expected'] : json_encode($check['expected']), is_scalar($check['actual']) ? $check['actual'] : json_encode($check['actual']), isset($check['detail']) ? " — {$check['detail']}" : ''));
        }

        foreach ($result['turns'] as $turn) {
            $this->line('');
            $this->line(sprintf('  tour %d : %s', $turn['order'], ($turn['not_established'] ?? false) ? 'NON ETABLI — '.($turn['refusal'] ?? '') : ($turn['refused'] ? 'REFUSE — '.($turn['refusal'] ?? '') : 'execute')));
            if (! $turn['refused']) {
                $this->line(sprintf('    turn_id=%s status=%s execution_path=%s bubble=%s', $turn['turn_id'] ?? '(aucun)', $turn['status'] ?? '(aucun)', $turn['execution_path'] ?? '(aucun)', $turn['bubble_id'] ?? '(aucune)'));
                if (($turn['manifest_failure'] ?? null) !== null) {
                    $this->warn('    tour execute et facture, mais NON inscrit au manifeste : '.$turn['manifest_failure']);
                }
                if (is_array($turn['history_derived'] ?? null)) {
                    $this->line('    history_derived : '.json_encode($turn['history_derived'], JSON_UNESCAPED_UNICODE));
                }
                $this->line('    reponse : '.mb_substr(trim((string) ($turn['response'] ?? '')), 0, 240));
            }
        }

        foreach ($result['divergences'] as $d) {
            $this->line(sprintf('  divergence [%s] %s : attendu=%s observe=%s%s', $d['component'], $d['field'], json_encode($d['expected'], JSON_UNESCAPED_UNICODE), json_encode($d['actual'], JSON_UNESCAPED_UNICODE), isset($d['detail']) ? " — {$d['detail']}" : ''));
        }

        if ($result['comparison'] !== null) {
            $this->line('  comparaison tours 1→2 : first_divergent_step='.($result['comparison']['first_divergent_step'] ?? '(aucune)'));
        }

        $this->line('');
        match ($result['result']) {
            LabVerdict::PASS => $this->info("  RESULT = PASS ({$result['lab_scenario_key']})"),
            LabVerdict::FAIL => $this->warn("  RESULT = FAIL ({$result['lab_scenario_key']}) — first_failed_component = {$result['first_failed_component']}"),
            default => $this->warn("  RESULT = UNAVAILABLE ({$result['lab_scenario_key']}) — {$result['unavailable_reason']}"),
        };
        $this->line('');

        return self::SUCCESS;
    }
}
