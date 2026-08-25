<?php

namespace App\Ai\Context;

/**
 * Contexte metier FINAL d'un appel IA, deja borne (TASK-1209 / IA P3).
 *
 * Ce que le Context Builder rend a la capability : un texte pret a etre envoye,
 * et de quoi expliquer plus tard « pourquoi BouclePro me propose cela ? ».
 *
 * `sourcesDenied` merite son existence : une source refusee doit se voir. La
 * taire ferait passer un contexte amputé pour un contexte complet, et personne
 * ne saurait que l'IA a repondu sans une information qu'elle aurait du avoir.
 * En revanche la raison reste un identifiant technique borne — jamais un
 * extrait de la ressource refusee, qui trahirait son contenu, ni un message
 * revelant qu'elle existe dans une autre Organization.
 */
final class ContexteBorne
{
    /**
     * @param  list<array{source: string, id: string, type: string, extrait: string}>  $provenance
     * @param  list<string>  $sourcesUsed
     * @param  array<string, string>  $sourcesDenied  source => raison technique
     */
    public function __construct(
        public readonly string $text,
        public readonly array $provenance,
        public readonly int $charBudget,
        public readonly array $sourcesUsed,
        public readonly array $sourcesDenied,
    ) {}

    public function isEmpty(): bool
    {
        return trim($this->text) === '';
    }

    /**
     * @return list<array{source: string, id: string, type: string, extrait: string}>
     */
    public function provenanceFor(string $source): array
    {
        return array_values(array_filter(
            $this->provenance,
            static fn (array $entry): bool => $entry['source'] === $source,
        ));
    }
}
