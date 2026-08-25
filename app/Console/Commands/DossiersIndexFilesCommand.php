<?php

namespace App\Console\Commands;

use App\Models\DossierFile;
use App\Models\Organization;
use App\Services\Dossiers\DossierFileIndexingDispatcher;
use App\Services\Dossiers\FileContentExtractor;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * TASK-1268 : reindexation EXPLICITE et bornee des DossierFiles d'UNE
 * Organization, par le pipeline metier existant
 * (DossierFileIndexingDispatcher -> IndexDossierFileChunks ->
 * DossierFileIndexer::synchronize()).
 *
 * Rien n'est extrait, chunke ni embarque ici : la commande SELECTIONNE et
 * DISPATCHE, c'est tout. L'idempotence est celle de DossierFileIndexer
 * (`content_hash` par chunk + WithoutOverlapping sur le job) ; la gate
 * (DossierSemanticSearchGate) et le credential tenant (ProviderResolver)
 * restent juges dans le job, jamais contournes ici.
 *
 * Les jobs partent sur une queue DEDIEE, jamais `default` : sur la surface
 * produit, `default` porte des jobs historiques que personne n'a decide
 * d'executer, et le worker de TASK-1268 n'ecoute que la queue dediee.
 */
class DossiersIndexFilesCommand extends Command
{
    protected $signature = 'dossiers:index-files
        {organization : Slug ou id de l\'Organization (obligatoire)}
        {--dry-run : Compte et liste les fichiers sans rien dispatcher}
        {--limit= : Nombre maximum de fichiers retenus}
        {--queue='.DossierFileIndexingDispatcher::DEDICATED_QUEUE.' : Queue des jobs (jamais `default`)}';

    protected $description = 'Planifie l’indexation IA des fichiers texte, Markdown, DOCX, PDF et XLSX d’une Organization sur une queue dédiée (TASK-1268)';

    public function handle(DossierFileIndexingDispatcher $dispatcher): int
    {
        $organization = $this->resolveOrganization((string) $this->argument('organization'));

        if ($organization === null) {
            $this->error('Organization inconnue : '.(string) $this->argument('organization'));

            return self::FAILURE;
        }

        $queue = trim((string) $this->option('queue'));

        if ($queue === '' || $queue === 'default') {
            $this->error('Queue refusée : les jobs d’indexation ne partent jamais sur `default` (TASK-1268). Utiliser une queue dédiée.');

            return self::FAILURE;
        }

        $limit = $this->resolveLimit();

        if ($limit === false) {
            $this->error('--limit doit être un entier strictement positif.');

            return self::FAILURE;
        }

        $query = $this->eligibleFiles($organization);
        $eligibleTotal = (clone $query)->count();

        if ($limit !== null) {
            $query->limit($limit);
        }

        $files = $query->get(['id', 'organization_id', 'dossier_id', 'disk', 'path', 'original_name', 'mime_type', 'size_bytes']);
        $dryRun = (bool) $this->option('dry-run');

        $this->line('Base : '.$this->connectionLabel());
        $this->line("Organization : {$organization->slug} ({$organization->id})");
        $this->line('MIME retenus : '.implode(', ', FileContentExtractor::SUPPORTED_MIME_TYPES));
        $this->line("Fichiers éligibles : {$eligibleTotal}".($limit !== null ? " — retenus (--limit={$limit}) : {$files->count()}" : ''));
        $this->line("Queue : {$queue}");
        $this->line('Mode : '.($dryRun ? 'DRY-RUN (aucun dispatch, aucun provider)' : 'DISPATCH'));

        if ($files->isNotEmpty()) {
            $this->table(
                ['#', 'dossier_file_id', 'dossier_id', 'original_name', 'mime_type', 'octets', 'sur disque'],
                $files->values()->map(fn (DossierFile $file, int $index): array => [
                    $index + 1,
                    $file->id,
                    $file->dossier_id,
                    Str::limit((string) $file->original_name, 48),
                    $file->mime_type,
                    $file->size_bytes,
                    $this->existsOnDisk($file) ? 'oui' : 'NON',
                ])->all(),
            );
        }

        if ($dryRun) {
            $this->info("{$files->count()} fichier(s) seraient planifiés sur la queue `{$queue}`. Rien n’a été dispatché.");

            return self::SUCCESS;
        }

        $dispatched = $dispatcher->dispatchForFiles($files, $queue);

        $this->info("{$dispatched} job(s) d’indexation planifié(s) sur la queue `{$queue}` via le pipeline métier.");

        return self::SUCCESS;
    }

    private function resolveOrganization(string $reference): ?Organization
    {
        $reference = trim($reference);

        if ($reference === '') {
            return null;
        }

        $query = Organization::query()->where('slug', $reference);

        if (Str::isUuid($reference)) {
            $query->orWhere($query->getModel()->getKeyName(), $reference);
        }

        return $query->first();
    }

    /**
     * @return Builder<DossierFile>
     */
    private function eligibleFiles(Organization $organization): Builder
    {
        return DossierFile::query()
            ->where('organization_id', $organization->id)
            ->whereNotNull('dossier_id')
            ->whereIn('mime_type', FileContentExtractor::SUPPORTED_MIME_TYPES)
            ->orderBy('created_at')
            ->orderBy('id');
    }

    /**
     * @return int|null|false null = pas de limite ; false = valeur invalide
     */
    private function resolveLimit(): int|null|false
    {
        $raw = $this->option('limit');

        if ($raw === null || trim((string) $raw) === '') {
            return null;
        }

        if (! ctype_digit(trim((string) $raw)) || (int) $raw < 1) {
            return false;
        }

        return (int) $raw;
    }

    private function existsOnDisk(DossierFile $file): bool
    {
        try {
            return Storage::disk((string) $file->disk)->exists((string) $file->path);
        } catch (\Throwable) {
            return false;
        }
    }

    private function connectionLabel(): string
    {
        $connection = (string) config('database.default');
        $database = (string) config("database.connections.{$connection}.database");

        return "{$connection} / {$database}";
    }
}
