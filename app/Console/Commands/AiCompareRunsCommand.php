<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Support\Ai\AiRunManifest;
use App\Support\Ai\AiTurnComparison;
use App\Support\Ai\AiTurnLocator;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * TASK-1584 / CDC-02 TRACE-1C — `ai:compare-runs` : deux runs se comparent,
 * tour a tour.
 *
 * READ ONLY. Les deux manifestes (TRACE-1B) sont l'autorite de la liste des
 * tours ; les tours s'apparient par `order` — le n-ieme de A face au n-ieme de
 * B. Un ordre present d'un seul cote est RAPPORTE comme non apparie, jamais
 * complete. Chaque paire passe par `AiTurnComparison` ; le resume compte les
 * paires par premiere etape divergente et par classe, sans jugement.
 *
 * Tenant : les deux manifestes doivent appartenir a l'Organization donnee.
 */
class AiCompareRunsCommand extends Command
{
    protected $signature = 'ai:compare-runs
        {a : uuid du run A}
        {b : uuid du run B}
        {--organization= : Slug ou UUID de l\'Organization des deux runs}
        {--json : Sortie JSON machine-readable}';

    protected $description = 'Compare deux runs (TRACE-1C) : leurs tours apparies par ordre, chaque paire comparee, un resume par etape divergente et par classe.';

    public function handle(): int
    {
        $organization = $this->resoudreOrganization();

        if ($organization === null) {
            return $this->refuser('Organization introuvable.');
        }

        $runA = trim((string) $this->argument('a'));
        $runB = trim((string) $this->argument('b'));

        foreach (['a' => $runA, 'b' => $runB] as $nom => $run) {
            if (! Str::isUuid($run)) {
                return $this->refuser("<{$nom}> doit etre un uuid de run.");
            }
        }

        try {
            $manifesteA = AiRunManifest::load($runA);
            $manifesteB = AiRunManifest::load($runB);
        } catch (\RuntimeException $exception) {
            return $this->refuser($exception->getMessage());
        }

        foreach (['a' => $manifesteA, 'b' => $manifesteB] as $nom => $manifeste) {
            if ($manifeste === null || $manifeste['organization_id'] !== (string) $organization->id) {
                return $this->refuser("Aucun manifeste pour le run {$nom} dans cette Organization.");
            }
        }

        $parOrdreA = $this->parOrdre($manifesteA['turns']);
        $parOrdreB = $this->parOrdre($manifesteB['turns']);
        $ordres = array_unique([...array_keys($parOrdreA), ...array_keys($parOrdreB)]);
        sort($ordres);

        $paires = [];
        $nonApparies = [];
        $parEtape = [];
        $parClasse = [];

        foreach ($ordres as $ordre) {
            $entreeA = $parOrdreA[$ordre] ?? null;
            $entreeB = $parOrdreB[$ordre] ?? null;

            if ($entreeA === null || $entreeB === null) {
                $nonApparies[] = ['order' => $ordre, 'only_in' => $entreeA === null ? 'b' : 'a'];

                continue;
            }

            $tourA = $this->localiser($organization, $entreeA);
            $tourB = $this->localiser($organization, $entreeB);

            if ($tourA === null || $tourB === null) {
                $paires[] = ['order' => $ordre, 'comparable' => false, 'reason' => 'turn_not_found', 'a' => $entreeA['interaction_id'] ?? $entreeA['shell_message_id'] ?? null, 'b' => $entreeB['interaction_id'] ?? $entreeB['shell_message_id'] ?? null];

                continue;
            }

            $comparaison = AiTurnComparison::compare($tourA['inspection'], $tourB['inspection']);
            $paires[] = ['order' => $ordre] + $comparaison;

            if ($comparaison['comparable']) {
                $etape = $comparaison['first_divergent_step'] ?? 'aligned';
                $parEtape[$etape] = ($parEtape[$etape] ?? 0) + 1;
                foreach ($comparaison['divergence_classes'] as $classe) {
                    $parClasse[$classe] = ($parClasse[$classe] ?? 0) + 1;
                }
            } else {
                $parEtape['unavailable'] = ($parEtape['unavailable'] ?? 0) + 1;
            }
        }

        $sortie = [
            'run_a' => ['run_id' => $runA, 'run_kind' => $manifesteA['run_kind'], 'lab_scenario_key' => $manifesteA['lab_scenario_key'], 'turns' => count($manifesteA['turns'])],
            'run_b' => ['run_id' => $runB, 'run_kind' => $manifesteB['run_kind'], 'lab_scenario_key' => $manifesteB['lab_scenario_key'], 'turns' => count($manifesteB['turns'])],
            'pairs' => $paires,
            'unpaired' => $nonApparies,
            'summary' => [
                'pairs' => count($paires),
                'unpaired' => count($nonApparies),
                'by_first_divergent_step' => $parEtape,
                'by_class' => $parClasse,
            ],
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($sortie, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line('');
        $this->info(sprintf('── RUN A %s (%s, %d tours)  vs  RUN B %s (%s, %d tours)', $runA, $manifesteA['run_kind'], count($manifesteA['turns']), $runB, $manifesteB['run_kind'], count($manifesteB['turns'])));
        foreach ($paires as $p) {
            if (($p['comparable'] ?? false) !== true) {
                $this->line(sprintf('  #%-3s NON COMPARABLE (%s)', $p['order'], $p['reason']));

                continue;
            }
            $this->line(sprintf('  #%-3s first_divergent_step=%-22s identite=%-2d divergences=%-3d classes=%s',
                $p['order'],
                $p['first_divergent_step'] ?? 'null',
                count($p['identity_differences']),
                count($p['divergences']),
                $p['divergence_classes'] === [] ? 'aucune' : implode(',', $p['divergence_classes'])));
        }
        foreach ($nonApparies as $n) {
            $this->line(sprintf('  #%-3s NON APPARIE (seulement dans %s)', $n['order'], $n['only_in']));
        }
        $this->info('── RESUME');
        $this->line(sprintf('  %-28s %d apparies, %d non apparies', 'paires', count($paires), count($nonApparies)));
        foreach ($parEtape as $etape => $n) {
            $this->line(sprintf('  %-28s %d', 'first='.$etape, $n));
        }
        foreach ($parClasse as $classe => $n) {
            $this->line(sprintf('  %-28s %d', 'class='.$classe, $n));
        }
        $this->line('');

        return self::SUCCESS;
    }

    /**
     * @param  list<array<string, mixed>>  $tours
     * @return array<int, array<string, mixed>>
     */
    private function parOrdre(array $tours): array
    {
        $index = [];
        foreach ($tours as $t) {
            $ordre = $t['order'] ?? null;
            if (is_int($ordre) && ! isset($index[$ordre])) {
                $index[$ordre] = $t;
            }
        }

        return $index;
    }

    /**
     * @param  array<string, mixed>  $entree
     * @return array<string, mixed>|null
     */
    private function localiser(Organization $organization, array $entree): ?array
    {
        foreach (['interaction_id', 'shell_message_id', 'loop_message_id'] as $cle) {
            if (is_string($entree[$cle] ?? null) && Str::isUuid($entree[$cle])) {
                $tour = AiTurnLocator::locate($organization, $entree[$cle]);
                if ($tour !== null) {
                    return $tour;
                }
            }
        }

        return null;
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
