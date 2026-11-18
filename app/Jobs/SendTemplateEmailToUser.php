<?php

namespace App\Jobs;

use App\Models\EmailTemplate;
use App\Models\Organization;
use App\Models\User;
use App\Services\EmailerService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * TASK-1863 (TASK B2) — livrer UN destinataire de l'outil « Envoyer un
 * email » (`AdminEmailTemplatesController::sendExecute()`) hors du thread
 * HTTP, meme famille que TASK-1854/1855/1857/1858/1860/1861/1862.
 *
 * ## Perimetre — un job LEGER, PAS une nouvelle table
 *
 * Arbitrage MASTER (mini-audit de compatibilite TASK B2) : reutiliser
 * `BulkEmailSend`/`BulkEmailSendRecipient` (TASK-1861) se serait revele le
 * diff le PLUS GRAND des deux options, une fois le melange possible de
 * plusieurs Organizations dans une seule selection `user_ids` pris en
 * compte (`BulkEmailSend.organization_id` est UN champ singulier, pas une
 * liste). Ce job reste donc volontairement separe, sans nouvelle table :
 * la protection anti-double-soumission vit dans un jeton en cache
 * (`AdminEmailTemplatesController`), pas dans une reservation par ligne.
 *
 * `EmailerService::sendFromTemplate()` reste l'autorite UNIQUE du rendu et
 * de la livraison : ce job l'APPELLE, il ne la reimplemente jamais.
 *
 * ## La charge utile est des IDENTIFIANTS, et un hash de contenu
 *
 * `templateId`, `userId`, `senderId` — meme motif que toute la famille. Le
 * SEUL ajout propre a ce job : `expectedContentHash`, capture au moment du
 * DISPATCH (pas de la confirmation ecran), voir la section suivante.
 *
 * ## Le contenu valide par l'administrateur ne doit pas changer sous lui
 *
 * `EmailTemplate.subject`/`content_html` sont modifiables a tout moment
 * par un AUTRE SuperAdmin. Sans garde, un gabarit edite ENTRE le dispatch
 * et l'execution deferree partirait dans sa version EDITEE — jamais celle
 * que l'administrateur a confirmee a l'ecran. `handle()` recalcule le hash
 * du gabarit FRAIS et le compare a celui capture au dispatch ; une
 * divergence abandonne l'envoi (journalise, sans email) plutot que
 * d'envoyer une version inattendue. Mecanisme MINIMAL delibere : un hash
 * scalaire dans la charge utile, pas le contenu lui-meme (qui romprait la
 * charge utile minimale de toute la famille), pas de nouvelle colonne de
 * versionnage sur `EmailTemplate`.
 *
 * ## Residuel accepte, signale a MASTER (mandat : "pas de nouvelle infrastructure")
 *
 * Sans table de reservation par destinataire, ce job n'a NI etat
 * `queued`/`processing` ni garde de redelivraison — une redelivraison
 * "at-least-once" du MEME message de file renverrait un second email.
 * Meme residu, deliberement non ferme, que `SendLoopInvitationEmail`
 * (TASK-1860) et `SendOrganizationInvitationEmail` (TASK-1862). Le jeton
 * de soumission (cote controleur) protege contre une DOUBLE SOUMISSION du
 * formulaire, pas contre une redelivraison du job lui-meme — deux
 * protections DISTINCTES, a ne jamais confondre.
 *
 * ## `$tries = 1` — meme doctrine que toute la famille
 *
 * Aucune reprise automatique : `EmailerService::sendFromTemplate()` n'est
 * pas idempotent contre un second envoi — le rejouer aveuglement sur une
 * panne transitoire du transport risquerait un second envoi.
 *
 * ## Integrite si la mise en file echoue partiellement
 *
 * `sendExecute()` dispatche un job par destinataire sous `rescue()` : un
 * echec de mise en file pour UN destinataire ne doit jamais faire
 * disparaitre ni retenter ceux DEJA acceptes par la file pour les autres
 * destinataires de la MEME soumission.
 */
class SendTemplateEmailToUser implements ShouldQueue
{
    use Dispatchable, Queueable;

    public const DEDICATED_QUEUE = 'entraide-admin-template-email';

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        public readonly string $templateId,
        public readonly string $userId,
        public readonly string $senderId,
        public readonly string $expectedContentHash,
    ) {
        $this->onQueue(self::DEDICATED_QUEUE);
        $this->afterCommit();

        if ($connexion = config('queue.notifications_managed_queue_connection')) {
            $this->onConnection($connexion);
            $this->onQueue(config('queue.notifications_managed_queue_name', self::DEDICATED_QUEUE));
        }
    }

    public function handle(EmailerService $service): void
    {
        $template = EmailTemplate::find($this->templateId);

        if ($template === null) {
            Log::warning('SendTemplateEmailToUser: gabarit introuvable au moment du traitement.', [
                'template_id' => $this->templateId,
            ]);

            return;
        }

        if (self::contentHash($template) !== $this->expectedContentHash) {
            Log::warning('SendTemplateEmailToUser: le contenu du gabarit a change depuis la confirmation, email non envoye.', [
                'template_id' => $this->templateId,
                'user_id' => $this->userId,
            ]);

            return;
        }

        $user = User::activeAccount()->whereKey($this->userId)->first();

        if ($user === null) {
            Log::warning('SendTemplateEmailToUser: destinataire introuvable ou desactive, email non envoye.', [
                'template_id' => $this->templateId,
                'user_id' => $this->userId,
            ]);

            return;
        }

        // Relu FRAIS, jamais capture au dispatch : l'expediteur peut avoir
        // ete desactive ou supprime entre-temps. `sendFromTemplate()`
        // accepte deja un `?User $sender` nul (son `replyTo` est
        // conditionnel), donc aucune garde supplementaire n'est necessaire
        // ici.
        $sender = User::find($this->senderId);

        $this->withOrganizationContext($user->organization_id, function () use ($service, $template, $user, $sender): void {
            $log = $service->sendFromTemplate($template, $user, $sender);

            if ($log === null) {
                // Transport SOLLICITE (reussi ou leve, `sendFromTemplate()`
                // l'a deja absorbe), mais la preuve n'a pas pu s'ecrire —
                // jamais rejoue automatiquement, reconciliation manuelle.
                Log::error('SendTemplateEmailToUser: aucune preuve ecrite apres une tentative de transport, reconciliation manuelle necessaire.', [
                    'template_id' => $this->templateId,
                    'user_id' => $this->userId,
                ]);
            }
        });
    }

    /**
     * Le hash du CONTENU valide par l'administrateur — pas de
     * `updated_at` : un `touch()` sans changement de contenu ne doit pas
     * fabriquer une divergence qui n'existe pas.
     */
    public static function contentHash(EmailTemplate $template): string
    {
        return hash('sha256', $template->subject.'|'.$template->content_html);
    }

    /**
     * Idiome repris du reste de la famille (TASK-1854/1855/1857/1858/1860/
     * 1861/1862), version NULLABLE (TASK-1861) : un compte orphelin
     * (`organization_id` NULL, SuperAdmin plateforme compris) ne lie
     * AUCUN tenant plutot que d'en forcer un inexistant.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function withOrganizationContext(?string $organizationId, callable $callback): mixed
    {
        $hadPrevious = app()->bound('current_organization');
        $previous = $hadPrevious ? app('current_organization') : null;

        $organization = $organizationId !== null
            ? Organization::query()->whereKey($organizationId)->first()
            : null;

        if ($organization) {
            app()->instance('current_organization', $organization);
        } else {
            app()->forgetInstance('current_organization');
        }

        try {
            return $callback();
        } finally {
            if ($hadPrevious) {
                app()->instance('current_organization', $previous);
            } else {
                app()->forgetInstance('current_organization');
            }
        }
    }
}
