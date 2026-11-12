<?php

namespace App\Services\Loops;

use App\Models\Loop;
use App\Models\LoopEvent;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\Scopes\BelongsToOrganizationScope;
use App\Models\User;
use App\Support\Notifications\GabaritEmail;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * TASK-1802 (demande Cyril) — le courriel « un nouvel évènement est proposé
 * dans votre Boucle ».
 *
 * Écrit sur le patron de `BlogCommentMailer`/`LoopMentionMailer` (mêmes trois
 * contraintes, reprises telles quelles) :
 *
 * ## 1. Le texte ne vit PAS ici
 *
 * Il est posé en base par migration (Global, FR et EN) et le SuperAdmin peut
 * le réécrire depuis `/admin/system-email-templates`. Ce fichier ne porte que
 * les **variables offertes** et le **repli**.
 *
 * ## 2. L'OBJET relu est le MESSAGE d'annonce, jamais l'Évènement
 *
 * `MemberNotification.object_id` désigne la Boucle (même doctrine que
 * `LOOP_HELP_REQUEST_CREATED` : c'est ce qui permet à la sourdine PAR BOUCLE
 * de s'appliquer). Le FAIT précis — quel Évènement, à quelle date, où — vit
 * dans `event_id`, qui porte l'identifiant du MESSAGE d'annonce : cette classe
 * le relit, et lit son `metadata` plutôt que l'Évènement lui-même. Les deux se
 * valent : `LoopMessageService::sendEventMessage()` y duplique déjà
 * `description`/`location`/`meeting_url` au moment de la création, précisément
 * pour que ce courriel n'ait besoin que du message.
 *
 * ## 3. Tout se lit sans les scopes globaux, puis se borne à la main
 *
 * Ce code tourne dans un **worker**. Il n'y a alors aucune
 * `current_organization`, donc `BelongsToOrganizationScope` applique son
 * fail-closed et les objets ressortent INTROUVABLES — en silence.
 */
final class LoopEventMailer
{
    public const SLUG = 'loop_event_created';

    /** Même borne que les autres mailers : assez pour situer, pas pour remplacer la lecture sur site. */
    public const EXTRAIT_MAX = 400;

    public const EXTRAIT_EXPURGE = '[description-redacted]';

    /**
     * @param  bool  $pourJournal  rend la variante destinée à `email_logs` :
     *                             la description est expurgée.
     * @return array{subject: string, body: string, fallback_reason: string|null}
     */
    public function compose(
        LoopMessage $message,
        User $destinataire,
        ?User $acteur,
        string $url,
        string $langue,
        bool $pourJournal = false,
    ): array {
        $langueOrigine = app()->getLocale();
        app()->setLocale($langue);

        try {
            $boucle = Loop::query()
                ->withoutGlobalScope(BelongsToOrganizationScope::class)
                ->whereKey($message->loop_id)
                ->where('organization_id', $message->organization_id)
                ->first();

            $organization = Organization::query()
                ->withoutGlobalScope(BelongsToOrganizationScope::class)
                ->whereKey($message->organization_id)
                ->first();

            $meta = $message->metadata ?? [];

            $titre = (string) ($meta['title'] ?? '');
            $timezone = (string) ($meta['timezone'] ?? 'UTC');
            $startsAt = isset($meta['starts_at'])
                ? CarbonImmutable::parse((string) $meta['starts_at'])->setTimezone($timezone)
                : null;
            $date = $startsAt?->isoFormat('D MMM YYYY, HH:mm') ?? '';

            // La description est du texte écrit par un membre, au même titre
            // que l'extrait d'un commentaire : `email_logs` n'a pas à
            // l'archiver (cf. `BlogCommentMailer::EXTRAIT_EXPURGE`).
            $description = trim((string) ($meta['description'] ?? ''));
            $descriptionAffichee = $description === ''
                ? (string) __('notifications.loop_event_email_description_empty')
                : Str::limit($description, self::EXTRAIT_MAX);

            if ($pourJournal) {
                $descriptionAffichee = self::EXTRAIT_EXPURGE;
            }

            $format = (string) ($meta['format'] ?? LoopEvent::FORMAT_IN_PERSON);
            $infosPratiques = match ($format) {
                LoopEvent::FORMAT_ONLINE => __('notifications.loop_event_email_practical_online', [
                    'url' => (string) ($meta['meeting_url'] ?? ''),
                ]),
                LoopEvent::FORMAT_HYBRID => __('notifications.loop_event_email_practical_hybrid', [
                    'location' => (string) ($meta['location'] ?? ''),
                    'url' => (string) ($meta['meeting_url'] ?? ''),
                ]),
                default => __('notifications.loop_event_email_practical_in_person', [
                    'location' => (string) ($meta['location'] ?? ''),
                ]),
            };

            // Même raison qu'en T1784 : préférences DE L'ORGANIZATION, le slug
            // plutôt que l'id parce que cette adresse part dans un courriel.
            $urlReglages = $pourJournal
                ? $url
                : ($organization !== null
                    ? route('organization.notifications.preferences.edit', ['organization' => $organization])
                    : route('notifications.preferences.edit'));

            return app(GabaritEmail::class)->rendre(
                slug: self::SLUG,
                organizationId: (string) $message->organization_id,
                langue: $langue,
                sender: $acteur,
                vars: [
                    'recipient_name' => $destinataire->publicDisplayName(),
                    'recipient_email' => $destinataire->email,
                    'author_name' => $acteur?->publicDisplayName() ?? '',
                    'organization_name' => $organization?->name ?? '',
                    'loop_name' => $boucle?->name ?? '',
                    'event_title' => $titre,
                    'event_date' => $date,
                    'event_description' => $descriptionAffichee,
                    'event_practical_info' => $infosPratiques,
                    'event_url' => $url,
                    'preferences_url' => $urlReglages,
                    'app_name' => config('app.name'),
                ],
                // Le repli reprend le texte de `lang/{locale}/notifications.php` :
                // une troisième version du même message serait une source de
                // divergence de plus.
                //
                // La description passe EN DERNIER : elle porte du texte écrit
                // par un membre, qui pourrait contenir « :url » — substitué
                // plus tôt, il serait relu comme un jeton. Échappée à la main,
                // parce que ce chemin ne traverse pas `interpolate()`.
                repli: fn () => [
                    'subject' => str_replace(
                        [':titre', ':date'],
                        [e($titre), e($date)],
                        (string) __('notifications.email.'.self::SLUG.'.subject'),
                    ),
                    'html' => str_replace(
                        ':description',
                        e($descriptionAffichee),
                        str_replace(
                            [':url', ':reglages', ':titre', ':date', ':boucle', ':infos'],
                            [$url, $urlReglages, e($titre), e($date), e((string) ($boucle?->name ?? '')), e($infosPratiques)],
                            (string) __('notifications.email.'.self::SLUG.'.body'),
                        ),
                    ),
                ],
            );
        } finally {
            app()->setLocale($langueOrigine);
        }
    }
}
