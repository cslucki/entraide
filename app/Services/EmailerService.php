<?php

namespace App\Services;

use App\Models\CrmContact;
use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Throwable;

class EmailerService
{
    public const ALLOWED_VARS = ['first_name', 'name', 'full_name', 'email', 'organization', 'city'];

    public function availableVariables(User $user): array
    {
        return [
            'first_name' => $user->first_name ?? '',
            'name' => $user->fullName,
            'full_name' => $user->fullName,
            'email' => $user->email,
            'organization' => $user->organization?->name ?? '',
            'city' => $user->city ?? '',
        ];
    }

    /**
     * TASK-1421 — les memes variables, lues sur un Contact CRM (sans faux User).
     * `city` reste une chaine vide : le Contact n'en a pas, le contrat des
     * modeles historiques l'attend.
     */
    public function availableVariablesForContact(CrmContact $contact): array
    {
        return [
            'first_name' => $contact->first_name ?? '',
            'name' => $contact->last_name ?? '',
            'full_name' => $contact->fullName,
            'email' => $contact->email ?? '',
            'organization' => $contact->organization?->name ?? '',
            'city' => '',
            'company' => $contact->company ?? '',
        ];
    }

    public function interpolate(string $content, array $variables, array $extraAllowedVars = []): string
    {
        $allowed = array_merge(self::ALLOWED_VARS, $extraAllowedVars);

        return preg_replace_callback('/\{\{\s*(\w+)\s*\}\}/', function ($matches) use ($variables, $allowed) {
            $key = $matches[1];
            if (in_array($key, $allowed, true) && isset($variables[$key])) {
                return e($variables[$key]);
            }

            return $matches[0];
        }, $content);
    }

    public function interpolateSubject(string $subject, array $variables, array $extraAllowedVars = []): string
    {
        $allowed = array_merge(self::ALLOWED_VARS, $extraAllowedVars);

        return preg_replace_callback('/\{\{\s*(\w+)\s*\}\}/', function ($matches) use ($variables, $allowed) {
            $key = $matches[1];
            if (in_array($key, $allowed, true) && isset($variables[$key])) {
                return strip_tags($variables[$key]);
            }

            return $matches[0];
        }, $subject);
    }

    public function sendFromTemplate(EmailTemplate $template, User $user, ?User $sender = null): EmailLog
    {
        $variables = $this->availableVariables($user);
        $html = $this->interpolate($template->content_html, $variables);
        $subject = $this->interpolateSubject($template->subject, $variables);

        try {
            Mail::html($html, function ($message) use ($user, $subject, $sender) {
                $message->to($user->email, $user->fullName)
                    ->subject($subject);

                if ($sender) {
                    $message->replyTo($sender->email, $sender->fullName);
                }
            });

            $status = 'sent';
            $error = null;
        } catch (Throwable $e) {
            $status = 'failed';
            $error = $e->getMessage();
        }

        return EmailLog::create([
            'template_id' => $template->id,
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'to_email' => $user->email,
            'subject' => $subject,
            'status' => $status,
            'error_message' => $error,
            'data' => [
                'source' => 'emailer',
                'sender_id' => $sender?->id,
                'template_slug' => $template->slug,
            ],
        ]);
    }

    /**
     * TASK-1421 — envoi a un Contact CRM : meme transport, meme journal, mais
     * le destinataire est l'adresse du Contact et la preuve porte
     * `crm_contact_id` (et `user_id` seulement si le Contact est relie a un
     * compte). Le corps envoye est conserve (`body_html` + `body_hash`,
     * colonnes T1383) pour rester relisible depuis la fiche.
     */
    /**
     * TASK-1431 (MASTER Q53) : `$sender` est l'ACTEUR (audit : sender_id, timeline) ;
     * `$replyTo` est l'adresse a laquelle le prospect repond — par defaut l'acteur,
     * mais jamais un admin plateforme au nom d'un tenant dont il n'est pas membre.
     */
    public function sendFromTemplateToContact(EmailTemplate $template, CrmContact $contact, ?User $sender = null, ?User $replyTo = null): EmailLog
    {
        $replyTo ??= $sender;
        $variables = $this->availableVariablesForContact($contact);
        $extra = ['company'];
        $html = $this->interpolate($template->content_html, $variables, $extra);
        $subject = $this->interpolateSubject($template->subject, $variables, $extra);
        $to = CrmContact::normalizeEmail($contact->email);
        $name = $contact->fullName !== '' ? $contact->fullName : $to;

        try {
            Mail::html($html, function ($message) use ($to, $name, $subject, $replyTo) {
                $message->to($to, $name)
                    ->subject($subject);

                if ($replyTo) {
                    $message->replyTo($replyTo->email, $replyTo->fullName);
                }
            });

            $status = EmailLog::STATUS_SENT;
            $error = null;
        } catch (Throwable $e) {
            $status = EmailLog::STATUS_FAILED;
            $error = $e->getMessage();
        }

        return EmailLog::create([
            'template_id' => $template->id,
            'user_id' => $contact->user_id,
            'crm_contact_id' => $contact->id,
            'organization_id' => $contact->organization_id,
            'to_email' => $to,
            'subject' => $subject,
            'status' => $status,
            'error_message' => $error,
            'body_html' => $html,
            'body_hash' => hash('sha256', $html),
            'data' => [
                'source' => 'crm',
                'sender_id' => $sender?->id,
                'reply_to_id' => $replyTo?->id,
                'template_slug' => $template->slug,
            ],
        ]);
    }
}
