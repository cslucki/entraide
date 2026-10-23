<?php

namespace App\Notifications;

use App\Models\SystemEmailTemplate;
use App\Support\Email\SystemEmailTemplateResolver;
use App\Services\EmailerService;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AiBudgetExceeded extends Notification
{
    public function __construct(
        public readonly string $scenarioId,
        public readonly float $currentCost,
        public readonly float $budgetLimit,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $template = $this->resolveTemplate('ai_budget_exceeded', $notifiable);

        if ($template) {
            $emailer = app(EmailerService::class);
            $extraVars = [
                'scenario_id' => $this->scenarioId,
                'current_cost' => (string) $this->currentCost,
                'budget_limit' => (string) $this->budgetLimit,
                'url' => route('explorer'),
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
            ->subject('Alerte budget IA — '.$this->scenarioId)
            ->markdown('emails.ai_budget_exceeded', [
                'user' => $notifiable,
                'scenarioId' => $this->scenarioId,
                'currentCost' => $this->currentCost,
                'budgetLimit' => $this->budgetLimit,
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
