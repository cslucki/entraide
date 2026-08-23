<?php

namespace App\Policies;

use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\Loop;
use App\Models\User;
use App\Support\Loops\LoopPermissionResolver;

class BlogPostPolicy
{
    public function create(User $user): bool
    {
        $org = app()->bound('current_organization') ? app('current_organization') : null;

        if ($org === null) {
            return false;
        }

        return ! $user->banned_at;
    }

    public function update(User $user, BlogPost $post): bool
    {
        if ($user->is_admin) {
            return true;
        }

        if (! $this->resourceBelongsToCurrentOrganization($post)) {
            return false;
        }

        if ($user->id === $post->user_id) {
            return true;
        }

        if ($post->coAuthors()->where('user_id', $user->id)->exists()) {
            return true;
        }

        return $this->canUpdateAsLoopRootDocument($user, $post);
    }

    /**
     * Le document racine d'une Boucle (Manifeste, Cadre du dialogue,
     * Programme...) s'edite selon la grille centrale, pas selon l'auteur du
     * post : `manifesto.update` est deja accorde a l'owner et au facilitator
     * dans /admin/loop-permissions, mais l'editeur Blog l'ignorait et repondait
     * 403 a un owner que la Card invitait pourtant a modifier (TASK-1279,
     * symptome A). Aucun elargissement : `LoopPermissionResolver` reste la
     * seule autorite — un simple membre reste refuse, et la branche ne s'ouvre
     * qu'en dessous du garde tenant (`resourceBelongsToCurrentOrganization`).
     *
     * La racine se lit comme partout ailleurs : le Dossier racine
     * (`root_blog_post_id`), puis le champ legacy `manifesto_blog_post_id`
     * qu'un backfill n'a pas encore resorbe — les deux sont maintenus en phase
     * par LoopRootDocumentService.
     */
    private function canUpdateAsLoopRootDocument(User $user, BlogPost $post): bool
    {
        $loopIds = Dossier::where('root_blog_post_id', $post->id)->pluck('loop_id')
            ->merge(Loop::where('manifesto_blog_post_id', $post->id)->pluck('id'))
            ->filter()
            ->unique();

        if ($loopIds->isEmpty()) {
            return false;
        }

        $resolver = app(LoopPermissionResolver::class);

        return Loop::whereIn('id', $loopIds)->get()
            ->contains(fn (Loop $loop) => $resolver->can($user, $loop, 'manifesto.update'));
    }

    public function delete(User $user, BlogPost $post): bool
    {
        if ($user->is_admin) {
            return true;
        }

        return $this->resourceBelongsToCurrentOrganization($post)
            && $user->id === $post->user_id;
    }

    public function manageCoAuthors(User $user, BlogPost $post): bool
    {
        if ($user->is_admin) {
            return true;
        }

        return $this->resourceBelongsToCurrentOrganization($post)
            && $user->id === $post->user_id;
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
