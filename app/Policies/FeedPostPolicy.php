<?php

namespace App\Policies;

use App\Models\FeedPost;
use App\Models\User;

class FeedPostPolicy
{
    public function create(User $user): bool
    {
        if ($user->banned_at) {
            return false;
        }

        $org = app()->bound('current_organization') ? app('current_organization') : null;

        if ($org === null) {
            return false;
        }

        if ($user->organization_id !== $org->id) {
            return false;
        }

        if ($org->feed_post_publish_mode === 'members') {
            return true;
        }

        return $org->admin_id === $user->id || $user->is_admin;
    }

    public function viewAny(User $user): bool
    {
        if ($user->banned_at) {
            return false;
        }

        $org = app()->bound('current_organization') ? app('current_organization') : null;

        if ($org === null) {
            return false;
        }

        return $user->organization_id === $org->id;
    }

    public function view(User $user, FeedPost $feedPost): bool
    {
        if ($user->banned_at) {
            return false;
        }

        $org = app()->bound('current_organization') ? app('current_organization') : null;

        if ($org === null) {
            return false;
        }

        return $user->organization_id === $org->id
            && $feedPost->organization_id === $org->id;
    }

    public function react(User $user, FeedPost $feedPost): bool
    {
        return $this->view($user, $feedPost);
    }

    public function comment(User $user, FeedPost $feedPost): bool
    {
        return $this->view($user, $feedPost);
    }

    /**
     * TASK-1752 — `$feedPost` devient un ARGUMENT, et c'est le correctif.
     *
     * ## L'ecriture cross-tenant que cette signature ferme
     *
     * Cette methode ne recevait que l'acteur. Elle ne verifiait donc que
     * l'appartenance de CELUI QUI DEMANDE, jamais celle de l'objet vise. Trois
     * faits se combinaient :
     *
     *  1. `FeedPost` n'a PAS de scope global d'Organization — contrairement a
     *     `Service` et `ServiceRequest`. `HasOrganizationId` ne fait que
     *     REMPLIR la colonne a la creation ;
     *  2. `MyFeedPosts::togglePin()` resolvait l'annonce par `findOrFail()`,
     *     sans portee ;
     *  3. le `saving` de `FeedPost` DEPINGLE EN MASSE les autres annonces de
     *     l'Organization de la cible des qu'un `pinned_at` devient non-nul.
     *
     * Resultat mesure : l'administrateur de l'Organization A epinglait une
     * annonce de B, `pinned_by_id` enregistrait quelqu'un qui n'est pas membre
     * du tenant modifie, et l'epinglage precedent de B disparaissait en
     * silence. Sur l'invariant n°1 d'`AGENTS.md`.
     *
     * ## Pourquoi `?FeedPost` et non `FeedPost`
     *
     * `CreateFeedPost` demande la permission AVANT que l'annonce existe
     * (`can('pin', FeedPost::class)`), et la vue `my-feed-posts` fait de meme
     * pour decider d'afficher le bouton. Ces deux appels n'ont pas d'objet a
     * donner, et leur question est legitime : « cette personne a-t-elle le
     * droit d'epingler, en general ? ».
     *
     * L'argument est donc FACULTATIF — mais des qu'il est fourni, la frontiere
     * de locataire est verifiee. C'est la forme qui laisse les trois appelants
     * poser leur vraie question.
     */
    public function pin(User $user, ?FeedPost $feedPost = null): bool
    {
        if ($user->banned_at) {
            return false;
        }

        $org = app()->bound('current_organization') ? app('current_organization') : null;

        if ($org === null) {
            return false;
        }

        if ($user->organization_id !== $org->id) {
            return false;
        }

        // LA ligne qui manquait. Un objet nomme doit appartenir au locataire
        // courant, quel que soit le rang de celui qui demande.
        if ($feedPost !== null && $feedPost->organization_id !== $org->id) {
            return false;
        }

        return $user->is_admin || $user->id === $org->admin_id;
    }

    public function update(User $user, FeedPost $feedPost): bool
    {
        if ($user->banned_at) {
            return false;
        }

        $org = app()->bound('current_organization') ? app('current_organization') : null;

        if ($org === null) {
            return false;
        }

        return $this->resourceBelongsToCurrentOrganization($feedPost)
            && ($user->id === $feedPost->user_id || $user->is_admin || $user->id === $org->admin_id);
    }

    public function delete(User $user, FeedPost $feedPost): bool
    {
        if ($user->banned_at) {
            return false;
        }

        $org = app()->bound('current_organization') ? app('current_organization') : null;

        if ($org === null) {
            return false;
        }

        return $this->resourceBelongsToCurrentOrganization($feedPost)
            && ($user->id === $feedPost->user_id || $user->is_admin || $user->id === $org->admin_id);
    }

    private function resourceBelongsToCurrentOrganization($resource): bool
    {
        $org = app()->bound('current_organization') ? app('current_organization') : null;

        if ($org === null) {
            return false;
        }

        return $resource->organization_id === $org->id;
    }
}
