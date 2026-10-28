<?php

namespace App\Services\Loops;

use App\Models\EmailLog;
use App\Models\Loop;
use App\Models\LoopInvitation;
use App\Models\User;
use App\Services\EmailerService;
use App\Services\LoopInvitationService;
use App\Support\Email\SystemEmailTemplateResolver;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * L'envoi du courriel d'invitation a une Boucle.
 *
 * Ce code vivait en prive dans LoopInvitationController et n'etait donc
 * atteignable que par la route POST. La Card Membres envoie maintenant la meme
 * invitation sans quitter l'ecran : le recopier aurait garanti que les deux
 * chemins divergent — sur le gabarit choisi, sur les variables interpolees, ou
 * sur ce qui est journalise en cas d'echec.
 *
 * Le corps est repris tel quel, y compris son repli sur le gabarit Blade et sa
 * trace en cas de non-remise.
 *
 * ## TASK-1378 — ce mailer ne sert plus les membres de l'Organization
 *
 * Depuis le cutover, une invitation adressee a un membre de l'Organization part
 * par le pipeline Notifications : notification IN_APP, livraison EMAIL, file
 * dediee, preuve dans `email_logs`. Ce mailer reste la voie des INCONNUS — pas
 * de compte, ou un compte dans un autre tenant — pour qui aucune notification
 * ne peut exister.
 *
 * Le garde est ICI, et pas chez les appelants. Deux chemins de production
 * appellent `send()` — le controleur et la Card Membres — et un troisieme
 * arrivera. Poser la condition chez chacun serait une convention : elle tiendrait
 * jusqu'a ce que quelqu'un l'oublie, et l'oubli enverrait un email en double
 * sans rien casser de visible. Ici, le double envoi est structurellement
 * impossible, et tout futur appelant herite de la protection.
 */
class LoopInvitationMailer
{
    public function send(LoopInvitation $invitation): void
    {
        // Une seule autorite decide qui envoie — et elle recalcule en vif, sans
        // faire confiance a `invitation_type`, qui est fige a la creation.
        if (app(LoopInvitationService::class)->emailHandledByNotifications($invitation)) {
            return;
        }

        $compose = $this->compose($invitation);

        $subject = $compose['subject'];
        $html = $compose['body'];
        $fallbackReason = $compose['fallback_reason'];

        $logData = [
            'source' => 'loop-invitation',
            'invitation_id' => $invitation->id,
            'loop_id' => $invitation->loop_id,
            'organization_id' => $invitation->organization_id,
            'invitation_type' => $invitation->invitation_type,
            'recipient_email' => $invitation->recipient_email,
            'template_used' => $fallbackReason === null ? 'system_email_template' : 'blade_fallback',
            'fallback_reason' => $fallbackReason,
        ];

        try {
            Mail::html($html, function ($message) use ($invitation, $subject) {
                $message->to($invitation->recipient_email)->subject($subject);
            });

            EmailLog::create([
                'user_id' => $invitation->sender_id,
                'organization_id' => $invitation->organization_id,
                'to_email' => $invitation->recipient_email,
                'subject' => $subject,
                'status' => 'sent',
                'data' => $logData,
            ]);
        } catch (\Throwable $e) {
            // The invitation row is already persisted and its link stays valid,
            // so a delivery failure is recoverable by re-sending; it must be
            // recorded rather than swallowed.
            EmailLog::create([
                'user_id' => $invitation->sender_id,
                'organization_id' => $invitation->organization_id,
                'to_email' => $invitation->recipient_email,
                'subject' => $subject,
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'data' => $logData,
            ]);
        }
    }

    /**
     * TASK-1719 — CE QUE DIT un courriel d'invitation a une Boucle, en un seul
     * endroit.
     *
     * Extrait de `send()` sans une ligne de changement, pour une raison
     * precise. Depuis TASK-1378, une invitation adressee a un membre de
     * l'Organization ne passe plus par `send()` mais par le pipeline
     * Notifications. Or ce pipeline composait son corps depuis
     * `lang/<locale>/notifications.php` — un texte generique de cinq lignes — et
     * IGNORAIT le gabarit `loop_invitation` que le SuperAdmin administre dans
     * `/admin/system-email-templates`.
     *
     * Deux textes pour le meme fait, donc, selon que l'invite avait un compte
     * ou non : celui qu'on peut modifier partait aux inconnus, celui qu'on ne
     * peut pas partait aux membres. MASTER : « Reutiliser OBLIGATOIREMENT le
     * template systeme `loop_invitation` ».
     *
     * Rendre cette composition publique est la facon la moins chere de le
     * corriger : `NotificationEmailDeliverer` l'appelle au lieu de recopier
     * dix variables et un repli, et il n'existe plus qu'UNE autorite sur ce
     * texte.
     *
     * `$urlOverride` sert le JOURNAL. Le pipeline archive un corps expurge de
     * son jeton : composer deux fois — une avec le vrai lien, une avec le lien
     * masque — est plus sur que d'essayer de retirer un jeton d'un HTML deja
     * interpole.
     *
     * @return array{subject: string, body: string, fallback_reason: string|null}
     */
    /**
     * TASK-1719 — le courriel qui dit « on vous a AJOUTE a cette Boucle ».
     *
     * Le MEME gabarit `loop_invitation` que l'invitation, demande explicite de
     * MASTER. Les variables qu'il interpole ont toutes un sens ici — qui
     * ajoute, quelle Boucle, quelle Organization — et `invitation_url` pointe
     * vers la Boucle elle-meme : il n'existe aucun jeton a suivre, la personne
     * EST deja membre quand elle lit le message.
     *
     * `expires_at` repart vide, et c'est juste : un ajout n'expire pas.
     *
     * Passe par `rendreGabarit()`, comme `compose()` : la resolution du
     * gabarit, l'interpolation, le repli et sa trace sont ecrits UNE fois.
     * Les dupliquer aurait garanti que les deux courriels divergent — ce que
     * cette TASK existe precisement pour corriger.
     *
     * @return array{subject: string, body: string, fallback_reason: string|null}
     */
    public function composeForMember(
        Loop $loop,
        User $membre,
        ?User $acteur = null,
        ?string $urlOverride = null,
        ?string $locale = null,
    ): array {
        $langue = $locale ?: app()->getLocale();
        $langueOrigine = app()->getLocale();
        app()->setLocale($langue);

        try {
            $organization = $loop->organization;
            $url = $urlOverride ?? $loop->workspaceUrl();

            return $this->rendreGabarit(
                organizationId: (string) $loop->organization_id,
                langue: $langue,
                sender: $acteur,
                vars: [
                    'recipient_name' => $membre->publicDisplayName(),
                    'recipient_email' => $membre->email,
                    'sender_name' => $acteur?->fullName ?? '',
                    'organization_name' => $organization?->name ?? '',
                    'loop_name' => $loop->name ?? '',
                    'loop_tagline' => $loop->tagline ?? '',
                    'personal_message' => __('loops.member_added_default_message', ['loop' => $loop->name]),
                    'invitation_url' => $url,
                    'expires_at' => '',
                    'app_name' => config('app.name'),
                ],
                repli: fn () => [
                    'subject' => __('loops.member_added_mail_subject', ['loop' => $loop->name]),
                    'html' => view('emails.loop-member-added', [
                        'loop' => $loop,
                        'organization' => $organization,
                        'membre' => $membre,
                        'acteur' => $acteur,
                        'url' => $url,
                    ])->render(),
                ],
            );
        } finally {
            app()->setLocale($langueOrigine);
        }
    }

    /**
     * TASK-1719 — resoudre le gabarit `loop_invitation`, l'interpoler, et
     * retomber proprement. En UN seul endroit.
     *
     * Les deux courriels — invitation et ajout direct — partagent ce bloc.
     * L'avoir recopie aurait garanti qu'ils divergent sur le gabarit choisi,
     * sur les variables admises, ou sur ce qui est journalise quand le repli
     * sert.
     *
     * @param  array<string, string>  $vars
     * @param  callable(): array{subject: string, html: string}  $repli
     * @return array{subject: string, body: string, fallback_reason: string|null}
     */
    private function rendreGabarit(
        string $organizationId,
        string $langue,
        ?User $sender,
        array $vars,
        callable $repli,
    ): array {
        $resolver = app(SystemEmailTemplateResolver::class);
        $template = $resolver->resolve('loop_invitation', $organizationId, $langue);

        $emailer = app(EmailerService::class);
        $extraKeys = array_keys($vars);
        $vars = array_merge($sender ? $emailer->availableVariables($sender) : [], $vars);

        $fallbackReason = null;
        $subject = null;
        $html = null;

        if ($template) {
            try {
                $subject = $emailer->interpolateSubject($template->subject, $vars, $extraKeys);
                $html = $emailer->interpolate($template->content_html, $vars, $extraKeys);
            } catch (\Throwable $e) {
                $fallbackReason = 'render_error: '.$e->getMessage();
            }
        } else {
            $fallbackReason = $resolver->diagnoseAbsence('loop_invitation', $organizationId);
        }

        if ($fallbackReason !== null) {
            Log::warning('loop_invitation e-mail fell back to the Blade template', [
                'reason' => $fallbackReason,
                'organization_id' => $organizationId,
                'locale' => $langue,
            ]);

            $secours = $repli();
            $subject = $secours['subject'];
            $html = $secours['html'];
        }

        return ['subject' => $subject, 'body' => $html, 'fallback_reason' => $fallbackReason];
    }

    public function compose(LoopInvitation $invitation, ?string $urlOverride = null, ?string $locale = null): array
    {
        // TASK-1719 — la langue TRAVERSE la composition.
        //
        // Le pipeline Notifications fige la langue du destinataire au moment
        // de l'envoi : c'est la seule qu'on pourra affirmer plus tard. Sans la
        // faire descendre jusqu'ici, le gabarit serait choisi — et le repli
        // traduit — dans la langue de l'EXPEDITEUR, ce qui est la langue de
        // personne. `null` garde le comportement de `send()`, inchange.
        $langue = $locale ?: app()->getLocale();
        $langueOrigine = app()->getLocale();
        app()->setLocale($langue);

        try {
        $loop = $invitation->loop;
        $organization = $invitation->organization;
        $sender = $invitation->sender;
        $landingUrl = $urlOverride ?? route('loop-invitations.show', $invitation->token);
        $personalMessage = filled($invitation->message)
            ? $invitation->message
            : __('loops.invitation_default_message', ['loop' => $loop?->name]);

        // TASK-1694 bug M — la resolution passe par l'autorite unique. Elle ne
        // regardait que les lignes du tenant : une ligne « Global » lui etait
        // invisible. Les crans et leur ordre : voir
        // `SystemEmailTemplateResolver`.
        $resolver = app(SystemEmailTemplateResolver::class);
        $template = $resolver->resolve('loop_invitation', $invitation->organization_id, $langue);

        $emailer = app(EmailerService::class);
        $extraKeys = [
            'recipient_name', 'recipient_email', 'sender_name', 'organization_name',
            'loop_name', 'loop_tagline', 'personal_message', 'invitation_url',
            'expires_at', 'app_name',
        ];
        $vars = array_merge(
            $sender ? $emailer->availableVariables($sender) : [],
            [
                'recipient_name' => $invitation->recipient_name ?: $invitation->recipient_email,
                'recipient_email' => $invitation->recipient_email,
                'sender_name' => $sender?->fullName ?? '',
                'organization_name' => $organization?->name ?? '',
                'loop_name' => $loop?->name ?? '',
                'loop_tagline' => $loop?->tagline ?? '',
                'personal_message' => $personalMessage,
                'invitation_url' => $landingUrl,
                'expires_at' => optional($invitation->expires_at)->isoFormat('LL') ?? '',
                'app_name' => config('app.name'),
            ],
        );

        $fallbackReason = null;

        if ($template) {
            try {
                $subject = $emailer->interpolateSubject($template->subject, $vars, $extraKeys);
                $html = $emailer->interpolate($template->content_html, $vars, $extraKeys);
            } catch (\Throwable $e) {
                $fallbackReason = 'render_error: '.$e->getMessage();
            }
        } else {
            // Le diagnostic interroge LES MEMES crans que la resolution : une
            // question posee sur d'autres lignes que celles reellement
            // consultees produirait un journal faux.
            $fallbackReason = $resolver->diagnoseAbsence('loop_invitation', $invitation->organization_id);
        }

        if ($fallbackReason !== null) {
            Log::warning('loop_invitation e-mail fell back to the Blade template', [
                'reason' => $fallbackReason,
                'invitation_id' => $invitation->id,
                'organization_id' => $invitation->organization_id,
                'locale' => app()->getLocale(),
            ]);

            $subject = __('loops.invitation_mail_subject', ['loop' => $loop?->name]);
            $html = view('emails.loop-invitation', [
                'invitation' => $invitation,
                'loop' => $loop,
                'organization' => $organization,
                'sender' => $sender,
                'personalMessage' => $personalMessage,
                'landingUrl' => $landingUrl,
            ])->render();
        }


        return [
                'subject' => $subject,
                'body' => $html,
                'fallback_reason' => $fallbackReason,
            ];
        } finally {
            // Restauree quoi qu'il arrive : composer un courriel ne doit pas
            // laisser la requete en cours dans une autre langue.
            app()->setLocale($langueOrigine);
        }
    }
}
