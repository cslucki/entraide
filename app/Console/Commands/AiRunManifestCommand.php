<?php

namespace App\Console\Commands;

use App\Models\AiInteraction;
use App\Models\Organization;
use App\Support\Ai\AiRunManifest;
use App\Support\Ai\AiTurnInspection;
use App\Support\Ai\AiTurnTrace;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * TASK-1583 / CDC-02 TRACE-1B — `ai:run-manifest` : relire un run.
 *
 * READ ONLY. Le manifeste (fichier) est l'autorite de la liste des tours ;
 * la requete de secours (`metadata->turn->run->id`, clé JSON non indexee)
 * retrouve ce que les blocs `turn` portent. Tout ecart entre les deux est
 * RAPPORTE, jamais reconcilie en silence.
 *
 * Tenant : l'Organization donnee doit etre celle du manifeste ; la requete de
 * secours est bornee a cette Organization. Un manifeste corrompu est refuse
 * explicitement (s2).
 */
class AiRunManifestCommand extends Command
{
    protected $signature = 'ai:run-manifest
        {--organization= : Slug ou UUID de l\'Organization du run}
        {--run= : uuid du run (storage/app/ai-lab/runs/<run_id>.json)}
        {--json : Sortie JSON machine-readable}';

    protected $description = 'Relit le manifeste d\'un run (TRACE-1B) : ses tours avec leur execution_path/status, et l\'ecart eventuel avec les blocs turn qui portent ce run.';

    public function handle(): int
    {
        $runId = trim((string) $this->option('run'));

        if (! Str::isUuid($runId)) {
            return $this->refuser('--run doit etre un uuid.');
        }

        $organization = $this->resoudreOrganization();

        if ($organization === null) {
            return $this->refuser('Organization introuvable.');
        }

        try {
            $manifeste = AiRunManifest::load($runId);
        } catch (\RuntimeException $exception) {
            return $this->refuser($exception->getMessage());
        }

        if ($manifeste === null || $manifeste['organization_id'] !== (string) $organization->id) {
            // Introuvable ICI — sans dire s'il existe ailleurs (J7).
            return $this->refuser('Aucun manifeste pour ce run dans cette Organization.');
        }

        $tours = [];

        foreach ($manifeste['turns'] as $entree) {
            $interaction = is_string($entree['interaction_id'] ?? null) && Str::isUuid($entree['interaction_id'])
                ? AiInteraction::query()->where('organization_id', (string) $organization->id)->whereKey($entree['interaction_id'])->first()
                : null;
            $trace = $interaction instanceof AiInteraction ? AiTurnInspection::fromPersistedTurn($interaction) : null;

            $tours[] = [
                'order' => $entree['order'] ?? null,
                'turn_id' => $entree['turn_id'] ?? null,
                'interaction_id' => $entree['interaction_id'] ?? null,
                'loop_message_id' => $entree['loop_message_id'] ?? null,
                'shell_message_id' => $entree['shell_message_id'] ?? null,
                'execution_path' => $trace['identity']['execution_path'] ?? null,
                'status' => $trace['decision']['status'] ?? null,
                'reason_code' => $trace['decision']['reason_code'] ?? null,
                'turn_run_id' => $trace['run']['run_id'] ?? null,
                'turn_available' => $trace !== null && ($trace['run']['turn_id'] ?? null) !== null,
            ];
        }

        // Requete de secours : les tours dont le bloc porte ce run. Portable
        // json/jsonb, non indexee — c'est un secours, pas la voie normale.
        $secours = AiInteraction::query()
            ->where('organization_id', (string) $organization->id)
            ->where('metadata->'.AiTurnTrace::TURN_METADATA_KEY.'->run->id', $runId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->all();

        $manifesteIds = array_values(array_filter(array_map(static fn (array $t): ?string => $t['interaction_id'], $tours)));

        $sortie = [
            'run_id' => $runId,
            'run_kind' => $manifeste['run_kind'],
            'lab_scenario_key' => $manifeste['lab_scenario_key'],
            'organization_id' => $manifeste['organization_id'],
            'started_at' => $manifeste['started_at'],
            'ended_at' => $manifeste['ended_at'],
            'tool_versions' => $manifeste['tool_versions'] ?? null,
            'turns' => $tours,
            'fallback_query' => [
                'interaction_ids' => $secours,
                'only_in_manifest' => array_values(array_diff($manifesteIds, $secours)),
                'only_in_turn_blocks' => array_values(array_diff($secours, $manifesteIds)),
                'consistent' => array_diff($manifesteIds, $secours) === [] && array_diff($secours, $manifesteIds) === [],
            ],
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($sortie, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line('');
        $this->info(sprintf('── RUN %s (%s%s)', $runId, $manifeste['run_kind'], $manifeste['lab_scenario_key'] !== null ? ', '.$manifeste['lab_scenario_key'] : ''));
        $this->line(sprintf('  %-16s %s', 'organization', $organization->slug));
        $this->line(sprintf('  %-16s %s → %s', 'periode', $manifeste['started_at'], $manifeste['ended_at'] ?? '…'));
        $this->line(sprintf('  %-16s %s', 'tours', count($tours)));
        foreach ($tours as $t) {
            $this->line(sprintf('  #%-3s %-36s %-28s %-10s %s', $t['order'], $t['interaction_id'] ?? 'null', $t['execution_path'] ?? 'UNAVAILABLE', $t['status'] ?? 'UNAVAILABLE', $t['turn_run_id'] === $runId ? '' : '(turn.run ≠ manifeste)'));
        }
        $this->info('── SECOURS (metadata->turn->run->id)');
        $this->line(sprintf('  %-16s %d', 'retrouves', count($secours)));
        $this->line(sprintf('  %-16s %s', 'coherent', $sortie['fallback_query']['consistent'] ? 'oui' : 'NON — '.count($sortie['fallback_query']['only_in_manifest']).' seulement au manifeste, '.count($sortie['fallback_query']['only_in_turn_blocks']).' seulement dans les blocs'));
        $this->line('');

        return self::SUCCESS;
    }

    private function resoudreOrganization(): ?Organization
    {
        $cle = trim((string) $this->option('organization'));

        if ($cle === '') {
            return null;
        }

        return Organization::query()->where('slug', $cle)->first()
            ?? (Str::isUuid($cle) ? Organization::query()->find($cle) : null);
    }

    private function refuser(string $message): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode(['refused' => true, 'message' => $message], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            $this->error($message);
        }

        return self::FAILURE;
    }
}
