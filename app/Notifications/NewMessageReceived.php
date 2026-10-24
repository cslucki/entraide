<?php

namespace App\Notifications;

use App\Models\Message;
use App\Models\SystemEmailTemplate;
use App\Support\Email\SystemEmailTemplateResolver;
use App\Models\Transaction;
use App\Services\EmailerService;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewMessageReceived extends Notification
{
    public function __construct(
        public readonly Transaction $transaction,
        public readonly Message $message,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $template = $this->resolveTemplate('new_message', $notifiable);

        if ($template) {
            $senderName = $this->message->sender?->name ?? 'Système';
            $service = $this->transaction->service;
            $transactionTitle = $service ? $service->title : ($this->transaction->serviceRequest?->title ?? 'Échange');
            $messagePreview = mb_strimwidth(strip_tags($this->message->body ?? ''), 0, 120, '…');

            $emailer = app(EmailerService::class);
            $extraVars = [
                'sender_name' => $senderName,
                'transaction_title' => $transactionTitle,
                'message_preview' => $messagePreview,
                'url' => route('messages.show', $this->transaction),
            ];
            $vars = array_merge($emailer->availableVariables($notifiable), $extraVars);

            return (new MailMessage)
                ->subject($emailer->interpolateSubject($template->subject, $vars, array_keys($extraVars)))
                // TASK-1694 bug P — `markdown()` et non `view()` : c'est ce chemin
                // qui declenche l'habillage et l'incorporation des styles.
                ->markdown('emails.system-email', [
                    'html' => $emailer->interpolate($template->content_html, $vars, array_keys($extraVars)),
                ]);
        }

        return (new MailMessage)
            ->subject('Nouveau message de '.($this->message->sender?->name ?? 'Système'))
            ->markdown('emails.new_message', [
                'user' => $notifiable,
                'transaction' => $this->transaction,
                'message' => $this->message,
            ]);
    }

    /**
     * TASK-1694 bug M — la résolution a QUITTÉ ce fichier.
     *
     * Cette méthode était l'une des sept copies de la même logique, et elle ne
     * regardait que les lignes du tenant : une ligne « Global » lui était
     * invisible.
     * `SystemEmailTemplateResolver` est désormais l'autorité unique — six crans,
     * du plus spécifique au Blade. Voir sa documentation.
     */
    private function resolveTemplate(string $slug, object $notifiable): ?SystemEmailTemplate
    {
        return app(SystemEmailTemplateResolver::class)->resolveForNotifiable($slug, $notifiable);
    }
}
