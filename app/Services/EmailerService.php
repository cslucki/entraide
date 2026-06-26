<?php

namespace App\Services;

use App\Models\EmailLog;
use App\Models\EmailTemplate;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Throwable;

class EmailerService
{
    private const ALLOWED_VARS = ['first_name', 'name', 'email', 'organization', 'city'];

    public function availableVariables(User $user): array
    {
        return [
            'first_name' => $user->first_name ?? '',
            'name' => $user->name,
            'email' => $user->email,
            'organization' => $user->organization?->name ?? '',
            'city' => $user->city ?? '',
        ];
    }

    public function interpolate(string $content, array $variables): string
    {
        return preg_replace_callback('/\{\{\s*(\w+)\s*\}\}/', function ($matches) use ($variables) {
            $key = $matches[1];
            if (in_array($key, self::ALLOWED_VARS, true) && isset($variables[$key])) {
                return e($variables[$key]);
            }

            return $matches[0];
        }, $content);
    }

    public function interpolateSubject(string $subject, array $variables): string
    {
        return preg_replace_callback('/\{\{\s*(\w+)\s*\}\}/', function ($matches) use ($variables) {
            $key = $matches[1];
            if (in_array($key, self::ALLOWED_VARS, true) && isset($variables[$key])) {
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
                $message->to($user->email, $user->name)
                    ->subject($subject);

                if ($sender) {
                    $message->replyTo($sender->email, $sender->name);
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
