/**
 * TASK-1267 — rendu d'un resultat de recherche semantique selon sa source.
 *
 * Le composant Alpine `dossierSemanticArticleSearch` vit dans
 * resources/js/app.js (monolithe qui importe Alpine, Livewire, TipTap…) :
 * impossible de l'importer sous Node. On extrait donc la factory
 * `(config) => ({ ... })` du source et on l'evalue telle quelle, pour tester
 * le code reel — pas une copie. Si la factory est deplacee ou renommee, le
 * test echoue explicitement au lieu de passer a vide.
 *
 * Run with: node --test tests/js/dossier-semantic-search-result.test.mjs
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(resolve(here, '../../resources/js/app.js'), 'utf8');

function extractFactory() {
    const marker = "Alpine.data('dossierSemanticArticleSearch', ";
    const start = source.indexOf(marker);
    assert.notEqual(start, -1, 'dossierSemanticArticleSearch factory not found in app.js');

    // La factory est `(config) => ({ ... })` ; on avance jusqu'a la parenthese
    // fermante equilibree qui clot l'appel Alpine.data(...).
    let depth = 0;
    let index = start + 'Alpine.data'.length; // sur la '(' de Alpine.data(
    for (; index < source.length; index++) {
        const char = source[index];
        if (char === '(') depth++;
        if (char === ')') {
            depth--;
            if (depth === 0) break;
        }
    }
    const body = source.slice(start + marker.length, index);

    return new Function(`return (${body});`)();
}

const i18n = {
    readArticle: "Lire l’article",
    openDocument: 'Ouvrir le document',
    passage: 'Passage :number',
    resultsCount: ':count passage(s)',
};

const component = () => extractFactory()({ endpoint: '/x', i18n });

const article = {
    source_type: 'article',
    blog_post_id: 'post-1',
    title: 'Indexed article',
    slug: 'indexed-article',
    dossier_file_id: null,
    filename: null,
    chunk_index: 0,
    content: 'Passage',
};

const fileA = {
    source_type: 'file',
    blog_post_id: null,
    title: null,
    slug: null,
    dossier_file_id: 'file-a',
    filename: 'contrat-2026.pdf',
    chunk_index: 0,
    content: 'Passage A',
};

const fileB = { ...fileA, dossier_file_id: 'file-b', filename: 'rapport.docx', content: 'Passage B' };

test('titre : filename cote fichier, title cote article', () => {
    const c = component();
    assert.equal(c.resultTitle(article), 'Indexed article');
    assert.equal(c.resultTitle(fileA), 'contrat-2026.pdf');
    assert.equal(c.resultTitle({ ...fileA, filename: null }), '');
});

test('libelle du lien : « Ouvrir le document » pour un fichier, « Lire l’article » pour un article', () => {
    const c = component();
    assert.equal(c.resultLinkLabel(fileA), 'Ouvrir le document');
    assert.equal(c.resultLinkLabel(article), "Lire l’article");
});

test('cle DOM : deux fichiers distincts au meme chunk_index ne collisionnent pas', () => {
    const c = component();
    assert.notEqual(c.resultKey(fileA), c.resultKey(fileB));
    assert.notEqual(c.resultKey(fileA), c.resultKey(article));
    // l'ancienne cle `${slug}-${chunk_index}` donnait `null-0` pour les deux fichiers
    assert.ok(!c.resultKey(fileA).startsWith('null-'));
    assert.ok(!c.resultKey(fileB).startsWith('null-'));
});

test('cle DOM : stable pour un meme resultat, distincte entre deux chunks du meme fichier', () => {
    const c = component();
    assert.equal(c.resultKey(fileA), c.resultKey({ ...fileA }));
    assert.notEqual(c.resultKey(fileA), c.resultKey({ ...fileA, chunk_index: 1 }));
});

test('apercu : fichier texte/markdown/image/pdf previsualisable, docx non, article jamais', () => {
    const c = component();
    assert.equal(c.canPreviewResult({ ...fileA, mime_type: 'text/markdown' }), true);
    assert.equal(c.canPreviewResult({ ...fileA, mime_type: 'text/plain' }), true);
    assert.equal(c.canPreviewResult({ ...fileA, mime_type: 'image/png' }), true);
    assert.equal(c.canPreviewResult({ ...fileA, mime_type: 'application/pdf' }), true);
    assert.equal(c.canPreviewResult({ ...fileA, mime_type: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' }), false);
    assert.equal(c.canPreviewResult({ ...fileA, mime_type: null }), false);
    assert.equal(c.canPreviewResult({ ...article, mime_type: 'text/markdown' }), false);
});

test('apercu : openResultPreview appelle openPreview du parent avec id / mime_type / display_name', () => {
    const c = component();
    const appels = [];
    c.openPreview = (f) => appels.push(f); // methode de `dossierFilesCard`, atteinte par la portee Alpine
    assert.equal(c.openResultPreview({ ...fileA, mime_type: 'text/markdown' }), true);
    assert.deepEqual(appels, [{ id: 'file-a', mime_type: 'text/markdown', display_name: 'contrat-2026.pdf' }]);
    // non previsualisable : rien n'est ouvert, le lien citation_url reste le chemin
    assert.equal(c.openResultPreview({ ...fileA, mime_type: 'application/zip' }), false);
    assert.equal(appels.length, 1);
});

test('article sans source_type (payload historique) : clé et titre restent ceux d’un article', () => {
    const c = component();
    const legacy = { blog_post_id: 'post-9', title: 'Legacy', slug: 'legacy', chunk_index: 2 };
    assert.equal(c.resultTitle(legacy), 'Legacy');
    assert.equal(c.resultLinkLabel(legacy), "Lire l’article");
    assert.ok(c.resultKey(legacy).startsWith('article:post-9:'));
});
