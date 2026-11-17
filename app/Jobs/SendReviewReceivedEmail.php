<?php

namespace App\Jobs;

use App\Models\Organization;
use App\Models\Review;
use App\Services\Entraide\ReviewReceivedMailer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * TASK-1854 — livrer par email l'avis d'entraide deja depose, hors du
 * thread HTTP qui l'a cree.
 *
 * ## Avant ce correctif
 *
 * `EvaluationEntraide::enregistrer()` appelait `ReviewReceivedMailer::
 * envoyer()` directement, sous `rescue()` mais EN SYNCHRONE : le worker
 * FPM restait bloque sur la conversation SMTP (confirmee par trace, audit
 * incident 429 du 17/11) jusqu'a 11 281 ms observes sur `/complete`. Ce
 * job reprend A L'IDENTIQUE le motif deja en place pour les notifications
 * internes (`SendNotificationEmail`, TASK-1377/1829) : l'envoi reel part
 * desormais sur la Managed Queue Laravel Cloud EXISTANTE (`cloud`), pas
 * une nouvelle ressource.
 *
 * ## La charge utile est un IDENTIFIANT, rien d'autre
 *
 * Meme motif que `SendNotificationEmail` : aucun modele serialise, aucune
 * adresse, aucune langue. Entre la creation de l'avis et l'execution du
 * job, le destinataire a pu changer de langue ou de preference email,
 * l'avis a pu etre masque par moderation. Le worker relit donc TOUT
 * depuis la base, `ReviewReceivedMailer::envoyer()` reste INCHANGEE et
 * fait exactement ce travail de relecture pour les donnees de l'avis
 * lui-meme.
 *
 * ## Autorisation TENANT re-etablie ICI, explicitement
 *
 * `Review::transaction()` pointe vers `Transaction`, qui porte
 * `BelongsToOrganizationScope` (contrairement a `Review` elle-meme). Dans
 * une requete HTTP, `current_organization` est deja liee par le
 * middleware — mais un worker de Managed Queue n'a AUCUN contexte de
 * requete : sans cette liaison explicite, `$avis->loadMissing('transaction')`
 * echouerait FAIL-CLOSED (transaction = null), et l'email partirait quand
 * meme, degrade en silence (URL de conversation vide). On relit
 * l'Organization par son identifiant SCALAIRE (deja charge sur l'avis,
 * jamais une relation), puis on la lie AVANT tout appel au mailer.
 *
 * ## Idempotence HERITEE, pas reinventee
 *
 * Ce job n'ajoute aucune garde a lui : `EvaluationEntraide::enregistrer()`
 * ne cree qu'UNE ligne `Review` par entraide (`unique(transaction_id)` +
 * garde applicative) et n'appelle `dispatch()` qu'a cet unique endroit.
 * Un avis ne peut donc jamais declencher ce job deux fois.
 *
 * ## `$tries = 1` — meme doctrine que `SendNotificationEmail`
 *
 * Aucune reprise automatique : `ReviewReceivedMailer::envoyer()` capture
 * deja tout echec de transport et journalise `EmailLog::STATUS_FAILED`
 * sans jamais relancer l'exception — rejouer inviterait a un second envoi
 * sur la foi d'un echec qui a peut-etre quand meme ete transmis au
 * serveur SMTP.
 */
class SendReviewReceivedEmail implements ShouldQueue
{
    use Dispatchable, Queueable;

    public const DEDICATED_QUEUE = 'entraide-review-email';

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly string $reviewId)
    {
        // File DEDIEE sous la connexion par defaut (sync/database) : ne
        // partage jamais `default`, meme par erreur — meme motif que
        // `SendNotificationEmail`.
        $this->onQueue(self::DEDICATED_QUEUE);

        // Attendre le commit REEL du producteur avant de pousser le job.
        // `EvaluationEntraide::enregistrer()` n'ouvre pas toujours sa
        // propre transaction (elle peut etre appelee hors de toute
        // transaction active, ou imbriquee dans celle de l'appelant) :
        // `afterCommit()` gere les deux cas sans distinction a ecrire ici.
        $this->afterCommit();

        // TASK-1829/1830 — meme routage conditionnel que les notifications
        // internes, vers la MEME Managed Queue deja provisionnee
        // (`notifications`, confirmee active en PROD) : aucune nouvelle
        // ressource Cloud, aucune nouvelle variable d'environnement.
        if ($connexion = config('queue.notifications_managed_queue_connection')) {
            $this->onConnection($connexion);
            $this->onQueue(config('queue.notifications_managed_queue_name', self::DEDICATED_QUEUE));
        }
    }

    public function handle(ReviewReceivedMailer $mailer): void
    {
        $avis = Review::find($this->reviewId);

        if ($avis === null) {
            Log::warning('SendReviewReceivedEmail: avis introuvable au moment du traitement.', [
                'review_id' => $this->reviewId,
            ]);

            return;
        }

        $organization = Organization::find($avis->organization_id);

        if ($organization === null) {
            Log::warning('SendReviewReceivedEmail: Organization introuvable, email non envoye.', [
                'review_id' => $this->reviewId,
                'organization_id' => $avis->organization_id,
            ]);

            return;
        }

        // Liaison tenant EXPLICITE — voir le docblock de classe. Sans
        // elle, `$avis->transaction` (scope `BelongsToOrganizationScope`)
        // echouerait en silence sous ce worker.
        app()->instance('current_organization', $organization);

        $mailer->envoyer($avis);
    }
}
