<?php

namespace App\Services\Feed;

use App\Models\FeedPost;
use App\Models\Organization;
use App\Models\User;
use App\Support\Feed\FeedMentions;
use App\Support\Notifications\GabaritEmail;

/**
 * TASK-1880 (demande Cyril, 22/11) — le courriel « {Prenom} vous a identifie
 * sur le Flux ».
 *
 * Il porte la TOTALITE du message de l'annonce (« comme ca la personne n'a pas
 * forcement le besoin d'aller sur le site ») : texte brut, paragraphes
 * conserves, jamais le HTML de l'annonce — `EmailerService::interpolate()`
 * echappe chaque variable, et `white-space: pre-line` rend les sauts de ligne.
 *
 * La copie archivee dans `email_logs` (`$pourJournal`) garde la forme du
 * courriel mais expurge le message, meme regle que les mentions du ChatLoop.
 */
final class FeedMentionMailer
{
    public const SLUG = 'feed_post_mention_created';

    public const MESSAGE_EXPURGE = '[message-redacted]';

    /**
     * @return array{subject: string, body: string, fallback_reason: string|null}
     */
    public function compose(
        FeedPost $post,
        User $destinataire,
        ?User $acteur,
        string $url,
        string $langue,
        bool $pourJournal = false,
    ): array {
        $langueOrigine = app()->getLocale();
        app()->setLocale($langue);

        try {
            $organization = Organization::query()->whereKey($post->organization_id)->first();

            $prenom = $acteur === null || $acteur->isDeactivated()
                ? __('profile.deactivated_user')
                : ($acteur->first_name ?: ($acteur->name ?: __('profile.deactivated_user')));

            $message = FeedMentions::texte((string) $post->content);

            if (filled($post->title)) {
                $message = trim((string) $post->title)."\n\n".$message;
            }

            if ($pourJournal) {
                $message = self::MESSAGE_EXPURGE;
            }

            $urlReglages = $pourJournal
                ? $url
                : ($organization !== null
                    ? route('organization.notifications.preferences.edit', ['organization' => $organization])
                    : route('notifications.preferences.edit'));

            return app(GabaritEmail::class)->rendre(
                slug: self::SLUG,
                organizationId: (string) $post->organization_id,
                langue: $langue,
                sender: $acteur,
                vars: [
                    'recipient_name' => $destinataire->publicDisplayName(),
                    'recipient_email' => $destinataire->email,
                    'author_first_name' => $prenom,
                    'organization_name' => $organization?->name ?? '',
                    'post_message' => $message,
                    'post_url' => $url,
                    'preferences_url' => $urlReglages,
                    'app_name' => config('app.name'),
                ],
                // Le message passe EN DERNIER : il peut contenir « :url ».
                repli: fn () => [
                    'subject' => str_replace(':prenom', $prenom, (string) __('notifications.email.'.self::SLUG.'.subject')),
                    'html' => str_replace(
                        ':message',
                        e($message),
                        str_replace(
                            [':url', ':reglages', ':prenom'],
                            [$url, $urlReglages, e($prenom)],
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
