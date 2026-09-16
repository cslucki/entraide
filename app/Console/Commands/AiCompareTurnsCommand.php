<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Support\Ai\AiTurnComparison;
use App\Support\Ai\AiTurnLocator;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * TASK-1584 / CDC-02 TRACE-1C — `ai:compare-turns` : deux tours se comparent.
 *
 * READ ONLY. Les deux cles (uuid d'interaction, de LoopMessage ou de ligne
 * Shell) sont resolues dans la MEME Organization ; la comparaison est celle
 * d'`AiTurnComparison` — identites a part, premiere etape divergente sur le
 * contrat a 8 etapes, divergences classees. Aucun jugement sur le texte.
 */
class AiCompareTurnsCommand extends Command
{
    protected $signature = 'ai:compare-turns
        {a : uuid du tour A (interaction, message de Loop ou ligne Shell)}
        {b : uuid du tour B}
        {--organization= : Slug ou UUID de l\'Organization des deux tours}
        {--json : Sortie JSON machine-readable}';

    protected $description = 'Compare deux tours persistes (TRACE-1C) : ecarts d\'identite, premiere etape divergente, divergences classees.';

    public function handle(): int
    {
        $organization = $this->resoudreOrganization();

        if ($organization === null) {
            return $this->refuser('Organization introuvable.');
        }

        $cleA = trim((string) $this->argument('a'));
        $cleB = trim((string) $this->argument('b'));

        foreach (['a' => $cleA, 'b' => $cleB] as $nom => $cle) {
            if (! Str::isUuid($cle)) {
                return $this->refuser("<{$nom}> doit etre un uuid.");
            }
        }

        $tourA = AiTurnLocator::locate($organization, $cleA);
        $tourB = AiTurnLocator::locate($organization, $cleB);

        if ($tourA === null || $tourB === null) {
            $manquants = implode(', ', array_keys(array_filter(['a' => $tourA === null, 'b' => $tourB === null])));

            return $this->refuser("Aucun tour persiste ne correspond a la cle {$manquants} dans cette Organization.");
        }

        $comparaison = AiTurnComparison::compare($tourA['inspection'], $tourB['inspection']);
        $comparaison['a']['key'] = $cleA;
        $comparaison['a']['key_kind'] = $tourA['kind'];
        $comparaison['b']['key'] = $cleB;
        $comparaison['b']['key_kind'] = $tourB['kind'];

        if ($this->option('json')) {
            $this->line((string) json_encode($comparaison, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->rendre($comparaison);

        return self::SUCCESS;
    }

    /** @param  array<string, mixed>  $c */
    private function rendre(array $c): void
    {
        $this->line('');
        $this->info('── COMPARAISON');
        $this->line(sprintf('  A  %-36s turn=%s schema=%s', $c['a']['ai_interaction_id'] ?? $c['a']['key'], $c['a']['turn_id'] ?? 'UNAVAILABLE', $c['a']['turn_schema'] ?? 'UNAVAILABLE'));
        $this->line(sprintf('  B  %-36s turn=%s schema=%s', $c['b']['ai_interaction_id'] ?? $c['b']['key'], $c['b']['turn_id'] ?? 'UNAVAILABLE', $c['b']['turn_schema'] ?? 'UNAVAILABLE'));

        if ($c['comparable'] !== true) {
            $this->warn('  NON COMPARABLE : '.$c['reason'].' — un des deux tours n\'a pas de bloc turn ; rien n\'est dit de leur ressemblance.');
            $this->line('');

            return;
        }

        if ($c['schema_note'] !== null) {
            $this->warn('  '.$c['schema_note']);
        }

        $this->info('── IDENTITE');
        if ($c['identity_differences'] === []) {
            $this->line('  identiques');
        }
        foreach ($c['identity_differences'] as $d) {
            $this->line(sprintf('  %-28s %s → %s', $d['field'], $this->aff($d['a']), $this->aff($d['b'])));
        }

        $this->info('── ETAPES (contrat a '.count(AiTurnComparison::STEPS).')');
        foreach ($c['steps'] as $s) {
            $this->line(sprintf('  %s %-22s %-16s %-36s | %-16s %s',
                $s['divergent'] ? '≠' : '=',
                $s['name'],
                $s['a']['status'] ?? '—', $s['a']['reason_code'] ?? '',
                $s['b']['status'] ?? '—', $s['b']['reason_code'] ?? ''));
        }
        $this->line(sprintf('  %-28s %s', 'first_divergent_step', $c['first_divergent_step'] ?? 'null (etapes alignees)'));

        $this->info('── DIVERGENCES ('.count($c['divergences']).')');
        foreach ($c['divergences'] as $d) {
            if (str_starts_with($d['field'], 'steps.')) {
                continue;
            }
            $this->line(sprintf('  %-10s %-40s %s → %s', $d['class'], $d['field'], $this->aff($d['a']), $this->aff($d['b'])));
        }
        $this->line(sprintf('  %-28s %s', 'classes', $c['divergence_classes'] === [] ? 'aucune' : implode(', ', $c['divergence_classes'])));

        $this->info('── TIMING');
        foreach ($c['timing'] as $nom => $t) {
            $this->line(sprintf('  %-28s %s | %s  [%s]', $nom, $this->aff($t['a']), $this->aff($t['b']), $t['label']));
        }
        $this->line('');
    }

    private function aff(mixed $v): string
    {
        return match (true) {
            $v === null => 'UNAVAILABLE',
            is_bool($v) => $v ? 'true' : 'false',
            is_array($v) => Str::limit((string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 80),
            default => (string) $v,
        };
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
