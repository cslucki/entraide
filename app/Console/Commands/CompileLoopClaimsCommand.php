<?php

namespace App\Console\Commands;

use App\Models\Loop;
use App\Models\Organization;
use App\Services\Knowledge\LoopClaimCompiler;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * TASK-1540 — compiler une conversation en ENONCES, a la demande.
 *
 * Le declencheur automatique de T1539 continue d'alimenter le digest : la
 * bascule ne se fera qu'une fois le retrieval claim-level prouve (CDC §9 —
 * « le chemin claim-level doit etre prouve avant reduction du digest »).
 *
 * Cette commande existe donc pour exercer et mesurer le nouveau chemin sans
 * engager le produit, exactement comme
 * `knowledge:derive-loop-conversations` l'a fait pour le digest.
 */
class CompileLoopClaimsCommand extends Command
{
    protected $signature = 'knowledge:compile-loop-claims
        {--organization= : slug ou identifiant de l\'Organization}
        {--loop= : identifiant d\'une seule Boucle}
        {--limit=20 : nombre maximum de Boucles traitees}';

    protected $description = 'Compile les conversations humaines en enonces adressables (ADD / UPDATE / RETRACT / KEEP).';

    public function handle(LoopClaimCompiler $compiler): int
    {
        $loops = $this->targetLoops();

        if ($loops === []) {
            $this->warn('Aucune Boucle ciblee.');

            return self::SUCCESS;
        }

        foreach ($loops as $loop) {
            $b = $compiler->compile($loop);

            if (! $b['applique']) {
                $this->line("  - {$loop->name} : {$b['raison']}");

                continue;
            }

            $this->info(sprintf('  + %s : +%d ~%d -%d (=%d)%s',
                $loop->name, $b['ajoutes'], $b['modifies'], $b['retractes'], $b['conserves'],
                $b['rejetees'] === [] ? '' : '  — '.count($b['rejetees']).' operation(s) rejetee(s)'));

            foreach ($b['rejetees'] as $rejet) {
                $this->line('      rejet : '.$rejet['raison']);
            }

            // TASK-1548 — une operation ecartee parce qu'elle rejouait un passe
            // deja corrige est une DECISION, pas un detail. La taire ferait
            // d'une garde deliberee un silence indistinguable d'une panne.
            if (($b['resurrections'] ?? 0) > 0) {
                $this->line(sprintf('      %d operation(s) ecartee(s) : frontiere temporelle de verite', $b['resurrections']));
            }
        }

        return self::SUCCESS;
    }

    /**
     * @return list<Loop>
     */
    private function targetLoops(): array
    {
        $query = Loop::query()->with('organization');

        if (is_string($loopId = $this->option('loop')) && $loopId !== '') {
            return $query->whereKey($loopId)->get()->all();
        }

        if (is_string($organization = $this->option('organization')) && $organization !== '') {
            // Meme garde qu'en T1537 : comparer une chaine non-UUID a une
            // colonne `uuid` fait tomber toute la requete sous PostgreSQL.
            $resolved = Organization::query()
                ->where(function ($q) use ($organization): void {
                    $q->where('slug', $organization);

                    if (Str::isUuid($organization)) {
                        $q->orWhere('id', $organization);
                    }
                })
                ->first();

            if ($resolved === null) {
                $this->error("Organization introuvable : {$organization}");

                return [];
            }

            $query->where('organization_id', $resolved->id);
        }

        return $query->limit(max(1, (int) $this->option('limit')))->get()->all();
    }
}
