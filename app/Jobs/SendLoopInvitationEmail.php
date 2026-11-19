<?php

namespace App\Jobs;

use App\Models\LoopInvitation;
use App\Models\Organization;
use App\Services\Loops\LoopInvitationMailer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * TASK-1860 — deporter le courriel d'invitation EXTERNE a une Boucle hors du
 * thread HTTP/Livewire, pour les deux declencheurs identifies par l'audit
 * prealable : `LoopInvitationController::store()` et
 * `LoopMembersCard::sendInvitation()`.
 *
 * ## Perimetre — uniquement les deux appels existants a `LoopInvitationMailer::send()`
 *
 * Les invitations adressees a un membre DEJA de l'Organization passent
 * depuis TASK-1378 par le pipeline Notifications (deja asynchrone depuis
 * TASK-1377) — ce job ne les concerne PAS. `LoopInvitationMailer::send()`
 * reste l'autorite unique du rendu et de la livraison : ce job l'APPELLE,
 * il ne la reimplemente jamais. Elle recalcule elle-meme, EN VIF,
 * `emailHandledByNotifications()` (voir son docblock, TASK-1378) : aucune
 * duplication de cette regle ici.
 *
 * ## La charge utile est un IDENTIFIANT, rien d'autre
 *
 * `invitationId` — meme motif que toute la famille
 * (`SendTransactionStatusChangedEmail`/`SendNewMessageReceivedEmail`,
 * TASK-1855/1857/1858). Le worker relit l'invitation FRAICHE.
 *
 * ## `LoopInvitation` n'a AUCUN scope global tenant (verifie)
 *
 * `HasOrganizationId` ne fait qu'auto-remplir `organization_id` a la
 * creation (`static::creating()`) — ce n'est PAS un scope global. Rien
 * n'empeche donc une lecture par identifiant seul ; l'isolation tenant est
 * assuree ici par `withOrganizationContext()`, meme idiome que toute la
 * famille : etat precedent sauvegarde, restaure dans un `finally`.
 *
 * ## Statut relu FRAIS — pas d'envoi tardif
 *
 * Entre la mise en file et l'execution, l'invitation peut avoir ete
 * acceptee, revoquee, ou avoir expire. `handle()` relit `isPending()`
 * (qui verifie deja `isExpired()`, cf. le modele) au moment de
 * l'execution — jamais un etat capture au dispatch — et abandonne
 * silencieusement (journalise, sans email) si elle ne l'est plus.
 *
 * ## Invitations repetees, meme jeton — comportement INCHANGE
 *
 * `LoopInvitationService::invite()` reutilise l'invitation `pending`
 * existante (meme jeton) et les DEUX appelants redeclenchent l'envoi a
 * CHAQUE appel, y compris sur reutilisation — un renvoi volontaire avec le
 * MEME lien. Ce job ne introduit AUCUNE deduplication nouvelle : il est
 * dispatche exactement aux memes instants que l'etait l'appel synchrone a
 * `send()`, ni plus ni moins souvent.
 *
 * ## `$tries = 1` — meme doctrine que toute la famille
 *
 * Aucune reprise automatique : `LoopInvitationMailer::send()` n'est pas
 * idempotent contre un second envoi (il journalise mais ne verifie pas
 * d'avoir deja remis CE courriel precis) — le rejouer aveuglement sur une
 * panne transitoire du transport risquerait un second envoi.
 *
 * ## Integrite de l'invitation si la mise en file echoue
 *
 * La ligne `loop_invitations` est DEJA ecrite et commise avant que ce job
 * ne soit meme construit (`invite()` ferme sa propre transaction avant de
 * revenir a l'appelant). Un echec de mise en file (verifie par l'appelant,
 * sous `rescue()`) ne peut donc jamais faire disparaitre l'invitation
 * elle-meme — seul son courriel ne partirait pas, et le jeton reste valide
 * pour un renvoi ulterieur.
 */
class SendLoopInvitationEmail implements ShouldQueue
{
    use Dispatchable, Queueable;

    public const DEDICATED_QUEUE = 'entraide-loop-invitation-email';

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
        $invitation = LoopInvitation::find($this->invitationId);

        if ($invitation === null) {
            Log::warning('SendLoopInvitationEmail: invitation introuvable au moment du traitement.', [
                'invitation_id' => $this->invitationId,
            ]);

            return;
        }

        $this->withOrganizationContext($invitation->organization_id, function (?Organization $organization) use ($invitation): void {
            if ($organization === null) {
                Log::warning('SendLoopInvitationEmail: Organization introuvable, email non envoye.', [
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
                Log::warning('SendLoopInvitationEmail: invitation non pendante au moment du traitement, email non envoye.', [
                    'invitation_id' => $this->invitationId,
                    'organization_id' => $invitation->organization_id,
                    'status' => $invitation->status,
                ]);

                return;
            }

            // Autorite unique du rendu et de la livraison — recalcule
            // elle-meme `emailHandledByNotifications()` EN VIF (TASK-1378),
            // aucune duplication de cette regle ici.
            app(LoopInvitationMailer::class)->send($invitation);
        });
    }

    /**
     * Idiome repris A L'IDENTIQUE du reste de la famille (TASK-1854/1855/
     * 1857/1858) — voir le docblock de classe.
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
