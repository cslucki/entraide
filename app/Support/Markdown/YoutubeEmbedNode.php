<?php

namespace App\Support\Markdown;

use League\CommonMark\Node\Block\AbstractBlock;

/**
 * TASK-1788 correctif 12 (demande Cyril) — un paragraphe du document, APRES
 * coup, pour une video YouTube inseree depuis la barre d'outils de la bio.
 *
 * Ne porte que l'identifiant video (11 caracteres, deja valide par le
 * regex de `YoutubeEmbedExtension` au moment de la construction) : jamais
 * une URL complete, pour que `YoutubeEmbedRenderer` construise lui-meme la
 * source de l'iframe plutot que de rejouer une valeur qui a transite par le
 * navigateur.
 */
final class YoutubeEmbedNode extends AbstractBlock
{
    public function __construct(private readonly string $videoId)
    {
        parent::__construct();
    }

    public function getVideoId(): string
    {
        return $this->videoId;
    }
}
