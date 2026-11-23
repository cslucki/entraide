<?php

namespace App\Console\Commands;

use App\Livewire\MessageThread;
use App\Models\Message;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * TASK-1886 — LE PURGEUR DES MESSAGES VOCAUX ECHUS.
 *
 * Un vocal est promis pour cinq jours. La route gardee le refuse des
 * l'echeance (410) ; cette commande tient la seconde moitie de la promesse :
 * le FICHIER disparait du stockage.
 *
 * ## Ce qu'elle ne peut PAS toucher, par construction
 *
 * - **Un fichier qui n'est pas un vocal.** La selection exige
 *   `voice_expires_at`, que seul le chemin d'envoi vocal pose. Un `.mp3`
 *   JOINT ne la porte jamais.
 * - **Un fichier hors du prefixe vocal.** Chaque chemin est reverifie :
 *   il doit commencer par `message-voice/`. Une ligne qui porterait une
 *   echeance ET un autre chemin (ecrite a la main, corrompue) est REFUSEE et
 *   signalee, jamais supprimee. Deux gardes independantes pour une meme
 *   promesse : aucune piece jointe durable ne peut partir par ici.
 * - **Un vocal pas encore echu.** `voice_expires_at <= now()`.
 *
 * ## L'ordre, et pourquoi
 *
 * 1. supprimer l'objet ;
 * 2. VERIFIER qu'il n'existe plus — le disque `message_files` est en
 *    `'throw' => false` : un `delete()` qui echoue rend `false` sans lever,
 *    et croire une suppression sur parole laisserait un fichier orphelin
 *    marque « purge » ;
 * 3. seulement alors, marquer la ligne (`voice_purged_at`, `file_path` nul).
 *
 * Un echec a l'etape 1 ou 2 laisse la ligne intacte : elle sera reprise a la
 * passe suivante (re-essai naturel, sans compteur). Un objet DEJA absent
 * (purge concurrente, filet R2 Lifecycle passe avant) est un succes : la
 * commande est idempotente.
 *
 * ## Supervision
 *
 * Les logs Laravel Cloud ne sont conserves qu'UN jour (mesure 22/11). Chaque
 * passe ecrit donc une ligne `voice_purge` avec ses compteurs, et la commande
 * sort en ECHEC si un fichier n'a pas pu etre supprime — le tableau des
 * taches planifiees le montre.
 */
class PurgeExpiredVoiceMessages extends Command
{
    protected $signature = 'messages:purge-expired-voices
        {--dry-run : Compter et lister sans rien supprimer}
        {--limit=200 : Nombre maximal de vocaux traites par passe}';

    protected $description = 'Supprime les fichiers des messages vocaux dont l\'echeance (5 jours) est passee';

    public function handle(): int
    {
        $limite = max(1, min(1000, (int) $this->option('limit')));
        $prefixe = MessageThread::PREFIXE_VOCAL.'/';

        $lot = Message::query()
            ->whereNotNull('voice_expires_at')
            ->where('voice_expires_at', '<=', now())
            ->whereNull('voice_purged_at')
            ->orderBy('voice_expires_at')
            ->limit($limite)
            ->get(['id', 'file_path', 'file_disk', 'voice_expires_at']);

        if ($this->option('dry-run')) {
            $this->info("{$lot->count()} vocal(aux) echu(s) a purger (dry-run, rien supprime).");

            return self::SUCCESS;
        }

        $purges = 0;
        $echecs = 0;
        $refuses = 0;

        foreach ($lot as $message) {
            $chemin = (string) $message->file_path;

            // Deja sans fichier : il ne reste qu'a constater l'echeance.
            if ($chemin === '') {
                $this->marquer($message);
                $purges++;

                continue;
            }

            if (! str_starts_with($chemin, $prefixe)) {
                $refuses++;
                Log::warning('voice_purge_refused_outside_prefix', ['message_id' => $message->id]);

                continue;
            }

            if (config("filesystems.disks.{$message->file_disk}") === null) {
                $echecs++;
                Log::warning('voice_purge_unknown_disk', ['message_id' => $message->id]);

                continue;
            }

            try {
                $disque = Storage::disk($message->file_disk);
                $disque->delete($chemin);
                $encorePresent = $disque->exists($chemin);
            } catch (\Throwable $e) {
                $echecs++;
                Log::warning('voice_purge_storage_error', [
                    'message_id' => $message->id,
                    'exception_class' => $e::class,
                ]);

                continue;
            }

            if ($encorePresent) {
                $echecs++;
                Log::warning('voice_purge_still_present', ['message_id' => $message->id]);

                continue;
            }

            $this->marquer($message);
            $purges++;
        }

        Log::info('voice_purge', [
            'selected' => $lot->count(),
            'purged' => $purges,
            'failed' => $echecs,
            'refused' => $refuses,
        ]);

        $this->info("{$purges} vocal(aux) purge(s), {$echecs} echec(s), {$refuses} refuse(s) hors prefixe.");

        return $echecs > 0 || $refuses > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Le marquage est CONDITIONNEL (`voice_purged_at` encore nul) : deux passes
     * concurrentes ne marquent qu'une fois. `file_path` passe a nul pour
     * qu'aucune route ne puisse plus jamais viser cet objet.
     */
    private function marquer(Message $message): void
    {
        Message::query()
            ->whereKey($message->id)
            ->whereNull('voice_purged_at')
            ->update([
                'voice_purged_at' => now(),
                'file_path' => null,
            ]);
    }
}
