<?php

namespace App\Services\Blog;

use App\Models\BlogPost;
use App\Models\BlogTodo;
use App\Models\Organization;
use App\Models\User;
use App\Support\Notifications\GabaritEmail;

/**
 * TASK-1884 #7 (demande Cyril, 22/11) — le courriel « une tache vous est
 * confiee sur un article ».
 *
 * Sujet demande : « {tache} sur « {15 premiers caracteres du titre}… » » — les
 * points de suspension n'apparaissent QUE si le titre est coupe. Corps : qui a
 * saisi, a qui, titre complet de l'article, tache, date de creation.
 *
 * Gabarit systeme GLOBAL `blog_todo_assigned` (FR/EN). La copie archivee
 * (`$pourJournal`) expurge le titre de la tache, ecrit par un membre — dans le
 * corps ET dans le sujet.
 */
final class BlogTodoAssignedMailer
{
    public const SLUG = 'blog_todo_assigned';

    public const TACHE_EXPURGEE = '[todo-redacted]';

    public const LONGUEUR_TITRE_SUJET = 15;

    /**
     * @return array{subject: string, body: string, fallback_reason: string|null}
     */
    public function compose(BlogTodo $todo, BlogPost $post, User $destinataire, ?User $acteur, string $url, string $langue, bool $pourJournal = false): array
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

            $tache = $pourJournal ? self::TACHE_EXPURGEE : trim((string) $todo->title);
            $titre = trim((string) $post->title);
            $date = $todo->created_at?->copy()->locale($langue)->translatedFormat($langue === 'fr' ? 'j F Y' : 'F j, Y') ?? '';

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
                    'todo_title' => $tache,
                    'todo_date' => $date,
                    'post_title' => $titre,
                    'post_title_short' => self::titreCourt($titre),
                    'post_url' => $url,
                    'preferences_url' => $urlReglages,
                    'app_name' => config('app.name'),
                ],
                repli: fn () => [
                    'subject' => str_replace([':tache', ':titre_court'], [$tache, self::titreCourt($titre)], (string) __('notifications.email.'.self::SLUG.'.subject')),
                    'html' => str_replace(
                        [':url', ':reglages', ':prenom', ':titre', ':tache', ':date'],
                        [$url, $urlReglages, e($prenom), e($titre), e($tache), e($date)],
                        (string) __('notifications.email.'.self::SLUG.'.body'),
                    ),
                ],
            );
        } finally {
            app()->setLocale($langueOrigine);
        }
    }

    /** Les 15 premiers caracteres du titre, suivis de « … » seulement s'il est coupe. */
    public static function titreCourt(string $titre): string
    {
        return mb_strlen($titre) > self::LONGUEUR_TITRE_SUJET
            ? rtrim(mb_substr($titre, 0, self::LONGUEUR_TITRE_SUJET)).'…'
            : $titre;
    }
}
