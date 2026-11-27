<?php

namespace App\Services\Blog;

use App\Models\BlogPost;
use App\Models\BlogPostFile;

/**
 * TASK-1907 — rattache a un article les fichiers prives que son contenu cite.
 *
 * Un fichier depose dans l'editeur n'appartient d'abord qu'a son deposant
 * (`blog_post_id` NULL). Quand le contenu est enregistre, chaque fichier cite
 * est rattache a l'article, ce qui lui donne les droits de lecture de
 * l'article.
 *
 * Trois verrous :
 *  - meme Organization que l'article ;
 *  - fichier encore LIBRE : un fichier deja rattache a un autre article ne
 *    change jamais de proprietaire (copier-coller d'un marqueur = rien) ;
 *  - deposant = auteur ou co-auteur de l'article : citer l'identifiant d'un
 *    fichier depose par quelqu'un d'autre ne suffit pas a se l'approprier.
 *
 * Un fichier qui n'est plus cite reste rattache : une version anterieure de
 * l'article (snapshot) peut le citer a nouveau.
 */
class BlogPostFileBinder
{
    public const MARKER_ATTRIBUTE = 'data-bp-file-id';

    private const UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    /** @return list<string> */
    public static function citedIds(?string $html): array
    {
        if ($html === null || $html === '') {
            return [];
        }

        preg_match_all('/'.self::MARKER_ATTRIBUTE.'="('.self::UUID.')"/i', $html, $matches);

        return array_values(array_unique(array_map('strtolower', $matches[1])));
    }

    public function bind(BlogPost $post): int
    {
        $ids = self::citedIds($post->content);

        if ($ids === []) {
            return 0;
        }

        $uploaders = $post->coAuthors()->pluck('users.id')->push($post->user_id)->filter()->all();

        return BlogPostFile::query()
            ->whereIn('id', $ids)
            ->where('organization_id', $post->organization_id)
            ->whereNull('blog_post_id')
            ->whereIn('user_id', $uploaders)
            ->update(['blog_post_id' => $post->id]);
    }
}
