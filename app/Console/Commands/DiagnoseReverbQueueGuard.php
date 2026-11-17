<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * TASK-1850 — garde de DIAGNOSTIC, jamais appelee automatiquement : les 4
 * evenements de diffusion Reverb (`LoopMessageCreated`,
 * `ConversationMessageCreated`, `MemberNotificationCreated`,
 * `MessageUnreadCountChanged`) implementent `ShouldBroadcast` (mise en
 * FILE), pas `ShouldBroadcastNow`. Sous `QUEUE_CONNECTION=sync` (confirme
 * en PROD, TASK-1849), « mettre en file » execute le job IMMEDIATEMENT,
 * dans le MEME process — aucun risque.
 *
 * Mais `routes/console.php` planifie `queue:work` avec un ALLOWLIST
 * LITTERAL de noms de file qui EXCLUT DELIBEREMENT `default` (207 jobs
 * historiques jamais consommes, commentaire du code) — la file ou
 * tombent ces 4 jobs sous un `QUEUE_CONNECTION` ASYNCHRONE (`database`,
 * `redis`, ...) SANS worker dedie. Si `QUEUE_CONNECTION` changeait un
 * jour pour une raison SANS RAPPORT avec Reverb, ces diffusions
 * s'empileraient SANS JAMAIS etre consommees — silence total, zero
 * erreur, zero diffusion.
 *
 * Cette commande ne fait QUE lire la configuration courante et avertir —
 * aucune modification de la queue, aucune consommation de `default`
 * (mandat explicite : « Ne pas modifier la queue de PROD. Ne pas
 * consommer les anciens jobs historiques de `default`. »).
 */
class DiagnoseReverbQueueGuard extends Command
{
    protected $signature = 'reverb:diagnose-queue-guard';

    protected $description = 'Verifie si la configuration actuelle de la file laisserait les diffusions Reverb sans consommateur';

    public function handle(): int
    {
        $connection = config('queue.default');

        if ($connection === 'sync') {
            $this->info("QUEUE_CONNECTION='sync' — chaque diffusion Reverb (ShouldBroadcast) s'execute immediatement, dans le meme process. Aucun risque de file non consommee.");

            return self::SUCCESS;
        }

        $this->warn(sprintf(
            "QUEUE_CONNECTION='%s' (asynchrone) — les 4 evenements de diffusion Reverb (LoopMessageCreated, ConversationMessageCreated, MemberNotificationCreated, MessageUnreadCountChanged) implementent ShouldBroadcast : chacun met un job BroadcastEvent EN FILE sur la connexion par defaut.",
            $connection
        ));

        $this->warn("routes/console.php planifie `queue:work` avec un allowlist qui EXCLUT la file 'default' par conception (voir son commentaire). Si ces jobs de diffusion atterrissent sur 'default' (le cas par defaut, sans configuration explicite d'une file dediee), ILS NE SERONT JAMAIS CONSOMMES : aucune erreur visible, simplement aucune diffusion Reverb n'atteindra jamais un client.");

        $this->warn('Avant d\'activer un pilote Reverb sous cette configuration : soit router ces 4 evenements vers une file EFFECTIVEMENT consommee, soit les faire implementer ShouldBroadcastNow (deja protege par DB::afterCommit()/$afterCommit, donc sans risque de diffusion avant commit).');

        return self::FAILURE;
    }
}
