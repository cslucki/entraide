<?php

namespace App\Support\Notifications;

use App\Models\SystemEmailTemplate;
use App\Models\User;
use App\Services\EmailerService;
use App\Support\Email\SystemEmailTemplateResolver;
use Illuminate\Support\Facades\Log;

/**
 * TASK-1751 — le moteur de rendu d'un gabarit administrable, EXTRAIT.
 *
 * ## Pourquoi cette classe existe
 *
 * Ce code vivait en `private` dans `LoopInvitationMailer::rendreGabarit()`.
 * TASK-1751 avait besoin du meme moteur pour un fait qui n'a rien d'une
 * Boucle : une proposition d'echange. Deux sorties etaient possibles —
 * composer une transaction depuis une classe nommee « LoopInvitationMailer »,
 * ou recopier quarante lignes de resolution, d'interpolation et de repli.
 *
 * La premiere ment sur ce que fait le code. La seconde garantit que les deux
 * copies divergeront : la gestion du repli et le journal d'avertissement sont
 * precisement le genre de detail qu'on corrige d'un cote seulement.
 *
 * `LoopInvitationMailer::rendreGabarit()` DELEGUE ici et ne change pas de
 * comportement. Les suites d'email existantes le verifient.
 *
 * ## Ce que le moteur fait, et dans quel ordre
 *
 * 1. `SystemEmailTemplateResolver` cherche le gabarit (tenant+langue → tenant
 *    defaut → Global+langue → Global defaut → Global sans langue) ;
 * 2. trouve : interpolation du sujet et du corps ;
 * 3. absent, desactive, ou rendu en erreur : **repli**, et la RAISON est
 *    journalisee — sans quoi un gabarit casse enverrait silencieusement le
 *    texte de secours pendant des mois.
 */
final class GabaritEmail
{
    /**
     * @param  array<string, string>  $vars
     * @param  callable(): array{subject: string, html: string}  $repli
     * @param  SystemEmailTemplate|null  $templateResolu  TASK-1799 (A1) — quand
     *                                    l'appelant a DEJA resolu la meme ligne
     *                                    (meme slug, Organization, langue) pour
     *                                    un autre rendu de ce meme destinataire
     *                                    (ex. la copie expurgee de `email_logs`),
     *                                    il la passe ici pour eviter de relire
     *                                    deux fois une ligne identique. `null` ->
     *                                    comportement inchange, resolution ici.
     * @return array{subject: string, body: string, fallback_reason: string|null}
     */
    public function rendre(
        string $slug,
        string $organizationId,
        string $langue,
        ?User $sender,
        array $vars,
        callable $repli,
        ?SystemEmailTemplate $templateResolu = null,
    ): array {
        $resolver = app(SystemEmailTemplateResolver::class);
        $template = $templateResolu ?? $resolver->resolve($slug, $organizationId, $langue);

        $emailer = app(EmailerService::class);
        $extraKeys = array_keys($vars);
        $vars = array_merge($sender ? $emailer->availableVariables($sender) : [], $vars);

        $fallbackReason = null;
        $subject = null;
        $html = null;

        if ($template) {
            try {
                $subject = $emailer->interpolateSubject($template->subject, $vars, $extraKeys);
                $html = $emailer->interpolate($template->content_html, $vars, $extraKeys);
            } catch (\Throwable $e) {
                $fallbackReason = 'render_error: '.$e->getMessage();
            }
        } else {
            $fallbackReason = $resolver->diagnoseAbsence($slug, $organizationId);
        }

        if ($fallbackReason !== null) {
            Log::warning($slug.' e-mail fell back to the Blade template', [
                'reason' => $fallbackReason,
                'organization_id' => $organizationId,
                'locale' => $langue,
            ]);

            $secours = $repli();
            $subject = $secours['subject'];
            $html = $secours['html'];
        }

        return ['subject' => $subject, 'body' => $html, 'fallback_reason' => $fallbackReason];
    }
}
