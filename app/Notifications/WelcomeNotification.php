<?php

namespace App\Notifications;

use App\Models\SystemEmailTemplate;
use App\Support\Email\SystemEmailTemplateResolver;
use App\Services\EmailerService;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WelcomeNotification extends Notification
{
    /**
     * TASK-1694 bug H — le slug dit ce que l'email ANNONCE, plus un moment.
     *
     * « welcome » datait d'une epoque ou il partait a l'inscription. Il annonce
     * en realite l'AFFECTATION DES POINTS, et il part desormais apres la
     * confirmation d'adresse (bug I). Le nouveau slug le dit, et il est
     * symetrique de celui de la confirmation.
     *
     * Une CONSTANTE plutot qu'une chaine repetee : le slug vit maintenant dans
     * le code, les seeders et les tests. Un renommage de plus ne doit pas
     * demander de les retrouver un par un.
     */
    public const SLUG = 'affectation-points-suite-inscription';

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $template = $this->resolveTemplate(self::SLUG, $notifiable);

        if ($template) {
            $emailer = app(EmailerService::class);
            $extraVars = ['url' => route('explorer')];
            $vars = array_merge($emailer->availableVariables($notifiable), $extraVars);

            return (new MailMessage)
                ->subject($emailer->interpolateSubject($template->subject, $vars, array_keys($extraVars)))
                ->view('emails.system-email', [
                    'html' => $emailer->interpolate($template->content_html, $vars, array_keys($extraVars)),
                ]);
        }

        // TASK-1694 bug G — le repli nomme l'ORGANIZATION, plus la marque.
        return (new MailMessage)
            ->subject(__('Bienvenue sur :organisation !', [
                // Un destinataire ANONYME (`AnonymousNotifiable`, route mail
                // directe) n'a ni `organization` ni meme la propriete : lire
                // `$notifiable->organization` dessus leverait. Le nom de
                // l'application est le repli — un email sans nom du tout serait
                // pire qu'un nom approximatif.
                'organisation' => ($notifiable instanceof \App\Models\User
                    ? $notifiable->organization?->name
                    : null) ?: config('app.name'),
            ]))
            ->markdown('emails.welcome', [
                'user' => $notifiable,
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
