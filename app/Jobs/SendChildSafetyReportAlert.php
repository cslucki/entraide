<?php

namespace App\Jobs;

use App\Models\BugReport;
use App\Services\Reports\ChildSafetyAlertMailer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * TASK-1905 (decision MASTER D7) — alerte « Securite des enfants », hors de la
 * requete du membre : asynchrone, APRES commit, UNE tentative, aucun rejeu
 * automatique (meme doctrine que `SendReviewReceivedEmail`, TASK-1809).
 * Le signalement est enregistre avant le dispatch ; un echec ici ne l'affecte
 * jamais. Le job ne porte que l'identifiant : aucune donnee sensible dans la file.
 */
class SendChildSafetyReportAlert implements ShouldQueue
{
    use Dispatchable, Queueable;

    public const DEDICATED_QUEUE = 'child-safety-alert';

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly string $bugReportId)
    {
        $this->onQueue(self::DEDICATED_QUEUE);

        $this->afterCommit();

        if ($connexion = config('queue.notifications_managed_queue_connection')) {
            $this->onConnection($connexion);
            $this->onQueue(config('queue.notifications_managed_queue_name', self::DEDICATED_QUEUE));
        }
    }

    public function handle(ChildSafetyAlertMailer $mailer): void
    {
        $report = BugReport::find($this->bugReportId);

        if ($report === null) {
            Log::warning('SendChildSafetyReportAlert: signalement introuvable au moment du traitement.', [
                'bug_report_id' => $this->bugReportId,
            ]);

            return;
        }

        $mailer->envoyer($report);
    }
}
