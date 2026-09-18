<?php

namespace App\Console\Commands;

use App\Models\GuestVisitor;
use Illuminate\Console\Command;

/**
 * TASK-1433 — SW-3 : la retention est une promesse — les visiteurs expires
 * sont supprimes (leurs conversations suivront par cascade en SW-4).
 * Planifiee chaque jour ; idempotente ; ne touche jamais un visiteur encore
 * dans sa fenetre.
 */
class PurgeExpiredGuestVisitors extends Command
{
    protected $signature = 'guest:purge-expired {--dry-run : Compter sans supprimer}';

    protected $description = 'Supprime les visiteurs du Shell Welcome dont la retention est echue';

    public function handle(): int
    {
        $query = GuestVisitor::expired();
        $count = $query->count();

        if ($this->option('dry-run')) {
            $this->info("{$count} visiteur(s) expire(s) (dry-run, rien supprime).");

            return self::SUCCESS;
        }

        $deleted = $query->delete();
        $this->info("{$deleted} visiteur(s) expire(s) supprime(s).");

        return self::SUCCESS;
    }
}
