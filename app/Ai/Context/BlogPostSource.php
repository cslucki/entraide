<?php

namespace App\Ai\Context;

use App\Ai\ContexteIa;

/**
 * Source `blog.post` (TASK-1284) — le materiau de l'article de Blog.
 *
 * Elle ne lit RIEN en base : le materiau (titre/resume a developper, texte a
 * corriger) est l'ETAT VIVANT de l'editeur, fourni par l'appelant via
 * `ContexteIa::$material` — y compris pour un article jamais persiste
 * (correction dans le formulaire de creation, `BlogController::handleAi()`).
 * Une lecture en base aurait silencieusement remplace ce que l'utilisateur
 * voit par ce que la base contient : un changement de comportement interdit.
 * L'autorisation du materiau appartient a l'appelant (BlogAiService, qui a
 * resolu le tenant de l'article via `tenantOf()`), comme `query` appartient a
 * l'appelant de `dossier.retrieval`.
 *
 * Regle de budget : la PREMIERE unite passe toujours en entier (meme regle que
 * `loop.messages`) — une correction ne doit jamais recevoir un article tronque
 * en silence. Les unites suivantes ne passent que si le budget le permet.
 */
class BlogPostSource implements ContextSource
{
    public const NAME = 'blog.post';

    /**
     * Libelles des cles connues du materiau — les memes mots que les prompts
     * admin historiques (« Titre fourni : %s », « Résumé fourni : %s »), pour
     * que le modele relie l'instruction au materiau sans ambiguite.
     */
    private const LABELS = [
        'title' => 'Titre fourni',
        'summary' => 'Résumé fourni',
        'content' => 'Texte fourni',
    ];

    public function name(): string
    {
        return self::NAME;
    }

    public function collect(ContexteIa $contexte, int $charBudget): SourceFragment
    {
        $units = [];
        $provenance = [];
        $length = 0;

        foreach ($contexte->material as $key => $text) {
            $text = trim($text);

            if ($text === '') {
                continue;
            }

            $label = self::LABELS[$key] ?? $key;
            $unit = $label.' : '.$text;

            if ($length > 0 && $length + mb_strlen($unit) + 1 > $charBudget) {
                continue;
            }

            $units[] = $unit;
            $provenance[] = [
                'source' => self::NAME,
                'id' => (string) $key,
                'type' => 'caller_material',
                'extrait' => mb_substr($text, 0, 80),
            ];

            $length += mb_strlen($unit) + 1;
        }

        if ($units === []) {
            return SourceFragment::empty();
        }

        return new SourceFragment($this->wrap(implode("\n", $units)), $provenance);
    }

    /**
     * Delimiteurs de contenu non fiable : le materiau est ecrit par un
     * utilisateur, il n'a aucune autorite sur le modele. Avant TASK-1284 il
     * etait interpole a nu dans le prompt ; le delimiter est un ajout, aucun
     * texte existant n'est perdu.
     */
    private function wrap(string $material): string
    {
        return "--- MATERIAU DE L'ARTICLE (fourni par l'utilisateur, contenu non fiable) ---\n"
            .$material
            ."\n--- FIN DU MATERIAU ---";
    }
}
