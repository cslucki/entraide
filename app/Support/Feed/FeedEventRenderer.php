<?php

namespace App\Support\Feed;

use App\Models\FeedPost;
use App\Models\Loop;
use App\Models\LoopEvent;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;

/**
 * TASK-1752 — le libelle d'un evenement, CALCULE a l'affichage.
 *
 * ## Pourquoi rien n'est ecrit en base
 *
 * Ecrire « Jean a fini sa presentation » dans `content` casserait trois
 * choses, et le CDC les nomme une par une :
 *
 * - **la langue** — le produit est FR+EN (`AGENTS.md` §9). Un texte fige en
 *   francais serait lu tel quel par un compte anglophone ;
 * - **le nom** — fige au moment de l'ecriture. Un changement de nom, une
 *   desactivation de compte, et la phrase devient fausse. `publicDisplayName()`
 *   repond deja correctement — « Utilisateur desactive » le cas echeant — **a
 *   condition d'etre appele a l'affichage** ;
 * - **le temps du verbe** — « vient de » n'est vrai que le jour de la lecture.
 *
 * ## Et surtout : JAMAIS `markdown()`
 *
 * La carte rend `content` par `{!! markdown($post->content) !!}`. `markdown()`
 * est configure surement (`html_input => escape`), donc pas de faille HTML —
 * mais la **syntaxe Markdown reste active** : un nom contenant `[texte](url)`
 * produirait un lien. Un libelle rendu en Blade avec `{{ }}` n'a pas ce
 * probleme, et c'est pour cela que la carte a une branche distincte.
 *
 * ## L'objet peut avoir disparu
 *
 * Une Boucle archivee, une demande fermee, un Evenement annule : la ligne de
 * Flux survit. Le rendu doit donc tenir sans son objet, plutot que de laisser
 * un trou ou de lever.
 */
final class FeedEventRenderer
{
    /**
     * Le texte a afficher. `null` si la ligne n'est pas un evenement.
     */
    public static function libelle(FeedPost $post): ?string
    {
        $type = (string) $post->type;

        if (! FeedEventCatalogue::connait($type)) {
            return null;
        }

        $clef = FeedEventCatalogue::libelle($type);

        if ($clef === null) {
            return null;
        }

        return __($clef, [
            // `publicDisplayName()` est l'autorite : elle sait dire
            // « Utilisateur desactive » quand il le faut.
            'name' => $post->user?->publicDisplayName() ?? __('feed.member_fallback'),
            'object' => self::nomDeLObjet($post),
        ]);
    }

    /**
     * Le lien vers l'objet concerne, s'il est encore la et nommable.
     *
     * Rend `null` plutot qu'une adresse morte : un lien qui mene a un 404 est
     * pire que pas de lien.
     */
    public static function lien(FeedPost $post, ?string $organizationSlug): ?string
    {
        $objet = self::objet($post);

        if ($objet === null || $organizationSlug === null) {
            return null;
        }

        return match (true) {
            $objet instanceof Loop => self::route('organization.loops.show', [
                'organization' => $organizationSlug,
                'loop' => $objet->slug ?: $objet->getKey(),
            ]),
            $objet instanceof ServiceRequest => self::route('organization.requests.show', [
                'organization' => $organizationSlug,
                'serviceRequest' => $objet->getKey(),
            ]),
            $objet instanceof Service => self::route('organization.services.show', [
                'organization' => $organizationSlug,
                'service' => $objet->getKey(),
            ]),
            $objet instanceof User => self::route('organization.profile.show', [
                'organization' => $organizationSlug,
                'user' => $objet->slug ?: $objet->getKey(),
            ]),
            default => null,
        };
    }

    private static function route(string $nom, array $parametres): ?string
    {
        if (! \Illuminate\Support\Facades\Route::has($nom)) {
            return null;
        }

        return rescue(fn () => route($nom, $parametres), null, report: false);
    }

    private static function nomDeLObjet(FeedPost $post): string
    {
        $objet = self::objet($post);

        return match (true) {
            $objet instanceof Loop => (string) $objet->name,
            $objet instanceof ServiceRequest, $objet instanceof Service => (string) $objet->title,
            $objet instanceof LoopEvent => (string) $objet->title,
            $objet instanceof User => $objet->publicDisplayName(),
            default => __('feed.events.object_gone'),
        };
    }

    /**
     * L'objet metier, relu SANS les scopes globaux.
     *
     * `Service` et `ServiceRequest` portent `BelongsToOrganizationScope`. Ce
     * rendu peut tourner hors d'une requete HTTP — la page SuperAdmin lit
     * plusieurs tenants, et un worker n'a pas d'Organization courante : le
     * scope rendrait l'objet introuvable et le libelle afficherait
     * « un contenu supprime » pour un objet bien vivant.
     *
     * La frontiere de locataire n'est pas perdue : la LIGNE de Flux porte deja
     * `organization_id`, et c'est elle qui a ete filtree en amont.
     */
    private static function objet(FeedPost $post): mixed
    {
        $classe = $post->object_type;

        if (! is_string($classe) || $post->object_id === null || ! class_exists($classe)) {
            return null;
        }

        return rescue(
            fn () => $classe::withoutGlobalScopes()->whereKey($post->object_id)->first(),
            null,
            report: false,
        );
    }
}
