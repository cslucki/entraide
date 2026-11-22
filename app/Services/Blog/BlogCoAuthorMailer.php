<?php

namespace App\Services\Blog;

use App\Models\BlogPost;
use App\Models\Organization;
use App\Models\User;
use App\Support\Notifications\GabaritEmail;

/**
 * TASK-1884 #1 (demande Cyril, 22/11) — le courriel « {Prenom} vous a nomme
 * co-auteur d'un article ».
 *
 * Gabarit systeme GLOBAL `blog_co_author_added` (FR/EN), editable dans
 * /admin/system-email-templates. Le lien mene a la page de MODIFICATION de
 * l'article (arbitrage), calculee par `NotificationTargetResolver`.
 *
 * Rien d'ecrit par un membre n'y figure hors le titre de l'article, deja visible
 * de tous les co-auteurs : pas d'expurgation de contenu, seul le lien l'est dans
 * la copie archivee (`$url` recu = jeton du livreur).
 */
final class BlogCoAuthorMailer
{
    public const SLUG = 'blog_co_author_added';

    /**
     * @return array{subject: string, body: string, fallback_reason: string|null}
     */
    public function compose(BlogPost $post, User $destinataire, ?User $acteur, string $url, string $langue, bool $pourJournal = false): array
    {
        $langueOrigine = app()->getLocale();
        app()->setLocale($langue);

        try {
            $organization = Organization::query()->whereKey($post->organization_id)->first();

            $prenom = $acteur === null || $acteur->isDeactivated()
                ? __('profile.deactivated_user')
                : ($acteur->first_name ?: ($acteur->name ?: __('profile.deactivated_user')));

            $urlReglages = $pourJournal
                ? $url
                : ($organization !== null
                    ? route('organization.notifications.preferences.edit', ['organization' => $organization])
                    : route('notifications.preferences.edit'));

            $titre = (string) $post->title;

            return app(GabaritEmail::class)->rendre(
                slug: self::SLUG,
                organizationId: (string) $post->organization_id,
                langue: $langue,
                sender: $acteur,
                vars: [
                    'recipient_name' => $destinataire->first_name ?: $destinataire->publicDisplayName(),
                    'recipient_email' => $destinataire->email,
                    'author_first_name' => $prenom,
                    'organization_name' => $organization?->name ?? '',
                    'post_title' => $titre,
                    'post_url' => $url,
                    'preferences_url' => $urlReglages,
                    'app_name' => config('app.name'),
                ],
                repli: fn () => [
                    'subject' => str_replace(':prenom', $prenom, (string) __('notifications.email.'.self::SLUG.'.subject')),
                    'html' => str_replace(
                        [':url', ':reglages', ':prenom', ':titre'],
                        [$url, $urlReglages, e($prenom), e($titre)],
                        (string) __('notifications.email.'.self::SLUG.'.body'),
                    ),
                ],
            );
        } finally {
            app()->setLocale($langueOrigine);
        }
    }
}
