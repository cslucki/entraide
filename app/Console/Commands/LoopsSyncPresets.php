<?php

namespace App\Console\Commands;

use App\Services\Loops\LoopPresetSyncService;
use App\Support\Loops\LoopTypeRegistry;
use Illuminate\Console\Command;

/**
 * Propager le socle des types aux Boucles existantes — sur demande, jamais tout
 * seul.
 *
 * C'est le pendant en ligne de commande de ce que l'ecran des types fait apres
 * confirmation. Le meme service repond aux deux, pour que `--dry-run` et
 * l'apercu affiche a un SuperAdmin ne puissent pas raconter deux histoires.
 */
class LoopsSyncPresets extends Command
{
    protected $signature = 'loops:sync-presets
                            {--type= : Ne traiter que ce type de Boucle}
                            {--dry-run : Montrer ce qui serait fait, sans rien ecrire}';

    protected $description = 'Ajoute aux Boucles les Cards manquantes du socle de leur type. Additif : ne retire et ne rallume jamais rien.';

    public function handle(LoopPresetSyncService $sync, LoopTypeRegistry $types): int
    {
        $type = $this->option('type') ?: null;

        if ($type !== null && ! $types->exists($type)) {
            $this->error("Type inconnu : {$type}");
            $this->line('Types connus : '.implode(', ', $types->keys()));

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            return $this->reportPreview($sync->preview($type), $types);
        }

        $result = $sync->sync($type);

        $this->info('Synchronisation terminée.');
        $this->line("Boucles analysées : {$result['loops_scanned']}");
        $this->line("Boucles modifiées : {$result['loops_affected']}");

        if ($result['cards_added'] === []) {
            $this->line('Aucune Card à ajouter : tout était déjà à jour.');

            return self::SUCCESS;
        }

        $this->line('Cards ajoutées :');
        foreach ($result['cards_added'] as $key => $count) {
            $this->line("  {$types->cardLabel($key)} ({$key}) → {$count} Boucle(s)");
        }

        $this->newLine();
        $this->line('Aucune Card n’a été retirée, ni réactivée.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $preview
     */
    private function reportPreview(array $preview, LoopTypeRegistry $types): int
    {
        $this->info('Simulation — aucune écriture.');
        $this->newLine();

        $this->line('Types concernés  : '.($preview['types'] === [] ? '—' : implode(', ', $preview['types'])));
        $this->line("Boucles analysées : {$preview['loops_scanned']}");
        $this->line("Boucles modifiées : {$preview['loops_affected']}");
        $this->newLine();

        if ($preview['cards_to_add'] === []) {
            $this->line('Aucune Card ne serait ajoutée.');
        } else {
            $this->line('Cards qui seraient ajoutées :');
            foreach ($preview['cards_to_add'] as $key => $count) {
                $this->line("  {$types->cardLabel($key)} ({$key}) → {$count} Boucle(s)");
            }
        }

        $this->newLine();
        $this->line("Cards désactivées préservées : {$preview['disabled_preserved']}");
        $this->line("Cards locales préservées     : {$preview['local_preserved']}");

        return self::SUCCESS;
    }
}
