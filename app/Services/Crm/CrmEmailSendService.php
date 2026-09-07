<?php

namespace App\Services\Crm;

use App\Models\CrmContact;
use App\Models\CrmContactEvent;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\User;
use App\Services\EmailerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * TASK-1421 — CRM-7b : envoyer un modele d'email a un Contact, par un humain.
 *
 * Fail-closed, dans l'ordre, AVANT la preview finale et AVANT l'envoi :
 * meme Organization (modele ET Contact), Contact contactable
 * (`do_not_contact_at` NULL), email present, expediteur de l'Organization ou
 * admin plateforme. Aucun envoi automatique, aucun retry : si le transport
 * echoue, la preuve dit « failed » et l'humain decide.
 *
 * Un email reellement accepte par le transport EST une interaction : il
 * avance `last_interaction_at`. Un echec n'en est pas une.
 */
class CrmEmailSendService
{
    public const TOKEN_SESSION_PREFIX = 'crm_email_token:';

    public function __construct(
        private readonly EmailerService $emailer,
    ) {}

    public const TOKEN_TTL_SECONDS = 900;

    /**
     * Verifie toutes les gardes ; leve LogicException avec un code lisible.
     * `$sending` : a l'envoi, l'expediteur commercial doit etre MEMBRE de
     * l'Organization (arbitrage MASTER Q29) — un admin plateforme d'une autre
     * Organization peut regarder la preview, pas signer l'email du tenant.
     */
    public function guard(CrmContact $contact, EmailTemplate $template, User $sender, bool $sending = false): void
    {
        if ($template->organization_id === null || $template->organization_id !== $contact->organization_id) {
            throw new LogicException('template_organization');
        }

        if (! $contact->isContactable()) {
            throw new LogicException('do_not_contact');
        }

        if (CrmContact::normalizeEmail($contact->email) === null) {
            throw new LogicException('no_email');
        }

        if ($sending && $sender->organization_id !== $contact->organization_id) {
            throw new LogicException('sender_organization');
        }
    }

    /** Rendu pour CE Contact (objet + corps), sans rien ecrire. */
    public function render(CrmContact $contact, EmailTemplate $template): array
    {
        $variables = $this->emailer->availableVariablesForContact($contact);

        return [
            'subject' => $this->emailer->interpolateSubject($template->subject, $variables, CrmEmailTemplateService::EXTRA_ALLOWED_VARS),
            'html' => $this->emailer->interpolate($template->content_html, $variables, CrmEmailTemplateService::EXTRA_ALLOWED_VARS),
            'to' => $contact->email,
        ];
    }

    /**
     * Jeton one-shot : pose en session a la preview (une nouvelle preview
     * remplace l'ancien), exige et CONSOMME a l'envoi, avant tout appel au
     * transport ; perime apres 15 minutes.
     */
    public function issueToken(CrmContact $contact, EmailTemplate $template): string
    {
        $token = Str::random(40);
        session()->put($this->tokenKey($contact, $template), ['token' => $token, 'issued_at' => now()->timestamp]);

        return $token;
    }

    public function consumeToken(CrmContact $contact, EmailTemplate $template, ?string $presented): bool
    {
        $stored = session()->pull($this->tokenKey($contact, $template));

        if (! is_array($stored) || ! is_string($stored['token'] ?? null) || ! is_string($presented) || $presented === '') {
            return false;
        }

        if (now()->timestamp - (int) ($stored['issued_at'] ?? 0) > self::TOKEN_TTL_SECONDS) {
            return false;
        }

        return hash_equals($stored['token'], $presented);
    }

    /**
     * Envoie, journalise, trace. Retourne l'EmailLog (sent ou failed).
     */
    public function send(CrmContact $contact, EmailTemplate $template, User $sender): EmailLog
    {
        $this->guard($contact, $template, $sender, sending: true);

        $log = $this->emailer->sendFromTemplateToContact($template, $contact, $sender);

        DB::transaction(function () use ($contact, $template, $sender, $log) {
            $sent = $log->status === EmailLog::STATUS_SENT;
            $now = now();

            if ($sent) {
                $contact->last_interaction_at = $now;
                $contact->save();
            }

            CrmContactEvent::create([
                'organization_id' => $contact->organization_id,
                'crm_contact_id' => $contact->id,
                'type' => $sent ? CrmContactEvent::TYPE_EMAIL_SENT : CrmContactEvent::TYPE_EMAIL_FAILED,
                'author_user_id' => $sender->id,
                'occurred_at' => $now,
                'payload' => [
                    'template_id' => $template->id,
                    'template_name' => $template->name,
                    'subject' => $log->subject,
                    'to' => $log->to_email,
                    'log_id' => $log->id,
                    'error' => $sent ? null : Str::limit((string) $log->error_message, 200),
                ],
            ]);
        });

        return $log;
    }

    private function tokenKey(CrmContact $contact, EmailTemplate $template): string
    {
        return self::TOKEN_SESSION_PREFIX.$contact->organization_id.':'.$contact->id.':'.$template->id;
    }
}
