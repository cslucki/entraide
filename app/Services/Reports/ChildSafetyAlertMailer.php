<?php

namespace App\Services\Reports;

use App\Models\BugReport;
use App\Models\BugReportAction;
use App\Models\EmailLog;
use App\Models\Organization;
use App\Services\EmailerService;
use App\Support\Email\SystemEmailTemplateResolver;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * TASK-1905 — alerte email au contact designe a chaque nouveau signalement
 * « Securite des enfants ». Patron TASK-1809 (`ReviewReceivedMailer`) :
 * `Mail::html()` direct, gabarit `system_email_templates` GLOBAL FR/EN,
 * trace `email_logs`.
 *
 * ## Ce que l'email ne porte JAMAIS (decision MASTER D6)
 *
 * La description, le signaleur (nom, email) et l'URL du contenu. Ces donnees
 * ne sont meme pas transmises au gabarit : seul le nom de l'organisation, la
 * date et le lien vers le back-office le sont. Les journaux ne portent que
 * des identifiants.
 *
 * ## Idempotence, y compris en dispatch concurrent
 *
 * L'alerte est RESERVEE par un `UPDATE ... WHERE child_safety_alerted_at IS
 * NULL` : la base n'accorde la ligne qu'a une seule execution. Toute autre
 * execution (rejeu manuel, double dispatch, deux workers) n'envoie rien.
 *
 * ## Echec
 *
 * Le signalement est deja enregistre : l'echec n'y touche pas. Trace
 * `email_logs` failed + action `alert_failed` dans l'historique ; aucun rejeu
 * (decision MASTER D7). Le back-office reste la source de verite.
 *
 * Le gabarit est resolu SANS organisation : une surcharge d'organisation ne
 * peut jamais modifier une alerte de plateforme. La trace `email_logs` n'a pas
 * d'`organization_id` : elle n'apparait dans aucun ecran d'organisation.
 */
final class ChildSafetyAlertMailer
{
    public const SLUG = 'child_safety_report_alert';

    public const LOG_SOURCE = 'child-safety-alert';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    public const NOT_CONFIGURED = 'not_configured';

    public const DUPLICATE = 'duplicate';

    public const SKIPPED = 'skipped';

    public function __construct(
        private readonly SystemEmailTemplateResolver $resolver,
        private readonly EmailerService $emailer,
    ) {}

    public function envoyer(BugReport $report): string
    {
        if (! $report->isChildSafety()) {
            return self::SKIPPED;
        }

        $reserve = BugReport::query()
            ->whereKey($report->id)
            ->whereNull('child_safety_alerted_at')
            ->update(['child_safety_alerted_at' => now()]);

        if ($reserve !== 1) {
            Log::info('child_safety_alert: deja reservee, aucun nouvel envoi.', ['bug_report_id' => $report->id]);

            return self::DUPLICATE;
        }

        $destinataire = trim((string) config('child_safety.alert_email'));

        if ($destinataire === '' || filter_var($destinataire, FILTER_VALIDATE_EMAIL) === false) {
            // Alerte technique EXPLICITE : le signalement existe, personne n'est prevenu.
            Log::critical('child_safety_alert: CHILD_SAFETY_ALERT_EMAIL absent ou invalide — aucune alerte envoyee pour un signalement « Securite des enfants ».', [
                'bug_report_id' => $report->id,
            ]);
            $report->recordAction(BugReportAction::ALERT_NOT_CONFIGURED);

            return self::NOT_CONFIGURED;
        }

        $rendu = null;
        $logData = ['source' => self::LOG_SOURCE, 'bug_report_id' => $report->id];

        try {
            $rendu = $this->rendre($report);
            $logData['template_used'] = $rendu['fallback'] ? 'lang_fallback' : 'system_email_template';

            Mail::html($rendu['body'], function ($message) use ($destinataire, $rendu) {
                $message->to($destinataire)->subject($rendu['subject']);
            });
        } catch (\Throwable $e) {
            Log::error('child_safety_alert: echec de l\'envoi, aucun rejeu automatique.', [
                'bug_report_id' => $report->id,
                'exception' => $e::class,
            ]);

            EmailLog::create([
                'user_id' => null,
                'organization_id' => null,
                'to_email' => $destinataire,
                'subject' => $rendu['subject'] ?? self::SLUG,
                'status' => EmailLog::STATUS_FAILED,
                'error_message' => mb_substr($e->getMessage(), 0, 1000),
                'data' => $logData,
            ]);
            $report->recordAction(BugReportAction::ALERT_FAILED);

            return self::FAILED;
        }

        EmailLog::create([
            'user_id' => null,
            'organization_id' => null,
            'to_email' => $destinataire,
            'subject' => $rendu['subject'],
            'status' => EmailLog::STATUS_SENT,
            'data' => $logData,
        ]);
        $report->recordAction(BugReportAction::ALERT_SENT);

        return self::SENT;
    }

    /** @return array{subject: string, body: string, fallback: bool} */
    private function rendre(BugReport $report): array
    {
        $locale = (string) config('child_safety.alert_locale', 'fr');
        $langueOrigine = app()->getLocale();
        app()->setLocale($locale);

        try {
            $vars = [
                'organization_name' => (string) (Organization::withTrashed()->whereKey($report->organization_id)->value('name') ?? ''),
                'report_date' => $report->created_at?->format('d/m/Y H:i') ?? now()->format('d/m/Y H:i'),
                'admin_url' => route('admin.bug-reports', ['category' => BugReport::CHILD_SAFETY, 'status' => 'pending']),
                'app_name' => (string) config('app.name'),
            ];

            $template = $this->resolver->resolve(self::SLUG, null, $locale);

            if ($template !== null) {
                try {
                    return [
                        'subject' => $this->emailer->interpolateSubject($template->subject, $vars, array_keys($vars)),
                        'body' => $this->emailer->interpolate($template->content_html, $vars, array_keys($vars)),
                        'fallback' => false,
                    ];
                } catch (\Throwable $e) {
                    Log::warning('child_safety_alert: rendu du gabarit impossible, repli.', ['bug_report_id' => $report->id, 'exception' => $e::class]);
                }
            } else {
                Log::warning('child_safety_alert: gabarit global absent, repli.', ['bug_report_id' => $report->id, 'locale' => $locale]);
            }

            return [
                'subject' => str_replace(':app', $vars['app_name'], (string) __('notifications.email.child_safety_report_alert.subject')),
                'body' => str_replace(
                    [':date', ':org', ':url'],
                    [e($vars['report_date']), e($vars['organization_name']), e($vars['admin_url'])],
                    (string) __('notifications.email.child_safety_report_alert.body'),
                ),
                'fallback' => true,
            ];
        } finally {
            app()->setLocale($langueOrigine);
        }
    }
}
