<?php

namespace App\Services\Blog;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * TASK-1785 — lire un flux RSS/Atom deja telecharge, et n'en garder que des
 * donnees.
 *
 * ## La garde XXE est posee AVANT le parsing (precaution MASTER n°3)
 *
 * MASTER le dit mot pour mot : « ne pas supposer que `LIBXML_NONET` protege si
 * l'appel interne de la bibliotheque ne l'utilise pas ». Et c'est exactement
 * le cas ici — `FeedIo\Reader\Document::loadDomDocument()` appelle
 * `$domDocument->loadXML($this->content)` **sans aucun drapeau**. Passer des
 * options a feed-io n'aurait donc rien change : elles n'atteignent jamais
 * l'appel qui compte.
 *
 * La protection ne peut donc pas etre un drapeau. Elle est un REFUS, en amont
 * et sur la chaine : tout document portant une declaration `<!DOCTYPE` ou
 * `<!ENTITY` est rejete avant qu'un parseur ne le voie. Un flux RSS legitime
 * n'en a aucun besoin, donc le refus ne coute rien d'utile.
 *
 * Mesure du 07/11 : libxml 2.9.14 ne substitue deja pas les entites externes.
 * Cette garde ne remplace pas ce defaut — elle cesse d'en DEPENDRE, puisqu'il
 * varie avec la version installee sur la machine qui execute.
 *
 * ## Pourquoi un parsing maison plutot que `FeedIo::read()`
 *
 * `read()` telecharge lui-meme. Or tout le travail de `RssFeedFetcher` — garde
 * SSRF, epinglage d'adresse, redirections suivies a la main, borne de taille —
 * serait contourne si la bibliotheque allait chercher l'URL elle-meme. Le
 * telechargement et la lecture sont donc separes, et c'est deliberé.
 *
 * ## Rien n'est invente
 *
 * Un champ absent du flux reste `null`. Le CDC l'exige — « ne pas inventer de
 * contenu manquant » — et c'est aussi ce qui rend l'ecran honnete : « auteur
 * si disponible » veut dire vide quand il ne l'est pas.
 */
class RssFeedReader
{
    /** Plafond d'articles retenus par chargement. Le CDC demande un nombre borne. */
    public const ARTICLES_MAX = 50;

    /**
     * Lit un flux et rend des articles normalises.
     *
     * @return list<array{identite: string, guid: string|null, url: string|null, titre: string, auteur: string|null, resume: string|null, contenu_html: string|null, publie_le: string|null}>
     */
    public function lire(string $xml): array
    {
        $xml = $this->degraisser($xml);

        $this->refuserLesEntites($xml);

        $document = $this->charger($xml);
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('content', 'http://purl.org/rss/1.0/modules/content/');
        $xpath->registerNamespace('dc', 'http://purl.org/dc/elements/1.1/');
        $xpath->registerNamespace('atom', 'http://www.w3.org/2005/Atom');

        // RSS 2.0 (`//item`) et Atom (`//atom:entry`) : les deux formes qu'un
        // WordPress peut servir selon ses reglages.
        $noeuds = $xpath->query('//item');

        if ($noeuds === false || $noeuds->length === 0) {
            $noeuds = $xpath->query('//atom:entry');
        }

        if ($noeuds === false || $noeuds->length === 0) {
            throw new RuntimeException('flux_sans_article');
        }

        $articles = [];

        foreach ($noeuds as $noeud) {
            if (! $noeud instanceof DOMElement) {
                continue;
            }

            $article = $this->article($xpath, $noeud);

            if ($article === null) {
                continue;
            }

            $articles[] = $article;

            if (count($articles) >= self::ARTICLES_MAX) {
                break;
            }
        }

        if ($articles === []) {
            throw new RuntimeException('flux_sans_article');
        }

        return $articles;
    }

    /**
     * Retire ce qui precede la declaration XML.
     *
     * DEFAUT TROUVE EN RECETTE REELLE le 08/11, et qu'aucune fixture n'aurait
     * montre : `https://cpformation.com/feed/` commence par un ESPACE avant
     * `<?xml` — premier octet `0x20`, mesure. `DOMDocument::loadXML()` refuse
     * tout caractere avant la declaration, et rendait donc « xml_illisible »
     * sur un flux parfaitement valide.
     *
     * Ce n'est pas un cas tordu : un greffon WordPress qui laisse echapper une
     * ligne vide hors de ses balises PHP suffit, et c'est frequent. `feed-io`
     * degraisse d'ailleurs pour la meme raison (`Reader\Document`).
     *
     * Le BOM UTF-8 est retire aussi : invisible, et il produit exactement la
     * meme panne.
     */
    private function degraisser(string $xml): string
    {
        return trim(str_replace("\xEF\xBB\xBF", '', $xml));
    }

    /**
     * Le refus qui tient lieu de protection XXE.
     *
     * Volontairement grossier et sur la CHAINE : on ne veut pas avoir a
     * raisonner sur ce qu'un parseur fera d'un DOCTYPE, on veut qu'aucun
     * parseur n'en voie.
     */
    private function refuserLesEntites(string $xml): void
    {
        // Les commentaires XML sont retires d'abord : `<!-- <!DOCTYPE … -->`
        // est inoffensif, et le refuser donnerait un faux positif sur un flux
        // legitime qui documente son format en commentaire.
        $sansCommentaires = preg_replace('/<!--.*?-->/s', '', $xml) ?? $xml;

        if (preg_match('/<!\s*(DOCTYPE|ENTITY)/i', $sansCommentaires) === 1) {
            throw new RuntimeException('xml_avec_declaration_interdite');
        }
    }

    private function charger(string $xml): DOMDocument
    {
        $document = new DOMDocument();

        $erreursAvant = libxml_use_internal_errors(true);

        try {
            // `LIBXML_NONET` et `LIBXML_NOENT` sont passes malgre la garde
            // ci-dessus : deux protections independantes valent mieux qu'une,
            // et celle-ci ne coute rien. Mais c'est bien le REFUS qui protege,
            // puisque ces drapeaux n'atteindraient pas un appel interne de
            // bibliotheque.
            $ok = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($erreursAvant);
        }

        if ($ok === false) {
            throw new RuntimeException('xml_illisible');
        }

        return $document;
    }

    /**
     * @return array{identite: string, guid: string|null, url: string|null, titre: string, auteur: string|null, resume: string|null, contenu_html: string|null, publie_le: string|null}|null
     */
    private function article(DOMXPath $xpath, DOMElement $noeud): ?array
    {
        $titre = $this->texte($xpath, 'title', $noeud);

        // Sans titre, il n'y a pas d'article a proposer : on n'en fabrique pas
        // un depuis l'URL.
        if ($titre === null || trim($titre) === '') {
            return null;
        }

        $guid = $this->texte($xpath, 'guid', $noeud) ?? $this->texte($xpath, 'atom:id', $noeud);
        $url = $this->lien($xpath, $noeud);

        $publieLe = $this->texte($xpath, 'pubDate', $noeud)
            ?? $this->texte($xpath, 'atom:published', $noeud)
            ?? $this->texte($xpath, 'atom:updated', $noeud)
            ?? $this->texte($xpath, 'dc:date', $noeud);

        return [
            'identite' => $this->identite($guid, $url, $titre, $publieLe),
            'guid' => $guid,
            'url' => $url,
            'titre' => trim($titre),
            'auteur' => $this->texte($xpath, 'dc:creator', $noeud)
                ?? $this->texte($xpath, 'author', $noeud)
                ?? $this->texte($xpath, 'atom:author/atom:name', $noeud),
            'resume' => $this->texte($xpath, 'description', $noeud)
                ?? $this->texte($xpath, 'atom:summary', $noeud),
            'contenu_html' => $this->texte($xpath, 'content:encoded', $noeud)
                ?? $this->texte($xpath, 'atom:content', $noeud),
            'publie_le' => $publieLe,
        ];
    }

    /**
     * L'IDENTITE SOURCE, et la raison de ses trois etages (precaution MASTER n°1).
     *
     * Tous les flux ne servent pas un GUID fiable : WordPress en met un par
     * defaut, mais un flux reecrit par un greffon peut l'omettre, ou en servir
     * un qui change a chaque regeneration. Une identite batie sur le seul GUID
     * creerait alors un doublon a chaque rafraichissement — precisement ce que
     * le CDC interdit.
     *
     * Trois etages, du plus fiable au moins mauvais :
     *
     *  1. le **GUID** quand il existe — l'identite que le flux promet stable ;
     *  2. a defaut l'**URL canonique** — stable en pratique sur un WordPress ;
     *  3. a defaut une **empreinte du titre et de la date source** — pas
     *     ideal, mais DETERMINISTE : deux lectures du meme flux rendent la
     *     meme valeur, donc l'anti-doublon tient quand meme.
     *
     * Le prefixe dit quel etage a servi : un `titre:` dans la base signale un
     * flux a surveiller, sans avoir a deviner.
     */
    public function identite(?string $guid, ?string $url, string $titre, ?string $publieLe): string
    {
        if ($guid !== null && trim($guid) !== '') {
            return 'guid:'.hash('sha256', trim($guid));
        }

        if ($url !== null && trim($url) !== '') {
            return 'url:'.hash('sha256', trim($url));
        }

        return 'titre:'.hash('sha256', Str::lower(trim($titre)).'|'.trim((string) $publieLe));
    }

    private function lien(DOMXPath $xpath, DOMElement $noeud): ?string
    {
        $lien = $this->texte($xpath, 'link', $noeud);

        if ($lien !== null && trim($lien) !== '') {
            return trim($lien);
        }

        // Atom porte l'adresse en ATTRIBUT, pas en contenu.
        $href = $xpath->query('atom:link[@rel="alternate"]/@href | atom:link[not(@rel)]/@href', $noeud);

        if ($href !== false && $href->length > 0) {
            return trim($href->item(0)->nodeValue ?? '') ?: null;
        }

        return null;
    }

    private function texte(DOMXPath $xpath, string $chemin, DOMElement $noeud): ?string
    {
        $trouve = $xpath->query($chemin, $noeud);

        if ($trouve === false || $trouve->length === 0) {
            return null;
        }

        $valeur = trim($trouve->item(0)->textContent ?? '');

        return $valeur === '' ? null : $valeur;
    }
}
