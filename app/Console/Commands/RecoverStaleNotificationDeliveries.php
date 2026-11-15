<?php

namespace App\Console\Commands;

use App\Models\MemberNotificationDelivery;
use App\Support\Notifications\NotificationDeliveryStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * TASK-1829 — recuperer les livraisons restees bloquees en `sending`.
 *
 * ## Le probleme que cette commande ferme
 *
 * `prendre()` (dans les deux livreurs) pose `sending` de maniere ATOMIQUE,
 * puis le livreur travaille. Si le worker meurt entre les deux — processus
 * tue, memoire epuisee, coupure dure AVANT le `try/catch` du livreur — rien
 * ne remet jamais cette ligne a un etat terminal : `prendre()` n'accepte que
 * `pending`, donc plus aucun rejeu ne peut la reclamer. C'est la perte
 * SILENCIEUSE que l'audit (Report/261107-13h52-Task1778-report2.md §4,
 * point 6) designe comme le vrai sujet, distinct du `try/catch` normal.
 *
 * ## Pourquoi `ambiguous`, jamais `pending`
 *
 * A-t-elle ete envoyee ? Indecidable depuis ce cote : le transport a pu
 * aboutir avant que le processus meure. Remettre en `pending` risquerait un
 * double envoi ; conclure `ambiguous` ne risque rien et remonte la decision
 * a un humain (meme principe que `NotificationWebPushDeliverer::deliver()`).
 * La transition `sending -> ambiguous` est deja permise par
 * {@see NotificationDeliveryStatus} — aucune regle nouvelle, cette commande
 * ne fait qu'appliquer un etat que le livreur applique a chaud sur ce que
 * lui n'a pas pu appliquer du tout.
 *
 * ## Bornes deliberees
 *
 * - `SEUIL_MINUTES` (15) est largement au-dessus du plus long `$timeout` de
 *   job (90 s pour le push) : une livraison plus jeune que ca peut encore
 *   etre un worker legitimement en cours, jamais un orphelin.
 * - `LOT_MAXIMUM` borne le travail d'un seul passage, pour que la commande
 *   planifiee reste courte meme si un incident a bloque beaucoup de lignes
 *   d'un coup.
 * - Chaque ligne est traitee dans sa PROPRE transaction (point de
 *   sauvegarde), comme `abandonnerSansEchouer()` dans les deux livreurs :
 *   une ligne qui echoue ne doit jamais faire echouer les suivantes.
 */
class RecoverStaleNotificationDeliveries extends Command
{
    protected $signature = 'notifications:recover-stale-deliveries {--dry-run : Compter sans ecrire}';

    protected $description = 'Marque ambiguous les livraisons bloquees en sending depuis trop longtemps';

    private const SEUIL_MINUTES = 15;

    private const LOT_MAXIMUM = 500;

    public function handle(): int
    {
        $candidats = MemberNotificationDelivery::query()
            ->where('status', NotificationDeliveryStatus::SENDING)
            ->where('claimed_at', '<', now()->subMinutes(self::SEUIL_MINUTES))
            ->orderBy('claimed_at')
            ->limit(self::LOT_MAXIMUM)
            ->get();

        if ($this->option('dry-run')) {
            $this->info("{$candidats->count()} livraison(s) orpheline(s) (dry-run, rien ecrit).");

            // TASK-1834 — le Scheduler Laravel Cloud n'ecrit AUCUNE trace
            // quand cette commande ne trouve rien a faire (cas courant) :
            // sans cette ligne, impossible de distinguer « le cron ne
            // tourne pas » de « rien a recuperer ». Compteurs seuls, aucun
            // identifiant de livraison ni de destinataire.
            Log::info('TASK-1834 notifications:recover-stale-deliveries execute (dry-run).', [
                'candidats' => $candidats->count(),
            ]);

            return self::SUCCESS;
        }

        $recuperees = 0;

        foreach ($candidats as $livraison) {
            try {
                DB::transaction(function () use ($livraison): void {
                    // Reverifie SOUS la transaction : une livraison que le
                    // worker aurait conclue entre la lecture et maintenant ne
                    // doit jamais etre ecrasee.
                    $fraiche = MemberNotificationDelivery::query()
                        ->whereKey($livraison->id)
                        ->where('status', NotificationDeliveryStatus::SENDING)
                        ->lockForUpdate()
                        ->first();

                    if ($fraiche === null) {
                        return;
                    }

                    $fraiche->forceFill([
                        'status' => NotificationDeliveryStatus::AMBIGUOUS,
                        'diagnostic' => 'stale_sending_recovered',
                    ])->save();
                });

                $recuperees++;
            } catch (Throwable $incident) {
                report($incident);

                Log::warning('TASK-1829 recuperation d une livraison orpheline en echec.', [
                    'delivery_id' => (string) $livraison->id,
                ]);
            }
        }

        $this->info("{$recuperees}/{$candidats->count()} livraison(s) orpheline(s) recuperee(s) en ambiguous.");

        // TASK-1834 — meme motif que ci-dessus : une preuve d'execution,
        // meme a zero, pour un passage planifie qui ne laisse sinon aucune
        // trace. Compteurs seuls.
        Log::info('TASK-1834 notifications:recover-stale-deliveries execute.', [
            'candidats' => $candidats->count(),
            'recuperees' => $recuperees,
        ]);

        return self::SUCCESS;
    }
}
