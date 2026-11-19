<?php

namespace App\Jobs;

use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Services\OrganizationInvitationMailer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * TASK-1862 — deporter le courriel d'invitation directe a une Organization
 * (TASK-1659, superadmin) hors du thread HTTP de
 * `AdminUserBulkCreationController::store()` et `resend()`.
 *
 * ## Perimetre — uniquement les deux appels existants a `OrganizationInvitationMailer::send()`
 *
 * `OrganizationInvitationMailer` reste l'autorite UNIQUE du rendu et de la
 * livraison : ce job l'APPELLE, il ne la reimplemente jamais — meme
 * doctrine que `SendLoopInvitationEmail` (TASK-1860), dont ce job reprend
 * le patron a l'identique.
 *
 * ## La charge utile est un IDENTIFIANT, rien d'autre
 *
 * `invitationId` — meme motif que toute la famille. Le worker relit
 * l'invitation FRAICHE.
 *
 * ## `OrganizationInvitation` n'a AUCUN scope global tenant (verifie)
 *
 * Meme situation que `LoopInvitation`/`BulkEmailSendRecipient` : aucun
 * scope global sur ce modele. Contrairement a `BulkEmailSend`
 * (TASK-1861), `organization_id` n'est JAMAIS nul ici — une invitation
 * Organization cible toujours exactement une Organization, posee par
 * `OrganizationInvitationService::invite()` a la creation — donc
 * `withOrganizationContext()` n'a pas besoin du cas nullable de TASK-1861.
 *
 * ## Statut relu FRAIS — pas d'envoi tardif
 *
 * `handle()` relit `isPending()` (qui verifie deja `isExpired()`, cf. le
 * modele) au moment de l'execution — jamais un etat capture au dispatch —
 * et abandonne silencieusement (journalise, sans email) si l'invitation a
 * ete acceptee, revoquee ou a expire entre le dispatch et l'execution.
 *
 * ## Relances explicites — comportement INCHANGE
 *
 * `OrganizationInvitationService::invite()` reutilise l'invitation
 * `pending` existante (meme jeton, fenetre d'expiration repoussee de 48h)
 * et les DEUX appelants redeclenchent l'envoi a CHAQUE appel, y compris
 * sur reutilisation (`CASE_RESENT`) — un renvoi volontaire avec le MEME
 * lien, exactement comme pour `LoopInvitation` (TASK-1860). Ce job
 * n'introduit AUCUNE deduplication nouvelle : il est dispatche exactement
 * aux memes instants que l'etait l'appel synchrone a `send()`.
 *
 * Residuel accepte, PAS ferme par ce correctif (meme perimetre que
 * TASK-1860, "pas de nouvelle infrastructure") : une redelivraison
 * "at-least-once" du MEME message de file enverrait un second email, car
 * `isPending()` resterait vrai et `OrganizationInvitationMailer::send()`
 * n'a pas de garde d'idempotence propre. Fermer ce residu exigerait une
 * colonne de reservation dediee (comme `BulkEmailSendRecipient.status`,
 * TASK-1861) qu'`OrganizationInvitation` n'a pas et que ce mandat exclut
 * explicitement d'ajouter.
 *
 * ## `$tries = 1` — meme doctrine que toute la famille
 *
 * Aucune reprise automatique : `OrganizationInvitationMailer::send()`
 * n'est pas idempotent contre un second envoi — le rejouer aveuglement
 * sur une panne transitoire du transport risquerait un second envoi.
 *
 * ## Integrite de l'invitation si la mise en file echoue
 *
 * La ligne `organization_invitations` est DEJA ecrite et commise avant
 * que ce job ne soit meme construit (`invite()` ferme sa propre
 * transaction avant de revenir a l'appelant). Un echec de mise en file
 * (verifie par l'appelant, sous `rescue()`) ne peut donc jamais faire
 * disparaitre l'invitation elle-meme.
 */
class SendOrganizationInvitationEmail implements ShouldQueue
{
    use Dispatchable, Queueable;

    public const DEDICATED_QUEUE = 'entraide-organization-invitation-email';

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        public readonly string $invitationId,
    ) {
        $this->onQueue(self::DEDICATED_QUEUE);
        $this->afterCommit();

        if ($connexion = config('queue.notifications_managed_queue_connection')) {
            $this->onConnection($connexion);
            $this->onQueue(config('queue.notifications_managed_queue_name', self::DEDICATED_QUEUE));
        }
    }

    public function handle(): void
    {
        $invitation = OrganizationInvitation::find($this->invitationId);

        if ($invitation === null) {
            Log::warning('SendOrganizationInvitationEmail: invitation introuvable au moment du traitement.', [
                'invitation_id' => $this->invitationId,
            ]);

            return;
        }

        $this->withOrganizationContext($invitation->organization_id, function (?Organization $organization) use ($invitation): void {
            if ($organization === null) {
                Log::warning('SendOrganizationInvitationEmail: Organization introuvable, email non envoye.', [
                    'invitation_id' => $this->invitationId,
                    'organization_id' => $invitation->organization_id,
                ]);

                return;
            }

            // Relu FRAIS au moment de l'execution — jamais un etat capture
            // au dispatch. `isPending()` verifie deja `isExpired()` (cf. le
            // modele) : accepte, revoque ou expire entre-temps, aucun envoi
            // tardif.
            if (! $invitation->isPending()) {
                Log::warning('SendOrganizationInvitationEmail: invitation non pendante au moment du traitement, email non envoye.', [
                    'invitation_id' => $this->invitationId,
                    'organization_id' => $invitation->organization_id,
                    'status' => $invitation->status,
                ]);

                return;
            }

            // Autorite unique du rendu et de la livraison.
            app(OrganizationInvitationMailer::class)->send($invitation);
        });
    }

    /**
     * Idiome repris A L'IDENTIQUE du reste de la famille (TASK-1854/1855/
     * 1857/1858/1860) — voir le docblock de classe.
     *
     * @template TReturn
     *
     * @param  callable(?Organization): TReturn  $callback
     * @return TReturn
     */
    private function withOrganizationContext(string $organizationId, callable $callback): mixed
    {
        $hadPrevious = app()->bound('current_organization');
        $previous = $hadPrevious ? app('current_organization') : null;
        $organization = Organization::query()->whereKey($organizationId)->first();

        if ($organization) {
            app()->instance('current_organization', $organization);
        } elseif ($hadPrevious) {
            app()->forgetInstance('current_organization');
        }

        try {
            return $callback($organization);
        } finally {
            if ($hadPrevious) {
                app()->instance('current_organization', $previous);
            } else {
                app()->forgetInstance('current_organization');
            }
        }
    }
}
