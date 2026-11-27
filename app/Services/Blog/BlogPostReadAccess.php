<?php

namespace App\Services\Blog;

use App\Models\BlogPost;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;

/**
 * TASK-1907 — qui peut LIRE un article : une seule verite.
 *
 * Ces regles vivaient dans `BlogController::show()`. Les fichiers prives d'un
 * article (PDF, audio) doivent suivre EXACTEMENT les memes droits que la page :
 * une deuxieme copie de ces regles finirait par diverger, et un fichier
 * deviendrait lisible la ou l'article ne l'est pas.
 *
 * Le contexte d'URL (l'article appartient-il a l'Organization de l'URL ?)
 * reste au controleur : il depend de la route, pas du lecteur.
 */
class BlogPostReadAccess
{
    public function allows(BlogPost $post, ?User $user): bool
    {
        return $this->statusAllows($post, $user)
            && $this->organizationBlogIsReadable($post->organization, $user)
            && $this->privateLoopManifestoIsReadable($post, $user);
    }

    /**
     * Un article non publie n'est lu que par son auteur, ses co-auteurs et le
     * SuperAdmin.
     */
    private function statusAllows(BlogPost $post, ?User $user): bool
    {
        if ($post->status === 'published') {
            return true;
        }

        if (! $user) {
            return false;
        }

        return $user->id === $post->user_id
            || $user->is_admin
            || $post->coAuthors()->where('user_id', $user->id)->exists();
    }

    /**
     * TASK-1492 — la confidentialite d'une Organization s'etend a son blog.
     *
     * Publier rend un article lisible par l'ORGANIZATION, pas par le Web : une
     * Organization privee ne montre son blog qu'a ses membres (et au
     * SuperAdmin, meme predicat `is_admin` que `EnsureOrganizationMember`).
     * Une Organization PUBLIQUE garde son contrat : un article publie y reste
     * lisible par un invite (surface d'acquisition).
     *
     * Mesure qui a motive la garde (HEAD 8f540d69, `test20260822` privee) :
     * membre d'une AUTRE Organization et anonyme lisaient l'article (200).
     */
    public function organizationBlogIsReadable(?Organization $organization, ?User $user): bool
    {
        if (! $organization) {
            return false;
        }

        if ($organization->is_public) {
            return true;
        }

        if ($user?->is_admin) {
            return true;
        }

        return $user !== null && $user->organization_id === $organization->id;
    }

    /**
     * TASK-1079 — l'article designe comme Manifeste d'une Boucle PRIVEE herite
     * de la confidentialite de la Boucle : sinon l'URL directe /blog/{slug}
     * la contourne. Seuls les membres actifs de la Boucle et le SuperAdmin le
     * lisent.
     */
    private function privateLoopManifestoIsReadable(BlogPost $post, ?User $user): bool
    {
        $loop = Loop::where('manifesto_blog_post_id', $post->id)
            ->where('visibility', 'private')
            ->first();

        if (! $loop) {
            return true;
        }

        if ($user?->is_admin) {
            return true;
        }

        return $user !== null && LoopMember::where('loop_id', $loop->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->exists();
    }
}
