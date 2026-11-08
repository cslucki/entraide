<?php

namespace App\Support\Markdown;

use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\ExtensionInterface;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Inline\Text;

/**
 * TASK-1788 correctif 12 (demande Cyril) — « insérer des vidéos YouTube »
 * dans la barre d'outils de la bio.
 *
 * ## Pourquoi une extension, et pas un noeud TipTap avec son propre
 * serialiseur Markdown
 *
 * L'editeur de la bio stocke son contenu en MARKDOWN (`contentType:
 * 'markdown'`, `editor.getMarkdown()` — voir
 * `resources/js/markdown-wysiwyg-editor.js`). Un nouveau noeud TipTap
 * (`mediaEmbed`, deja utilise par le Flux/Blog) n'a aucune regle de
 * serialisation Markdown enregistree : `@tiptap/markdown` ne sait ni
 * l'ecrire ni le relire sans qu'on lui fournisse `renderMarkdown` ET
 * `parseMarkdown`.
 *
 * Le bouton insere donc un lien ORDINAIRE — texte = href — que la marque
 * `link` deja configuree sait serialiser et reparser sans rien ajouter cote
 * navigateur. Cette extension reconnait le MEME motif cote serveur, au
 * moment du rendu HTML : un paragraphe dont le SEUL contenu est un lien nu
 * vers YouTube devient un lecteur integre plutot qu'un lien bleu.
 *
 * ## Securite — aucune requete serveur, aucune URL utilisateur dans le HTML
 *
 * `html_input => 'escape'` (voir `markdown()`) interdit toute HTML brute
 * dans la source : sans effet ici, cette extension ne lit jamais de HTML,
 * elle construit la sienne. La seule donnee qui survit au filtrage est
 * l'identifiant video, verrouille par un regex strict
 * (`^[A-Za-z0-9_-]{11}$`, l'alphabet exact des identifiants YouTube) AVANT
 * de construire le `YoutubeEmbedNode` — jamais l'URL fournie telle quelle.
 * `YoutubeEmbedRenderer` reconstruit la source de l'iframe lui-meme a partir
 * de ce seul identifiant.
 */
final class YoutubeEmbedExtension implements ExtensionInterface
{
    private const YOUTUBE_HOSTS = [
        'youtube.com', 'www.youtube.com',
        'youtube-nocookie.com', 'www.youtube-nocookie.com',
        'youtu.be', 'www.youtu.be',
    ];

    public function register(EnvironmentBuilderInterface $environment): void
    {
        $environment->addRenderer(YoutubeEmbedNode::class, new YoutubeEmbedRenderer());
        $environment->addEventListener(DocumentParsedEvent::class, $this->embedBareYoutubeLinks(...));
    }

    private function embedBareYoutubeLinks(DocumentParsedEvent $event): void
    {
        $walker = $event->getDocument()->walker();

        while ($walked = $walker->next()) {
            if (! $walked->isEntering()) {
                continue;
            }

            $node = $walked->getNode();
            if (! $node instanceof Paragraph) {
                continue;
            }

            $videoId = $this->bareYoutubeVideoId($node);
            if ($videoId !== null) {
                $node->replaceWith(new YoutubeEmbedNode($videoId));
            }
        }
    }

    /**
     * Un paragraphe « nu » : un unique lien dont le texte affiche est
     * EXACTEMENT son href — jamais une phrase avec un lien dedans. Une
     * phrase qui mentionne une URL YouTube reste un lien bleu ordinaire ;
     * seule l'URL deposee seule sur sa ligne par le bouton devient un
     * lecteur.
     */
    private function bareYoutubeVideoId(Paragraph $paragraph): ?string
    {
        $children = [...$paragraph->children()];
        if (count($children) !== 1 || ! $children[0] instanceof Link) {
            return null;
        }

        /** @var Link $link */
        $link = $children[0];
        $linkChildren = [...$link->children()];
        if (count($linkChildren) !== 1 || ! $linkChildren[0] instanceof Text) {
            return null;
        }

        if ($linkChildren[0]->getLiteral() !== $link->getUrl()) {
            return null;
        }

        return self::extractVideoId($link->getUrl());
    }

    public static function extractVideoId(string $url): ?string
    {
        $parsed = parse_url($url);
        $host = isset($parsed['host']) ? strtolower($parsed['host']) : null;

        if ($host === null || ! in_array($host, self::YOUTUBE_HOSTS, true)) {
            return null;
        }

        $path = $parsed['path'] ?? '';

        if (str_starts_with($host, 'youtu.be')) {
            $id = trim($path, '/');
        } elseif (str_starts_with($path, '/embed/')) {
            $id = substr($path, strlen('/embed/'));
        } else {
            parse_str($parsed['query'] ?? '', $query);
            $id = $query['v'] ?? null;
        }

        if (! is_string($id) || ! preg_match('/^[A-Za-z0-9_-]{11}$/', $id)) {
            return null;
        }

        return $id;
    }
}
