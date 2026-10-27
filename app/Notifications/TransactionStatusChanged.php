<?php

namespace App\Notifications;

use App\Models\SystemEmailTemplate;
use App\Support\Email\SystemEmailTemplateResolver;
use App\Models\Transaction;
use App\Services\EmailerService;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TransactionStatusChanged extends Notification
{
    public function __construct(public readonly Transaction $transaction) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $template = $this->resolveTemplate('transaction_status_changed', $notifiable);

        if ($template) {
            $service = $this->transaction->service;
            $title = $service ? $service->title : ($this->transaction->serviceRequest?->title ?? 'Échange');

            $emailer = app(EmailerService::class);
            $extraVars = [
                'title' => $title,
                'status_label' => $this->transaction->status_label,
                'points' => (string) ($this->transaction->points_agreed ?? $this->transaction->points_proposed),
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
            ->subject('Mise à jour de votre échange — '.$this->transaction->status_label)
            ->markdown('emails.transaction_status', [
                'user' => $notifiable,
                'transaction' => $this->transaction,
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
