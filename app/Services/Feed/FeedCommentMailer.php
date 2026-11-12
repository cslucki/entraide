<?php

namespace App\Services\Feed;

use App\Models\FeedPost;
use App\Models\FeedPostComment;
use App\Models\Organization;
use App\Models\User;
use App\Support\Feed\FeedEventRenderer;
use App\Support\Notifications\GabaritEmail;
use Illuminate\Support\Str;

/**
 * TASK-1810 (demande Cyril, 12/11) — le courriel « Commentaire sur le flux ».
 *
 * Ecrit sur le patron de `BlogCommentMailer` (T1784) : UN type d'item
 * commentable (`FeedPost`, pas deux comme `EntraideCommentMailer`), le meme
 * decoupage texte-en-base / repli / extrait expurge.
 *
 * ## Le contexte repris « ce qui est affiche dans le flux » (demande explicite)
 *
 * « On donne le contexte de l'item du flux. Et pour cela on reprend ce qui
 * est affiche dans le flux. » — `contexteDuFlux()` ci-dessous reutilise
 * EXACTEMENT `FeedEventRenderer::libelle()` pour un evenement automatique
 * (le MEME texte, plain-text, que la ligne compacte de la carte), et le
 * titre/extrait de l'annonce pour une publication manuelle. JAMAIS
 * `markdown($post->content)` : la regle ecrite dans le docblock de
 * `FeedEventRenderer` s'applique aussi ici — la syntaxe Markdown resterait
 * active dans un texte qu'on va de toute facon repasser par `e()`.
 *
 * ## `author_first_name`, pas `author_name`
 *
 * Demande explicite : « $prenom_commentateur vient de commenter ». Le
 * PRENOM du commentateur peut etre NULL (TASK-1810, correctif 1, le MEME
 * defaut sur le MEME genre de compte de demonstration) : meme repli a trois
 * etages que `ReviewReceivedMailer`/`EvaluationInviteMailer` — prenom, puis
 * nom complet, jamais une variable nulle qui laisserait un jeton brut dans
 * l'email reel.
 *
 * ## Aucun retrait de portee d'Organization
 *
 * `FeedPost`, `FeedPostComment` et `Organization` ne portent AUCUN
 * `BelongsToOrganizationScope` (confirme par grep avant d'ecrire ce fichier) :
 * il n'y a donc rien a retirer, a la difference de `BlogCommentMailer`. La
 * frontiere de tenant est quand meme posee a la main (`where('organization_id', …)`),
 * par discipline, pas par necessite de contourner un scope.
 */
final class FeedCommentMailer
{
    public const SLUG = 'feed_post_comment_created';

    /** Meme borne que les mailers de commentaire voisins. */
    public const EXTRAIT_MAX = 400;

    public const EXTRAIT_EXPURGE = '[comment-redacted]';

    /**
     * @param  bool  $pourJournal  rend la variante destinee a `email_logs` :
     *                             l'extrait est expurge, et le marqueur fourni
     *                             en `$url` sert AUSSI de lien de reglages.
     * @return array{subject: string, body: string, fallback_reason: string|null}
     */
    public function compose(
        FeedPostComment $commentaire,
        User $destinataire,
        ?User $acteur,
        string $url,
        string $langue,
        bool $pourJournal = false,
    ): array {
        $langueOrigine = app()->getLocale();
        app()->setLocale($langue);

        try {
            $post = FeedPost::query()
                ->whereKey($commentaire->feed_post_id)
                ->where('organization_id', $commentaire->organization_id)
                ->first();

            $organization = Organization::query()
                ->whereKey($commentaire->organization_id)
                ->first();

            $contexte = $post !== null ? self::contexteDuFlux($post) : '';

            // Meme defaut que `ReviewReceivedMailer`/`EvaluationInviteMailer`
            // (TASK-1810, correctif 1) : un commentateur actif mais sans
            // `first_name` renseigne laisserait sinon le jeton brut
            // `{{ author_first_name }}` dans l'email reel.
            $auteurPrenom = $acteur === null
                ? __('profile.deactivated_user')
                : ($acteur->isDeactivated()
                    ? __('profile.deactivated_user')
                    : ($acteur->first_name ?: ($acteur->name ?: __('profile.deactivated_user'))));

            // Le corps n'est PAS echappe ici : `EmailerService::interpolate()`
            // passe chaque variable par `e()`. Les retours a la ligne sont
            // rendus par `white-space: pre-line` dans le gabarit.
            $corps = trim((string) $commentaire->content);
            $extrait = $corps === ''
                ? (string) __('notifications.feed_post_comment_email_excerpt_empty')
                : Str::limit($corps, self::EXTRAIT_MAX);

            if ($pourJournal) {
                $extrait = self::EXTRAIT_EXPURGE;
            }

            $urlReglages = $pourJournal
                ? $url
                : ($organization !== null
                    ? route('organization.notifications.preferences.edit', ['organization' => $organization])
                    : route('notifications.preferences.edit'));

            return app(GabaritEmail::class)->rendre(
                slug: self::SLUG,
                organizationId: (string) $commentaire->organization_id,
                langue: $langue,
                sender: $acteur,
                vars: [
                    'recipient_name' => $destinataire->publicDisplayName(),
                    'recipient_email' => $destinataire->email,
                    'author_first_name' => $auteurPrenom,
                    'organization_name' => $organization?->name ?? '',
                    'feed_context' => $contexte,
                    'comment_url' => $url,
                    'comment_excerpt' => $extrait,
                    'preferences_url' => $urlReglages,
                    'app_name' => config('app.name'),
                ],
                // Repli minimal, meme idiome que les mailers voisins :
                // l'extrait passe EN DERNIER puisqu'il peut contenir
                // litteralement « :url » ou « :contexte ».
                repli: fn () => [
                    'subject' => __('notifications.email.'.self::SLUG.'.subject'),
                    'html' => str_replace(
                        ':extrait',
                        e($extrait),
                        str_replace(
                            [':url', ':reglages', ':contexte', ':prenom'],
                            [$url, $urlReglages, e($contexte), e($auteurPrenom)],
                            (string) __('notifications.email.'.self::SLUG.'.body'),
                        ),
                    ),
                ],
            );
        } finally {
            app()->setLocale($langueOrigine);
        }
    }

    /**
     * « On reprend ce qui est affiche dans le flux » (demande explicite).
     *
     * Un evenement automatique reutilise `FeedEventRenderer::libelle()` —
     * texte PLAT, localise, jamais passe par `markdown()`. Une annonce
     * manuelle n'a pas de libelle d'evenement : son titre fait le contexte,
     * et a defaut de titre, un extrait neutre de son contenu — JAMAIS
     * `markdown($post->content)`, pour la raison ecrite dans le docblock de
     * `FeedEventRenderer` (la syntaxe Markdown resterait active).
     */
    private static function contexteDuFlux(FeedPost $post): string
    {
        $libelle = FeedEventRenderer::libelle($post);

        if ($libelle !== null) {
            return $libelle;
        }

        if (filled($post->title)) {
            return (string) $post->title;
        }

        return Str::limit(trim((string) $post->content), 200);
    }
}
