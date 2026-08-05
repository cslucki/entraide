/**
 * Detection of a pasted Markdown document.
 *
 * The rule under test is narrow on purpose: a document must be recognised, and
 * ordinary prose containing a symbol must not. Turning a sentence with one bold
 * word into a set of headings would be worse than the bug being fixed.
 *
 * Run with: node --test tests/js/markdown-paste.test.mjs
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { looksLikeMarkdownDocument } from '../../resources/js/tiptap/markdown-paste.js';

const YES = {
    'titre niveau 1': '# Titre principal\n\nUn paragraphe.',
    'titre niveau 2': '## Sous-titre\n\nUn paragraphe assez long pour compter.',
    'liste à puces': '- premier\n- deuxième\n\nUn paragraphe qui suit.',
    'liste numérotée': '1. premier\n2. deuxième\n\nEt la suite du texte.',
    'citation': '> Une citation\n\n- et une liste',
    'lien': 'Voir [la documentation](https://example.com) et **noter** ceci.',
    'bloc clôturé': '# Titre\n\n```php\necho 1;\n```',
    'document complet': `# Titre principal

Introduction en **gras** avec un [lien](https://example.com).

## Première partie

- élément 1
- élément 2

> Une citation

\`\`\`php
echo 'test';
\`\`\``,
};

const NO = {
    'texte brut': 'Bonjour, ceci est un paragraphe tout à fait ordinaire sans aucune syntaxe.',
    'une seule emphase': "Il a dit que c'était **important**, rien de plus.",
    'trop court': '# a',
    'chaîne vide': '',
    'extrait de code': `function total(items) {
    return items.reduce((acc, item) => {
        return acc + item.price * item.qty;
    }, 0);
}`,
    'non-chaîne': null,
};

for (const [name, input] of Object.entries(YES)) {
    test(`reconnu comme Markdown : ${name}`, () => {
        assert.equal(looksLikeMarkdownDocument(input), true);
    });
}

for (const [name, input] of Object.entries(NO)) {
    test(`laissé tel quel : ${name}`, () => {
        assert.equal(looksLikeMarkdownDocument(input), false);
    });
}
