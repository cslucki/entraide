<?php

namespace App\Services;

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
}
