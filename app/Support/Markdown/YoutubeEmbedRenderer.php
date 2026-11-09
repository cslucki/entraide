<?php

namespace App\Support\Markdown;

use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\HtmlElement;

/**
 * Rend un `YoutubeEmbedNode` exactement comme le Flux/Blog rend sa propre
 * video integree (`resources/js/tiptap/media-embed-node.js`) : meme
 * attribut `data-media-embed`, meme ratio, memes permissions d'iframe. Un
 * seul rendu visuel pour « une video integree », que l'ecran soit la bio ou
 * un article — pas une seconde convention a maintenir en parallele.
 *
 * `youtube-nocookie.com` : le domaine respectueux de la vie privee, deja
 * dans la liste d'autorisation du cote navigateur (`MEDIA_DOMAINS`).
 */
final class YoutubeEmbedRenderer implements NodeRendererInterface
{
    public function render(Node $node, ChildNodeRendererInterface $childRenderer): HtmlElement
    {
        YoutubeEmbedNode::assertInstanceOf($node);

        $src = 'https://www.youtube-nocookie.com/embed/'.$node->getVideoId();

        $iframe = new HtmlElement('iframe', [
            'src' => $src,
            'width' => '100%',
            'height' => '100%',
            'frameborder' => '0',
            'allowfullscreen' => 'true',
            'allow' => 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture',
            'title' => 'Vidéo YouTube intégrée',
            'style' => 'width: 100%; height: 100%',
        ]);

        return new HtmlElement('div', [
            'data-media-embed' => '',
            'style' => 'aspect-ratio: 16 / 9',
        ], $iframe);
    }
}
