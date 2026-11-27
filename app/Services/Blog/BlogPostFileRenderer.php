<?php

namespace App\Services\Blog;

use App\Models\BlogPost;
use App\Models\BlogPostFile;
use Dom\Element;
use Dom\HTMLDocument;

/**
 * TASK-1907 — remplace, A L'AFFICHAGE, chaque marqueur de fichier prive par
 * son lecteur.
 *
 * Le HTML stocke ne porte que l'identifiant du fichier. Le lecteur n'est
 * construit que si le fichier est bien RATTACHE A CET ARTICLE : un marqueur
 * copie depuis un autre article, ou visant un fichier jamais rattache, ne
 * produit rien. L'adresse servie est la route gardee, qui reverifie les
 * droits de lecture a chaque requete.
 *
 * L'entree est deja passee par `BlogHtmlSanitizer` : les marqueurs y sont
 * vides et ne portent que leurs trois attributs.
 */
class BlogPostFileRenderer
{
    public function render(BlogPost $post, string $html): string
    {
        if (! str_contains($html, 'data-bp-file-id')) {
            return $html;
        }

        $document = HTMLDocument::createFromString(
            '<!DOCTYPE html><html><body>'.$html.'</body></html>',
            LIBXML_NOERROR,
            'UTF-8',
        );
        $body = $document->body;
        if ($body === null) {
            return $html;
        }

        $markers = iterator_to_array($body->querySelectorAll('figure[data-bp-file-id]'));
        $ids = array_map(fn (Element $m) => (string) $m->getAttribute('data-bp-file-id'), $markers);

        $files = BlogPostFile::query()
            ->where('blog_post_id', $post->id)
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        foreach ($markers as $marker) {
            $file = $files->get((string) $marker->getAttribute('data-bp-file-id'));

            if ($file === null) {
                $marker->remove();

                continue;
            }

            $this->fill($document, $marker, $post, $file);
        }

        return $body->innerHTML;
    }

    private function fill(HTMLDocument $document, Element $marker, BlogPost $post, BlogPostFile $file): void
    {
        $url = route('organization.blog.files.show', [$post->organization, $file->id]);
        $name = $file->original_name;

        foreach (iterator_to_array($marker->attributes) as $attribute) {
            $marker->removeAttribute($attribute->name);
        }
        $marker->setAttribute('class', 'bp-file bp-file-'.$file->kind);

        $caption = $document->createElement('figcaption');
        $caption->textContent = $name;
        $marker->append($caption);

        if ($file->kind === BlogPostFile::KIND_AUDIO) {
            $audio = $document->createElement('audio');
            $audio->setAttribute('controls', '');
            $audio->setAttribute('preload', 'none');
            $audio->setAttribute('src', $url);
            $marker->append($audio);

            return;
        }

        // PDF : conteneur que la visionneuse PDF.js remplit (etape 5) ; le lien
        // reste la voie de secours, sans JavaScript.
        $marker->setAttribute('data-bp-pdf-src', $url);
        $link = $document->createElement('a');
        $link->setAttribute('href', $url);
        $link->setAttribute('target', '_blank');
        $link->setAttribute('rel', 'noopener');
        $link->textContent = __('Ouvrir le PDF');
        $marker->append($link);
    }
}
