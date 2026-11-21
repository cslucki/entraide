<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Support\Images\ImageOptimizationControl;
use App\Support\Images\ImageOptimizationInventory;
use App\Support\Images\ImageOptimizationRunner;
use App\Support\Images\ImageProcessOutcome;
use App\Support\Images\ImageSource;
use Illuminate\Console\Command;
use Illuminate\Support\Number;
use Illuminate\Support\Str;

/**
 * TASK-1873 — rattrapage MANUEL et BORNE des images existantes.
 *
 * SIMULATION PAR DEFAUT : sans `--execute`, la commande n'ecrit rien — ni
 * fichier, ni ligne de registre — et liste seulement ce qu'un lot traiterait.
 * Elle n'est appelee par aucune migration, aucun deploiement, aucune tache
 * planifiee : un rattrapage est toujours un geste humain.
 *
 * Exemples :
 *   php artisan images:optimize --status
 *   php artisan images:optimize --surface=loop_cover --limit=5
 *   php artisan images:optimize --surface=loop_cover --limit=5 --execute
 *   php artisan images:optimize --execute --queue --limit=25
 *   php artisan images:optimize --pause | --resume
 */
class ImagesOptimizeCommand extends Command
{
    protected $signature = 'images:optimize
        {--surface= : user_avatar | organization_logo | loop_cover | blog_cover | service_image}
        {--organization= : id ou slug de l\'Organization}
        {--limit=20 : nombre maximum d\'images du lot (1 a 500)}
        {--max-time=300 : duree maximum du lot, en secondes (traitement sur place)}
        {--execute : traiter reellement (sinon : simulation, aucune ecriture)}
        {--queue : avec --execute, mettre le lot en file `images` au lieu de le traiter ici}
        {--force : retraiter aussi les images deja a jour ou abandonnees}
        {--status : afficher l\'etat par surface et quitter}
        {--pause : suspendre le rattrapage (les uploads neufs restent traites)}
        {--resume : lever la pause}';

    protected $description = 'Inventorie, simule ou rattrape (par lots bornes) les variantes WebP des images existantes';

    public function handle(ImageOptimizationInventory $inventory, ImageOptimizationRunner $runner, ImageOptimizationControl $control): int
    {
        if ($this->option('pause')) {
            $control->pause();
            $this->warn('Rattrapage en PAUSE. Les images nouvellement televersees restent traitees.');

            return self::SUCCESS;
        }

        if ($this->option('resume')) {
            $control->resume();
            $this->info('Pause levee. Relancer un lot : il reprend ou le precedent s\'est arrete.');

            return self::SUCCESS;
        }

        $surface = $this->option('surface') ?: null;
        if ($surface !== null && ! in_array($surface, $inventory->surfaces(), true)) {
            $this->error("Surface inconnue ou sans rattrapage : {$surface}. Valeurs : ".implode(', ', $inventory->surfaces()));

            return self::INVALID;
        }

        $organizationId = null;
        if ($ref = $this->option('organization')) {
            // Un slug compare a une colonne `uuid` leve sous PostgreSQL : on
            // ne cherche par cle que ce qui a la forme d'un UUID.
            $organization = Str::isUuid($ref)
                ? Organization::withTrashed()->whereKey($ref)->first()
                : Organization::withTrashed()->where('slug', $ref)->first();
            if ($organization === null) {
                $this->error("Organization introuvable : {$ref}");

                return self::INVALID;
            }
            $organizationId = $organization->id;
        }

        if ($this->option('status')) {
            $this->renderSummary($inventory->summary($organizationId), $control->paused());

            return self::SUCCESS;
        }

        $limit = max(1, min(500, (int) $this->option('limit')));
        $force = (bool) $this->option('force');

        if (! $this->option('execute')) {
            return $this->simulate($inventory, $surface, $organizationId, $limit, $force, $control->paused());
        }

        if ($control->paused()) {
            $this->warn('Rattrapage en PAUSE : rien n\'est lance. `--resume` pour reprendre.');

            return self::FAILURE;
        }

        if ($this->option('queue')) {
            $queued = $runner->queueBatch($surface, $organizationId, $limit, $force);
            $this->info("{$queued} image(s) mise(s) en file sur la queue `images`.");

            return self::SUCCESS;
        }

        $maxSeconds = max(10, (int) $this->option('max-time'));
        $this->line("Lot : {$limit} image(s) max, {$maxSeconds} s max.");

        $stats = $runner->runBatch($surface, $organizationId, $limit, $maxSeconds, $force, function (ImageSource $source, ImageProcessOutcome $outcome) {
            $gain = $outcome->status === ImageProcessOutcome::PROCESSED && $outcome->sourceBytes
                ? sprintf(' %s -> %s (%d variante(s))', Number::fileSize($outcome->sourceBytes, precision: 1), Number::fileSize($outcome->variantBytes, precision: 1), $outcome->generated)
                : '';
            $this->line(sprintf('  [%s] %s %s%s%s', $outcome->status, $source->surface, $source->path, $gain, $outcome->reason ? ' — '.$outcome->reason : ''));
        });

        $this->newLine();
        $this->table(
            ['traitees', 'a jour', 'ecartees', 'manquantes', 'echecs', 'verrouillees', 'originaux lus', 'variantes ecrites'],
            [[
                $stats['processed'], $stats['up_to_date'], $stats['skipped'], $stats['missing'], $stats['failed'], $stats['locked'],
                Number::fileSize($stats['source_bytes'], precision: 1), Number::fileSize($stats['variant_bytes'], precision: 1),
            ]],
        );

        if ($stats['stopped_by'] !== null) {
            $this->warn('Lot interrompu : '.($stats['stopped_by'] === 'pause' ? 'pause demandee' : 'duree maximum atteinte').'. Relancer pour reprendre.');
        }

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function simulate(ImageOptimizationInventory $inventory, ?string $surface, ?string $organizationId, int $limit, bool $force, bool $paused): int
    {
        $this->info('SIMULATION — aucune ecriture. Ajouter --execute pour traiter.');

        if ($paused) {
            $this->warn('Note : le rattrapage est en PAUSE ; un --execute serait refuse.');
        }

        $sources = $inventory->pending($surface, $organizationId, $limit, $force);

        if ($sources->isEmpty()) {
            $this->line('Rien a traiter pour ce filtre.');

            return self::SUCCESS;
        }

        $total = 0;
        $rows = $sources->map(function (ImageSource $source) use ($inventory, &$total) {
            $size = $inventory->sourceSize($source);
            $total += (int) $size;

            return [$source->surface, $source->path, $size === null ? 'ABSENT' : Number::fileSize($size, precision: 1), $source->organizationId ?? '—'];
        })->all();

        $this->table(['surface', 'original', 'poids', 'organization'], $rows);
        $this->line(sprintf('%d image(s), %s d\'originaux a lire.', count($rows), Number::fileSize($total, precision: 1)));

        return self::SUCCESS;
    }

    /** @param array<string, array<string, int>> $summary */
    private function renderSummary(array $summary, bool $paused): void
    {
        $this->line('Rattrapage : '.($paused ? 'EN PAUSE' : 'actif'));

        $this->table(
            ['surface', 'en base', 'restantes', 'pretes', 'ecartees', 'echecs', 'manquantes', 'originaux (prets)', 'servi (prets)'],
            collect($summary)->map(fn (array $line, string $surface) => [
                $surface, $line['total'], $line['pending'], $line['ready'], $line['skipped'], $line['failed'], $line['missing'],
                Number::fileSize($line['source_bytes'], precision: 1), Number::fileSize($line['variant_bytes'], precision: 1),
            ])->values()->all(),
        );
    }
}
