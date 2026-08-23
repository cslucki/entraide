/**
 * TASK-1267 — rendu d'un resultat de recherche semantique selon sa source.
 * TASK-1271 — groupement par DOCUMENT de la liste affichee (section en bas).
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
    otherPassagesOne: '+ 1 autre passage',
    otherPassagesMany: '+ :count autres passages',
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

// ---------------------------------------------------------------------------
// TASK-1271 — une ligne par DOCUMENT, representee par son meilleur passage.
// Les deux fixtures reproduisent les mesures reelles de la passe RAG-QUALITY
// du 2026-08-22 (Organization test20260822) : memes fichiers, memes
// chunk_index, memes distances. Le contrat JSON (`results` = passages) ne
// change pas ; seul `groupedResults()` est nouveau.
// ---------------------------------------------------------------------------

const fileChunk = (dossierFileId, filename, chunkIndex, distance, extra = {}) => ({
    source_type: 'file',
    blog_post_id: null,
    title: null,
    slug: null,
    dossier_file_id: dossierFileId,
    filename,
    mime_type: 'text/markdown',
    citation_url: `/org/test20260822/dossiers/d/files/${dossierFileId}`,
    chunk_index: chunkIndex,
    content: `${filename} passage ${chunkIndex}`,
    distance,
    ...extra,
});

// Q11 — Boucle 01-COMMUNICATION, « MODELE WORDPRESS MULTISITE POUR MUTUALISER
// LES COUTS D INFRASTRUCTURE » : 5 passages sur 5 du SEUL fichier
// ARCHITECTURE_MULTICOMMUNAUTES.md (chunks 0, 1, 8, 7, 6 ; 0.4751 -> 0.5353).
const q11 = [
    fileChunk('arch', 'ARCHITECTURE_MULTICOMMUNAUTES.md', 0, 0.4751),
    fileChunk('arch', 'ARCHITECTURE_MULTICOMMUNAUTES.md', 1, 0.5112),
    fileChunk('arch', 'ARCHITECTURE_MULTICOMMUNAUTES.md', 8, 0.5138),
    fileChunk('arch', 'ARCHITECTURE_MULTICOMMUNAUTES.md', 7, 0.529),
    fileChunk('arch', 'ARCHITECTURE_MULTICOMMUNAUTES.md', 6, 0.5353),
];

// Q15 — Boucle 03-Post LinkedIN, « Roger Malina et Leonardo » : 4 passages de
// Brouillon Numéro 04.md (chunks 0, 2, 1, 3) + 1 de 00-Sommaire newsletter.md.
const q15 = [
    fileChunk('brouillon', 'Brouillon Numéro 04.md', 0, 0.7142),
    fileChunk('brouillon', 'Brouillon Numéro 04.md', 2, 0.7625),
    fileChunk('brouillon', 'Brouillon Numéro 04.md', 1, 0.784),
    fileChunk('brouillon', 'Brouillon Numéro 04.md', 3, 0.8016),
    fileChunk('sommaire', '00-Sommaire newsletter.md', 0, 0.8045),
];

const withResults = (results) => {
    const c = component();
    c.results = results;
    return c;
};

test('T1271 (a) Q11 : 5 passages du meme fichier -> 1 document, ARCHITECTURE_MULTICOMMUNAUTES.md, meilleur passage #0', () => {
    const c = withResults(q11);
    const grouped = c.groupedResults();
    assert.equal(grouped.length, 1);
    assert.equal(grouped[0].filename, 'ARCHITECTURE_MULTICOMMUNAUTES.md');
    assert.equal(grouped[0].chunk_index, 0);
    assert.equal(grouped[0].distance, 0.4751);
    assert.equal(c.otherPassagesCount(grouped[0]), 4);
    assert.equal(c.otherPassagesLabel(grouped[0]), '+ 4 autres passages');
    // le contrat `results` (passages) n'est pas touche par le groupement
    assert.equal(c.results.length, 5);
});

test('T1271 (b) Q15 : 4 + 1 passages -> 2 documents, Brouillon Numéro 04.md en premier', () => {
    const c = withResults(q15);
    const grouped = c.groupedResults();
    assert.deepEqual(grouped.map((r) => r.filename), ['Brouillon Numéro 04.md', '00-Sommaire newsletter.md']);
    assert.deepEqual(grouped.map((r) => r.chunk_index), [0, 0]);
    assert.equal(c.otherPassagesCount(grouped[0]), 3);
    assert.equal(c.otherPassagesLabel(grouped[0]), '+ 3 autres passages');
    assert.equal(c.otherPassagesCount(grouped[1]), 0);
    assert.equal(c.otherPassagesLabel(grouped[1]), '');
});

test('T1271 (c) le representant est le passage de plus petite distance, et l ordre suit ce meilleur passage', () => {
    // entree volontairement hors ordre : le meilleur passage de A n'est pas le premier
    const c = withResults([
        fileChunk('a', 'a.md', 3, 0.6),
        fileChunk('b', 'b.md', 0, 0.5),
        fileChunk('a', 'a.md', 0, 0.4),
    ]);
    const grouped = c.groupedResults();
    assert.deepEqual(grouped.map((r) => `${r.filename}#${r.chunk_index}`), ['a.md#0', 'b.md#0']);
});

test('T1271 (c) sans distance exploitable, l ordre serveur fait foi (payload historique)', () => {
    const c = withResults([
        fileChunk('a', 'a.md', 2, undefined),
        fileChunk('b', 'b.md', 0, null),
        fileChunk('a', 'a.md', 0, 'n/a'),
    ]);
    const grouped = c.groupedResults();
    assert.deepEqual(grouped.map((r) => `${r.filename}#${r.chunk_index}`), ['a.md#2', 'b.md#0']);
    assert.equal(c.otherPassagesCount(grouped[0]), 1);
    assert.equal(c.otherPassagesLabel(grouped[0]), '+ 1 autre passage');
});

test('T1271 (d) la ligne groupee est l objet serveur lui-meme : citation_url et apercu inchanges', () => {
    const c = withResults(q15);
    const grouped = c.groupedResults();
    assert.equal(grouped[0], q15[0]); // meme reference, pas une copie
    assert.equal(grouped[0].citation_url, '/org/test20260822/dossiers/d/files/brouillon');
    assert.equal(c.canPreviewResult(grouped[0]), true);
    const appels = [];
    c.openPreview = (f) => appels.push(f);
    assert.equal(c.openResultPreview(grouped[0]), true);
    assert.deepEqual(appels, [{ id: 'brouillon', mime_type: 'text/markdown', display_name: 'Brouillon Numéro 04.md' }]);
    assert.equal(c.resultLinkLabel(grouped[0]), 'Ouvrir le document');
});

test('T1271 cle DOM : unique par document, identique pour deux passages du meme document', () => {
    const c = component();
    assert.equal(c.documentKey(q11[0]), c.documentKey(q11[4]));
    assert.notEqual(c.documentKey(q15[0]), c.documentKey(q15[4]));
    assert.equal(c.documentKey(fileA), 'file:file-a');
    assert.equal(c.documentKey(article), 'article:post-1');
    // la cle de passage reste disponible et derive de la cle document
    assert.equal(c.resultKey(fileA), 'file:file-a:0');
    // deux fichiers de noms differents restent deux documents, meme contenu identique (hors scope 07-Plan-262)
    assert.notEqual(c.documentKey(fileChunk('x', 'doublon.md', 0, 0.3)), c.documentKey(fileChunk('y', 'doublon (copie).md', 0, 0.3)));
});

test('T1271 (f) un resultat article reste inchange (titre, libelle, cle, ligne unique sans mention)', () => {
    const c = withResults([{ ...article, distance: 0.41, citation_url: '/org/o/blog/indexed-article' }]);
    const grouped = c.groupedResults();
    assert.equal(grouped.length, 1);
    assert.equal(c.resultTitle(grouped[0]), 'Indexed article');
    assert.equal(c.resultLinkLabel(grouped[0]), "Lire l’article");
    assert.equal(c.documentKey(grouped[0]), 'article:post-1');
    assert.equal(grouped[0].citation_url, '/org/o/blog/indexed-article');
    assert.equal(c.canPreviewResult(grouped[0]), false);
    assert.equal(c.otherPassagesCount(grouped[0]), 0);
    assert.equal(c.otherPassagesLabel(grouped[0]), '');
});

test('T1271 (f) article + fichier melanges : deux documents, chacun avec sa source', () => {
    const c = withResults([
        { ...article, chunk_index: 0, distance: 0.45 },
        fileChunk('file-a', 'contrat-2026.pdf', 0, 0.47, { mime_type: 'application/pdf' }),
        { ...article, chunk_index: 3, distance: 0.52 },
    ]);
    const grouped = c.groupedResults();
    assert.deepEqual(grouped.map((r) => c.resultTitle(r)), ['Indexed article', 'contrat-2026.pdf']);
    assert.deepEqual(grouped.map((r) => c.resultLinkLabel(r)), ["Lire l’article", 'Ouvrir le document']);
    assert.equal(c.otherPassagesLabel(grouped[0]), '+ 1 autre passage');
    assert.equal(c.otherPassagesLabel(grouped[1]), '');
});

test('T1271 i18n : pluriel via les deux gabarits transmis (1 autre passage / N autres passages), EN aussi', () => {
    const en = extractFactory()({ endpoint: '/x', i18n: { ...i18n, otherPassagesOne: '+ 1 other passage', otherPassagesMany: '+ :count other passages' } });
    en.results = q15;
    assert.equal(en.otherPassagesLabel(en.groupedResults()[0]), '+ 3 other passages');
    en.results = q15.slice(0, 2);
    assert.equal(en.otherPassagesLabel(en.groupedResults()[0]), '+ 1 other passage');
});

test('T1271 liste vide : aucun groupe, aucune exception', () => {
    const c = withResults([]);
    assert.deepEqual(c.groupedResults(), []);
});
