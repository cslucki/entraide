<?php

namespace App\Notifications;

use App\Models\SystemEmailTemplate;
use App\Services\EmailerService;
use Illuminate\Auth\Notifications\VerifyEmail as FrameworkVerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * TASK-1694 bug D — l'email de confirmation passe par un gabarit modifiable.
 *
 * ## Ce qui manquait
 *
 * L'email partait du gabarit Laravel EN DUR : impossible d'en changer un mot
 * sans toucher au code, et invisible dans `/admin/system-email-templates` ou
 * vivent tous les autres.
 *
 * ## GLOBAL, et pas par Organization — arbitrage de Cyril
 *
 * « Je pense que c'est bien que l'email de verification soit Global, on ne va
 * pas creer un email pour chaque organisation. » La colonne `organization_id`
 * NULLABLE porte deja cette notion : quatre slugs ont une ligne globale. On ne
 * cherche donc JAMAIS de variante par tenant ici — une recherche par
 * Organization laisserait croire qu'elle est personnalisable.
 *
 * ## Deux langues, et un repli qui ne ment pas
 *
 * La langue est celle de la personne (`preferred_locale`), sinon celle de
 * l'application. Si le gabarit manque dans cette langue, on prend le francais ;
 * s'il manque tout court, on retombe sur le gabarit du FRAMEWORK plutot que
 * d'envoyer un email vide. Un email de confirmation qui n'arrive pas rend le
 * compte inutilisable : le repli est une garantie, pas une politesse.
 *
 * ## Ce que cette classe ne refait PAS
 *
 * L'URL signee. Elle est construite par `FrameworkVerifyEmail::verificationUrl()`,
 * qui lit `auth.verification.expire` — les 24 heures du bug C. La recopier ici
 * creerait une seconde verite sur la duree, et les deux divergeraient.
 */
class VerifyEmailWithTemplate extends FrameworkVerifyEmail
{
    public const SLUG = 'email_verification';

    public function toMail($notifiable): MailMessage
    {
        $template = $this->resolveTemplate($notifiable);

        if ($template === null) {
            // Le gabarit du framework, avec la MEME url signee.
            return parent::toMail($notifiable);
        }

        $emailer = app(EmailerService::class);
        $extra = ['url' => $this->verificationUrl($notifiable)];
        $vars = array_merge($emailer->availableVariables($notifiable), $extra);

        return (new MailMessage)
            ->subject($emailer->interpolateSubject($template->subject, $vars, array_keys($extra)))
            ->view('emails.system-email', [
                'html' => $emailer->interpolate($template->content_html, $vars, array_keys($extra)),
            ]);
    }

    private function resolveTemplate(object $notifiable): ?SystemEmailTemplate
    {
        $locale = $notifiable->preferred_locale ?? app()->getLocale();

        $base = SystemEmailTemplate::query()
            ->where('slug', self::SLUG)
            ->where('enabled', true)
            ->whereNull('organization_id');

        return (clone $base)->where('locale', $locale)->first()
            ?? (clone $base)->where('locale', 'fr')->first();
    }
}
