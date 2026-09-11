<?php

namespace App\Console\Commands;

use App\Models\Loop;
use App\Models\Organization;
use App\Services\Knowledge\LoopConversationKnowledgeDeriver;
use Illuminate\Console\Command;

/**
 * TASK-1534 — declencher la derivation, explicitement et de facon bornee.
 *
 * ## Pourquoi une commande, et pas un observer sur `LoopMessage`
 *
 * Un observer deriverait a chaque message : un appel IA par phrase, y compris
 * pour « ok », « merci », « je regarde ». Le CDC CORE le dit dans ses propres
 * termes — « le systeme ne devrait pas appeler un modele apres chaque mutation
 * triviale si une consolidation differee produit un meilleur sens ».
 *
 * La forme automatique viendra, et la place est prete : ce service est
 * idempotent (empreinte de source) et sans etat. Mais un declencheur explicite
 * est la plus petite chose qui prouve la tranche, et c'est la seule dont le
 * cout est mesurable avant d'etre engage.
 */
class DeriveLoopConversationKnowledgeCommand extends Command
{
    protected $signature = 'knowledge:derive-loop-conversations
        {--organization= : slug ou identifiant de l\'Organization}
        {--loop= : identifiant d\'une seule Boucle}
        {--limit=20 : nombre maximum de Boucles traitees}';

    protected $description = "Compile les conversations humaines des Boucles en connaissance derivee retrouvable.";

    public function handle(LoopConversationKnowledgeDeriver $deriver): int
    {
        $loops = $this->targetLoops();

        if ($loops === []) {
            $this->warn('Aucune Boucle ciblee.');

            return self::SUCCESS;
        }

        $derived = 0;
        $unchanged = 0;
        $skipped = 0;

        foreach ($loops as $loop) {
            $note = $deriver->derive($loop);

            if ($note === null) {
                $skipped++;
                $this->line("  - {$loop->name} : rien a deriver");

                continue;
            }

            // La note rendue peut etre celle qui existait deja : l'empreinte
            // de source n'avait pas bouge, aucun appel n'a ete emis.
            if ($note->wasRecentlyCreated) {
                $derived++;
                $this->info("  + {$loop->name} : note v{$note->version} ({$note->id})");
            } else {
                $unchanged++;
                $this->line("  = {$loop->name} : inchangee (v{$note->version})");
            }
        }

        $this->newLine();
        $this->info("Derivees : {$derived} · inchangees : {$unchanged} · sans matiere : {$skipped}");

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
            $resolved = Organization::query()
                ->where('slug', $organization)
                ->orWhere('id', $organization)
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
