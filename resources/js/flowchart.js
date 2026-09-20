import cytoscape from 'cytoscape';

/**
 * TASK-1608 — le moteur de la carte interactive.
 *
 * ## Ce fichier ne decide AUCUNE permission
 *
 * Il affiche ce que Laravel lui a donne, et rien d'autre. Une Boucle absente du
 * payload n'existe pas de son point de vue : il n'y a aucun filtrage de
 * securite ici, parce qu'il n'y a rien a filtrer.
 *
 * ## Il ne persiste rien (§21)
 *
 * V0 = EXPLORATEUR, pas editeur. Les noeuds ne sont pas deplacables, aucune
 * position n'est sauvegardee, aucune arete n'est reconnectable.
 *
 * ## FOCUS + CONTEXTE, et pourquoi les layouts automatiques sont partis
 *
 * Version precedente : `breadthfirst` avec un `transform` qui transposait x/y
 * pour coucher le graphe a l'horizontale. Mesure : le `transform` etait appele
 * 22 fois et n'avait AUCUN effet — la boite restait 370x1627. Un deplie
 * debordait donc du cadre (10 noeuds sur 20 hors ecran), et le `fit` de
 * rattrapage ecrasait le texte a 8 px, puis a 4 px en vue d'ensemble.
 *
 * Reparer ce `transform` par retouches successives aurait garde le vrai
 * defaut : la geometrie etait un EFFET SECONDAIRE du layout, pas un choix.
 *
 * Elle est desormais calculee ici, explicitement. La topologie est connue et
 * stable : il n'y a rien a deviner.
 *
 * Le modele d'interaction est celui arbitre par MASTER :
 *
 *   CLIC -> CENTRAGE SUR L'ITEM -> ZOOM DE LECTURE
 *        -> DEVELOPPEMENT LOCAL -> CONTEXTE AUTOUR
 *
 * On n'affiche jamais toute une branche d'un coup, et on ne « fit » jamais
 * globalement en exploration. Le noeud actif vient au centre de la zone
 * REELLEMENT utile — celle qui reste quand le panneau de detail est ouvert —
 * ses voisins directs se posent autour, et ce qui a deja ete visite reste
 * visible mais attenue.
 *
 * ## Le fond appartient a l'application
 *
 * Cytoscape ne peint aucune couleur de fond : le canvas est transparent et
 * laisse voir `--bp-page`, pose par le layout.
 */

/** L'echelle de lecture. On ne descend pas en dessous en exploration. */
const ZOOM_LECTURE = 1;

/** Plancher absolu : en dessous, les libelles cessent d'etre lisibles. */
const ZOOM_MIN_LISIBLE = 0.78;

const ZOOM_MAX = 2.2;

/**
 * Plancher propre a la vue de synthese.
 *
 * Elle doit se comprendre en cinq secondes : un libelle sous ~14 px a l'ecran
 * la rend inutile. Les noeuds y sont a 16 px de police, donc 0,88 garantit
 * environ 14 px rendus.
 */
const ZOOM_MIN_SYNTHESE = 0.88;

/**
 * L'eventail des voisins est ELLIPTIQUE, pas circulaire.
 *
 * Le cadre utile mesure 1360x660 : il est deux fois plus large que haut. Un
 * eventail circulaire depense donc sa place dans l'axe le plus rare. Mesure
 * avec un rayon unique de 260 : l'echelle tombait a 0,83 et les libelles a
 * 13,2 px, pour une occupation de 18 % seulement — le graphe etait a la fois
 * petit ET a l'etroit.
 */
const RX_PROCHE = 400;

/**
 * Rayons du CERCLE COMPLET, utilise quand le noeud actif n'a pas de parent.
 *
 * La racine n'a pas de « sens de lecture » : ses portes l'entourent. Un
 * eventail sur le seul hemisphere droit poussait toute la composition a
 * droite (occupation mesuree : 25 %) et serrait les satellites a ~105 px les
 * uns des autres, si bien que les libelles — larges de 210 px — recouvraient
 * les noeuds voisins. Visible a l'oeil sur la capture, invisible dans les
 * chiffres : aucune de mes mesures ne regardait les collisions de texte.
 */
const RX_ETOILE = 470;

const RY_ETOILE = 245;

/** Espacement vertical minimal entre deux cards empilees. */
const PAS_COLONNE = 118;

const RY_PROCHE = 150;

/** L'ouverture de l'eventail, en radians. */
const OUVERTURE_PROCHE = Math.PI * 0.5;

/**
 * Le pas d'une CHAINE, quand le noeud actif n'a qu'une suite.
 *
 * ELLIPTIQUE lui aussi, et pour la meme raison que l'etoile : le cadre est
 * deux fois plus large que haut. Un pas isotrope faisait partir la chaine
 * VERTICALEMENT quand la porte choisie etait celle du haut, et l'ecart a
 * l'actif atteignait alors 435 px pour un demi-cadre de 290 — l'echelle
 * tombait au plancher, 0,78, et les libelles a 11,7 px. Le sens de lecture
 * est conserve, sa composante verticale est simplement aplatie.
 */
const PAS_CHAINE_X = 400;

const PAS_CHAINE_Y = 140;

/** Au-dela, les freres passent en grille plutot qu'en eventail (§6, §7). */
const SEUIL_GRILLE = 6;

/**
 * L'ordre du tronc commun.
 *
 * Il double `FlowchartGraph::STEPS` cote client — la scene a besoin de
 * positionner les etapes AVANT de les afficher, donc de connaitre leur ordre.
 * Le payload, lui, ne porte que des aretes. Si les deux divergeaient, la scene
 * afficherait un ordre faux : le test `TASK1608FlowchartSceneTest` compare les
 * deux listes.
 */
const ORDRE_TRONC = [
    'step:clarify',
    'step:match',
    'step:exchange',
    'step:ai',
    'step:resources',
    'step:dossiers',
    'step:synthesis',
    'step:decision',
];

const racine = document.querySelector('[data-flowchart-canvas]');
const source = document.querySelector('[data-flowchart-graph]');

/**
 * Melange deux couleurs du theme.
 *
 * Les cards de la page d'accueil posent un fond DOUX et une puce d'icone plus
 * soutenue, tires de la meme teinte. `color-mix()` fait cela en CSS, mais
 * Cytoscape peint sur un canvas : il lui faut une couleur resolue. On la
 * calcule donc ici, a partir des jetons `--bp-*` — aucune palette en dur,
 * aucune couleur par slug.
 */
function melange(couleurA, couleurB, part) {
    const lire = (c) => {
        const h = c.replace('#', '').trim();
        const plein = h.length === 3 ? h.split('').map((x) => x + x).join('') : h;

        return [0, 2, 4].map((i) => parseInt(plein.slice(i, i + 2), 16) || 0);
    };

    const a = lire(couleurA);
    const b = lire(couleurB);
    const canal = (i) => Math.round(a[i] * part + b[i] * (1 - part));

    return '#' + [0, 1, 2].map((i) => canal(i).toString(16).padStart(2, '0')).join('');
}

/**
 * Les pictogrammes des cards, en SVG inline.
 *
 * Meme grammaire que les cards de l'accueil — coeur, main, idee, lien — mais
 * la police d'icones du projet (Tabler) n'est pas utilisable DANS un noeud
 * Cytoscape, qui peint sur un canvas. Les chemins sont donc poses ici, sans
 * aucune bibliotheque supplementaire : la puce complete (cercle + trait) est
 * generee en data-URI, teintee par les jetons du theme.
 */
const CHEMINS = {
    need_help: '<path d="M12 20s-7-4.6-7-9.3A4.2 4.2 0 0 1 12 7a4.2 4.2 0 0 1 7 3.7C19 15.4 12 20 12 20z"/>',
    offer_help: '<path d="M8 13V6.5a1.5 1.5 0 0 1 3 0V12m0-1.5a1.5 1.5 0 0 1 3 0V12m0-1a1.5 1.5 0 0 1 3 0v4a5 5 0 0 1-5 5h-1.5a4 4 0 0 1-3.2-1.6L5 16"/>',
    explore_idea: '<path d="M9.5 17h5M10 20h4M12 3a6 6 0 0 1 3.5 10.9c-.4.3-.5.7-.5 1.1H9c0-.4-.1-.8-.5-1.1A6 6 0 0 1 12 3z"/>',
    connect: '<path d="M10 13.5a3.5 3.5 0 0 0 5 0l2.5-2.5a3.5 3.5 0 0 0-5-5L11 7.5m3 3a3.5 3.5 0 0 0-5 0L6.5 13a3.5 3.5 0 0 0 5 5L13 16.5"/>',
    explore: '<path d="M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zm3.5 5.5-2 5-5 2 2-5z"/>',
    // Les six resultats. Un pictogramme chacun : le libelle vit DANS la card,
    // jamais flottant a cote (addendum « texte integre »).
    entraide: '<path d="M7 13.5 4 11m3 2.5 4.5 4.5 8-8M4 15.5 8.5 20"/>',
    relation: '<path d="M9 11a3 3 0 1 0 0-6 3 3 0 0 0 0 6zm8 2a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5zM3 20v-1.5A3.5 3.5 0 0 1 6.5 15h5a3.5 3.5 0 0 1 3.5 3.5V20m2-5.5h.5a3 3 0 0 1 3 3V19"/>',
    learning: '<path d="M4 6a2 2 0 0 1 2-2h5v15H6a2 2 0 0 0-2 2zm16 0a2 2 0 0 0-2-2h-5v15h5a2 2 0 0 1 2 2z"/>',
    coordination: '<path d="M4 7a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2zM4 10h16M8 3v4m8-4v4m-7 8h6"/>',
    action: '<path d="m13 3-8 10h6l-1 8 8-10h-6z"/>',
    memory: '<path d="M4 7h16v3H4zm1 3v8a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-8M10 14h4"/>',
};

/** La puce ronde d'une card, en data-URI. Cercle doux + trait soutenu. */
function puce(cle, fond, trait) {
    const chemin = CHEMINS[cle];

    if (!chemin) {
        return null;
    }

    const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="46" height="46" viewBox="0 0 46 46">`
        + `<circle cx="23" cy="23" r="23" fill="${fond}"/>`
        + `<g transform="translate(11 11)" fill="none" stroke="${trait}" stroke-width="1.8" `
        + `stroke-linecap="round" stroke-linejoin="round">${chemin}</g></svg>`;

    return 'data:image/svg+xml;utf8,' + encodeURIComponent(svg);
}

/** Un jeton de theme, avec un repli explicite : un `var()` vide serait invisible. */
function jeton(nom, repli) {
    const valeur = getComputedStyle(document.documentElement).getPropertyValue(nom).trim();

    return valeur || repli;
}

/**
 * Relit les jetons du theme ACTIF.
 *
 * Appelable a volonte : c'est ce qui permet de suivre un changement de theme
 * sans recharger. Les valeurs ne sont jamais memorisees ailleurs qu'ici.
 */
function lireJetons() {
    return {
        page: jeton('--bp-page', '#0f172a'),
        surface: jeton('--bp-surface', '#1e293b'),
        panel: jeton('--bp-panel', '#1e293b'),
        primary: jeton('--bp-primary', '#1B1FCC'),
        primaryDeep: jeton('--bp-primary-deep', '#141799'),
        accent: jeton('--bp-accent', '#7c3aed'),
        validation: jeton('--bp-validation', '#16a34a'),
        info: jeton('--bp-info', '#0284c7'),
        progress: jeton('--bp-progress', '#f59e0b'),
        warning: jeton('--bp-warning', '#f97316'),
        text: jeton('--bp-text', '#e2e8f0'),
        muted: jeton('--bp-muted', '#94a3b8'),
        border: jeton('--bp-border', '#334155'),
    };
}

function demarrer(racine, graph) {
    const etroit = () => racine.clientWidth < 720;

    // --- Les cards, mesurees ------------------------------------------
    //
    // Le libelle vit DANS la card, jamais flottant dessous : un texte pose
    // sous une forme peut recouvrir le voisin, et la mesure l'avait montre
    // (3 collisions). Une card ne peut chevaucher que si les cards elles-memes
    // se touchent, ce que la geometrie empeche.
    //
    // Deux jeux de mesures : un cadre de 390 px n'accueille pas une card de
    // 236. Le jeu est choisi au demarrage — un changement de format en cours
    // de route (rotation) replace les noeuds mais ne redimensionne pas les
    // cards. Limite assumee de la V0, rapportee a MASTER.
    const COMPACT = racine.clientWidth < 720;

    const CARD = COMPACT
        ? {
            intent: { w: 168, h: 68, icone: true, puce: 28 },
            explore: { w: 168, h: 68, icone: true, puce: 28 },
            outlet: { w: 168, h: 58, icone: false, puce: 0 },
            outcome: { w: 164, h: 56, icone: true, puce: 28 },
            entry: { w: 168, h: 58, icone: false, puce: 0 },
            step: { w: 162, h: 54, icone: false, puce: 0 },
            aggregate: { w: 168, h: 60, icone: false, puce: 0 },
            loop: { w: 176, h: 70, icone: false, puce: 0 },
        }
        : {
            intent: { w: 236, h: 96, icone: true, puce: 46 },
            explore: { w: 236, h: 96, icone: true, puce: 46 },
            outlet: { w: 214, h: 70, icone: false, puce: 0 },
            outcome: { w: 214, h: 68, icone: true, puce: 40 },
            entry: { w: 216, h: 68, icone: false, puce: 0 },
            step: { w: 208, h: 64, icone: false, puce: 0 },
            aggregate: { w: 224, h: 76, icone: false, puce: 0 },
            loop: { w: 224, h: 84, icone: false, puce: 0 },
        };

    /** Largeur de texte disponible : la card, moins la puce et les marges. */
    const largeurTexte = (genre) => {
        const c = CARD[genre];

        return c.icone ? c.w - c.puce - 30 : c.w - 26;
    };

    /** Decalage du texte : centre dans l'espace RESTANT a droite de la puce. */
    const decalageTexte = (genre) => (CARD[genre].icone ? Math.round(CARD[genre].puce / 2 + 4) : 0);

    /**
     * Le stylesheet Cytoscape, construit A PARTIR des jetons qu'on lui donne.
     *
     * Il est isole dans une fonction pour une raison precise : changer de
     * theme ne doit pas recharger la page. Cytoscape garde le stylesheet qu'on
     * lui a passe a la construction — les couleurs etaient donc figees a
     * l'initialisation, et il fallait un F5 pour les voir changer.
     *
     * Rappeler cette fonction avec les jetons relus, puis la reinjecter, suffit
     * a repeindre : positions, zoom, pan, noeud actif et classes survivent,
     * parce qu'on ne reconstruit RIEN — on ne remplace que la feuille de style.
     */
    function construireStyle(couleurs) {
        // La couleur porte le SENS, pas la decoration. Toutes les teintes sortent
        // des jetons `--bp-*` du theme courant : aucune palette par Organization.
        const semantique = {
                // `--bp-primary`, et pas une approximation : c'est EXACTEMENT le
            // jeton du bouton « BouclePro IA » en bas a droite
            // (`components/ai-fab.blade.php:32`,
            // `.bp-ai-trigger{background:var(--bp-primary)}`). Deux bleus
            // presque identiques seraient une dette de design system.
            root: couleurs.primary,
            intent: couleurs.primary,
            entry: couleurs.progress,
            step: couleurs.info,
            aggregate: couleurs.info,
            outcome: couleurs.validation,
            explore: couleurs.accent,
            outlet: couleurs.progress,
            loop: couleurs.accent,
        };

        /** Le fond DOUX d'une card : la teinte semantique fondue dans la page. */
        const fondDoux = (cle) => melange(semantique[cle] || couleurs.muted, couleurs.page, 0.14);

        /** La puce de l'icone : la meme teinte, un peu plus presente. */
        const fondPuce = (cle) => melange(semantique[cle] || couleurs.muted, couleurs.page, 0.3);

        /**
         * L'icone d'un noeud, s'il en a une.
         *
         * Les intentions et les resultats en portent une ; les etapes non — leur
         * card se lit au titre seul, et inventer huit pictogrammes pour un moteur
         * qui se raconte en mots aurait charge la carte sans rien ajouter.
         */
        function iconeDe(noeud) {
            const genre = noeud.data('kind');
            const id = noeud.data('id') || '';

            const cle = genre === 'intent' ? id.replace('intent:', '')
                : genre === 'outcome' ? id.replace('outcome:', '')
                    : genre === 'explore' ? 'explore'
                        : null;

            if (!cle) {
                return null;
            }

            const famille = genre === 'outcome' ? 'outcome' : genre;

            return puce(cle, fondPuce(famille), semantique[famille]);
        }




        const styleCard = (genre) => ({
            selector: `node[kind = "${genre}"]`,
            style: {
                shape: 'round-rectangle',
                'corner-radius': 18,
                width: CARD[genre].w,
                height: CARD[genre].h,
                'background-color': fondDoux(genre),
                'background-opacity': 1,
                'border-width': 1.5,
                'border-color': melange(semantique[genre], couleurs.border, 0.5),
                'text-valign': 'center',
                'text-halign': 'center',
                'text-margin-x': decalageTexte(genre),
                'text-margin-y': 0,
                'text-max-width': largeurTexte(genre),
                'text-outline-width': 0,
                color: couleurs.text,
                'font-size': genre === 'intent' || genre === 'explore' ? (COMPACT ? 14 : 16) : (COMPACT ? 13 : 14),
                'font-weight': 700,
                // La puce, posee a gauche comme sur les cards de l'accueil.
                'background-image': (n) => iconeDe(n) || 'none',
                'background-fit': 'none',
                'background-width': CARD[genre].puce,
                'background-height': CARD[genre].puce,
                'background-position-x': COMPACT ? 12 : 18,
                'background-position-y': (CARD[genre].h - CARD[genre].puce) / 2,
                'background-clip': 'none',
            },
        });

        return [
                {
                    selector: 'node',
                    style: {
                        label: 'data(label)',
                        color: couleurs.text,
                        'font-size': 14,
                        'font-weight': 700,
                        'text-wrap': 'wrap',
                        'line-height': 1.25,
                        'transition-property': 'background-color, border-width, border-color, opacity',
                        'transition-duration': 220,
                    },
                },

                // La racine garde une forme distinctive : c'est l'ancrage, et une
                // ancre ne se confond pas avec ce qu'elle tient.
                {
                    selector: 'node[kind = "root"]',
                    style: {
                        shape: 'ellipse',
                        width: COMPACT ? 96 : 168,
                        height: COMPACT ? 96 : 168,
                        'background-color': couleurs.primary,
                        'border-width': 3,
                        'border-color': couleurs.primaryDeep,
                        'text-valign': 'center',
                        'text-halign': 'center',
                        'text-max-width': COMPACT ? 104 : 148,
                        'font-size': COMPACT ? 14 : 17,
                        color: '#FFFFFF',
                    },
                },

                ...Object.keys(CARD).map(styleCard),

                // La Boucle porte deux lignes — nom puis statut — donc un peu plus
                // d'air et un cadre plus marque : c'est une destination.
                {
                    selector: 'node[kind = "loop"]',
                    style: { 'border-width': 2, 'border-color': semantique.loop, 'font-size': COMPACT ? 12 : 14 },
                },

                {
                    selector: 'edge',
                    style: {
                        width: 2,
                        'line-color': couleurs.border,
                        'target-arrow-color': couleurs.border,
                        'target-arrow-shape': 'triangle',
                        'arrow-scale': 1.1,
                        'curve-style': 'bezier',
                        opacity: 0.6,
                        // Les fleches s'arretent au BORD de la card : le rendu
                        // compound de Cytoscape les fait toucher la boite du
                        // noeud, jamais son texte.
                        'target-distance-from-node': 4,
                        'source-distance-from-node': 4,
                        'transition-property': 'line-color, opacity, width',
                        'transition-duration': 220,
                    },
                },

                // --- ETATS INTERACTIFS (addendum MASTER couleurs) -----------
                //
                // Tous derives des jetons du theme. L'etat ne repose jamais sur la
                // SEULE teinte — bordure, halo et opacite le portent aussi, ce qui
                // le garde lisible sur un theme sombre comme clair.
                {
                    selector: 'node.bp-survol',
                    style: { 'border-width': 3, 'border-color': couleurs.accent },
                },
                // L'etat actif se lit sur la CARD elle-meme, et nulle part
                // autour.
                //
                // La version precedente peignait un halo `underlay-*`, pose
                // pour remplacer le `shadow-*` que Cytoscape 3.34 ne connait
                // pas sur un noeud. En sombre, ce halo se lisait comme un
                // grand rectangle opaque derriere la card — un bloc, pas une
                // mise en evidence. Il est retire : la bordure suffit, et elle
                // epouse la forme du noeud au lieu d'en dessiner une seconde.
                {
                    selector: 'node.bp-actif',
                    style: {
                        'border-width': 4,
                        'border-color': couleurs.primary,
                        'border-opacity': 1,
                    },
                },
                // Le retour a la ligne de la scene du moteur : un coude, pas
                // une diagonale. `taxi` trace des segments orthogonaux, donc
                // le changement de rangee se LIT au lieu de se deviner.
                {
                    selector: 'edge.bp-retour',
                    style: {
                        'curve-style': 'taxi',
                        'taxi-direction': 'downward',
                        'taxi-turn': '50%',
                        'taxi-turn-min-distance': 12,
                    },
                },
                { selector: 'edge.bp-actif', style: { 'line-color': couleurs.primary, 'target-arrow-color': couleurs.primary, width: 3, opacity: 1 } },
                // Focus clavier : meme principe, teinte distincte du survol.
                // Une bordure epouse la card ; un `overlay`/`underlay` y
                // collerait un bloc rectangulaire.
                {
                    selector: 'node.bp-focus',
                    style: { 'border-width': 4, 'border-color': couleurs.progress, 'border-opacity': 1 },
                },

                // En synthese, les cards sont plus compactes : la vue doit se lire
                // d'un coup, pas se dechiffrer.
                { selector: 'node.bp-synthese', style: { 'font-size': 15 } },

                // Le contexte : attenue, mais ENCORE LISIBLE.
                {
                    selector: 'node.bp-contexte',
                    style: { 'background-opacity': 0.4, 'border-opacity': 0.4, 'text-opacity': 0.72 },
                },
                { selector: 'edge.bp-contexte', style: { opacity: 0.18 } },
                { selector: '.bp-replie', style: { display: 'none' } },
            ];
        }

    const cy = cytoscape({
        container: racine,
        elements: { nodes: graph.nodes, edges: graph.edges },
        // §21 : la carte s'explore, elle ne s'edite pas.
        autoungrabify: true,
        boxSelectionEnabled: false,
        minZoom: 0.2,
        maxZoom: ZOOM_MAX,
        style: construireStyle(lireJetons()),
    });

    /**
     * Repeint le graphe aux couleurs du theme COURANT.
     *
     * `cy.style().fromJson(...).update()` remplace la feuille de style en
     * place. Aucun `destroy()`, aucune reconstruction : les positions, le
     * zoom, le pan, le noeud actif et toutes les classes d'etat survivent —
     * c'est le contrat pose par MASTER.
     */
    function appliquerTheme() {
        cy.style().fromJson(construireStyle(lireJetons())).update();
    }

    const root = cy.getElementById('root');

    // Les aretes servent deux lectures : l'exploration et la synthese.
    const aretes = (vue) => cy.edges().filter((e) => e.data('view') === vue || e.data('view') === 'both');

    // =====================================================================
    // La zone REELLEMENT utile du canvas
    //
    // Centrer sur le milieu mathematique poserait le noeud actif DERRIERE le
    // panneau de detail des qu'il est ouvert. On centre donc sur ce qui reste.
    // =====================================================================

    const panneau = document.querySelector('[data-flowchart-panel]');

    function zoneUtile() {
        const cadre = racine.getBoundingClientRect();
        let droite = cadre.width;
        let bas = cadre.height;

        if (panneau && !panneau.hidden) {
            const p = panneau.getBoundingClientRect();

            // Panneau lateral (desktop) : on rend la largeur restante.
            // Panneau bas (mobile) : on rend la hauteur restante.
            if (p.width < cadre.width * 0.7) {
                droite = Math.max(cadre.width * 0.35, p.left - cadre.left - 16);
            } else {
                bas = Math.max(cadre.height * 0.4, p.top - cadre.top - 16);
            }
        }

        return { x: droite / 2, y: bas / 2 };
    }

    /**
     * Amene un noeud au centre de la zone utile, a une echelle de lecture.
     *
     * Le `pan` est calcule, jamais delegue a un `fit` : c'est precisement ce
     * qui garantit qu'on n'ecrase pas le texte pour faire tenir des noeuds
     * dont personne n'a besoin a cet instant.
     *
     * `essentiels` — le parent, l'actif et ses voisins — est la SEULE chose
     * qu'on s'engage a garder dans le cadre. Le zoom est calcule depuis la
     * distance la plus grande entre l'actif et ces noeuds-la, puis borne par
     * le plancher de lisibilite : on prefere laisser du contexte deborder
     * plutot que rendre le texte illisible pour l'y faire tenir.
     */
    function focaliser(noeud, essentiels = null, duree = 420) {
        const centre = zoneUtile();
        const p = noeud.position();
        const z = Math.min(ZOOM_MAX, Math.max(ZOOM_MIN_LISIBLE, echellePour(noeud, essentiels)));

        cy.animate(
            { zoom: z, pan: { x: centre.x - p.x * z, y: centre.y - p.y * z } },
            { duration: duree, easing: 'ease-in-out-cubic' },
        );
    }

    /**
     * Cadre une SCENE : un ensemble revele d'un coup, centre sur lui-meme.
     *
     * Distinct de `focaliser()`, qui centre UN noeud. Une scene n'a pas de
     * noeud unique a mettre au milieu — c'est l'ensemble qui fait sens.
     *
     * `facteur` permet un leger dezoom delibere, sans jamais passer sous le
     * plancher de lisibilite.
     */
    function focaliserScene(elements, facteur = 1) {
        const cadre = racine.getBoundingClientRect();
        const centre = zoneUtile();
        const bb = elements.boundingBox({ includeLabels: true });
        const marge = Math.min(70, Math.round(Math.min(cadre.width, cadre.height) * 0.06));

        const tenir = Math.min(
            (Math.min(centre.x, cadre.width - centre.x) * 2 - marge * 2) / Math.max(bb.w, 1),
            (Math.min(centre.y, cadre.height - centre.y) * 2 - marge * 2) / Math.max(bb.h, 1),
        );

        const z = Math.min(ZOOM_MAX, Math.max(ZOOM_MIN_LISIBLE, Math.min(ZOOM_LECTURE, tenir) * facteur));
        const milieu = { x: bb.x1 + bb.w / 2, y: bb.y1 + bb.h / 2 };

        cy.animate(
            { zoom: z, pan: { x: centre.x - milieu.x * z, y: centre.y - milieu.y * z } },
            { duration: 460, easing: 'ease-in-out-cubic' },
        );
    }

    /**
     * L'echelle qui garde `essentiels` dans le cadre, l'actif etant centre.
     *
     * On raisonne en DEMI-cadre, parce que le noeud actif est au milieu : ce
     * qui compte est l'ecart maximal a lui, pas l'etendue totale.
     */
    function echellePour(noeud, essentiels) {
        if (!essentiels || essentiels.length === 0) {
            return ZOOM_LECTURE;
        }

        const cadre = racine.getBoundingClientRect();
        const centre = zoneUtile();
        // Proportionnelle : 40 px fixes mangeaient un quart de la demi-hauteur
        // utile sur un cadre mobile de 540.
        const marge = Math.min(40, Math.round(Math.min(cadre.width, cadre.height) * 0.06));
        const p = noeud.position();

        let ecartX = 1;
        let ecartY = 1;

        essentiels.forEach((entree) => {
            // Un noeud « entier » doit tenir avec sa card ; un noeud « repere »
            // — le parent — n'a qu'a rester visible, son centre suffit.
            //
            // Sans cette distinction, un parent pose en diagonale (la Boucle
            // d'exploration est a 470 px en x du centre) imposait a lui seul
            // une echelle de 0,79, et les libelles tombaient a 10,8 px : on
            // payait la lisibilite de ce qu'on vient d'ouvrir pour cadrer
            // entierement ce d'ou l'on vient.
            const { noeud, repere } = entree;
            const bb = repere
                ? { x1: noeud.position().x, x2: noeud.position().x, y1: noeud.position().y, y2: noeud.position().y }
                : noeud.boundingBox({ includeLabels: true });

            ecartX = Math.max(ecartX, Math.abs(bb.x1 - p.x), Math.abs(bb.x2 - p.x));
            ecartY = Math.max(ecartY, Math.abs(bb.y1 - p.y), Math.abs(bb.y2 - p.y));
        });

        // Le demi-cadre disponible de part et d'autre de l'actif.
        const demiX = Math.min(centre.x, cadre.width - centre.x) - marge;
        const demiY = Math.min(centre.y, cadre.height - centre.y) - marge;

        return Math.min(ZOOM_LECTURE, demiX / ecartX, demiY / ecartY);
    }

    // =====================================================================
    // Geometrie : placement CONTROLE autour du noeud actif
    // =====================================================================

    /**
     * Pose les voisins autour de l'actif.
     *
     * Eventail sur l'hemisphere droit tant qu'ils sont peu nombreux ; grille
     * des qu'ils deviennent une famille (§6 : des Boucles sont des SOEURS,
     * jamais une chaine ; §7 : le cas « beaucoup de Boucles » doit rester
     * utilisable sans tout reduire).
     */
    /**
     * Pose les noeuds QUI VIENNENT D'APPARAITRE, et eux seuls.
     *
     * ## Pourquoi rien de deja place ne bouge
     *
     * La version precedente replacait le parent a chaque focalisation, puis
     * tentait de rattraper ses autres enfants. Mesure : 4 noeuds hors cadre et
     * 3 collisions de libelle, parce que la carte se reorganisait sous les
     * yeux de la personne a chaque clic.
     *
     * La carte est desormais STABLE : elle s'etend, elle ne se recompose pas.
     * C'est la CAMERA qui se deplace. C'est aussi ce qui donne l'impression
     * d'entrer dans une partie de la carte plutot que d'en afficher plus —
     * le critere pose par MASTER.
     *
     * ## La direction
     *
     * Les nouveaux noeuds prolongent le mouvement : on repart dans l'axe
     * parent -> actif. L'etoile croit donc vers l'exterieur, et deux branches
     * explorees l'une apres l'autre ne se marchent pas dessus.
     */
    function placerNouveaux(actif, nouveaux, parent) {
        if (nouveaux.length === 0) {
            return;
        }

        const centre = actif.position();

        let base = 0;

        if (parent && parent.nonempty()) {
            const pp = parent.position();
            base = Math.atan2(centre.y - pp.y, centre.x - pp.x);
        }

        if (nouveaux.length > SEUIL_GRILLE) {
            placerEnGrille(nouveaux, centre, base);

            return;
        }

        // Sans parent — la racine — il n'y a pas de sens de lecture : les
        // portes ENTOURENT la question. C'est la croix dessinee par MASTER :
        //
        //                  J'ai besoin d'aide
        //     Explorer une idee    ROOT    Je peux aider
        //                   Creer du lien
        //
        // Positions POSEES, pas calculees par un layout : cette vue est connue
        // d'avance, et c'est la seule facon de garantir qu'aucune card n'en
        // touche une autre.
        if (!parent || parent.empty()) {
            const rx = etroit() ? 92 : RX_ETOILE;
            const ry = etroit() ? 150 : RY_ETOILE;

            // Haut, droite, bas, gauche — puis les diagonales pour tout
            // satellite supplementaire (« Explorer les Boucles »).
            // En large, la croix dessinee par MASTER.
            //
            // En etroit, elle est GEOMETRIQUEMENT impossible : la racine fait
            // 96 px de diametre et une card 176 de large. Les poser cote a
            // cote demande 118 px d'ecart horizontal minimum, pour une
            // demi-largeur utile de 163. Deux colonnes tenaient, mais forcaient
            // l'echelle a 0,78 et les libelles a 10,9 px — mesure.
            //
            // La composition mobile est donc VERTICALE : une colonne sous la
            // question, plus d'espacement, moins de contexte simultane. C'est
            // le parti demande par MASTER, et non un rabotage du texte.
            // ROOT, puis les quatre portes en GRILLE 2x2, puis « Explorer les
            // Boucles ». Une colonne unique de cinq cards descendait a 440 px
            // de modele : la derniere passait sous la barre de navigation
            // basse, mesure sur capture.
            const colonneX = 90;
            const rangeeY = [130, 225];

            const ancrages = etroit()
                ? [
                    { x: -colonneX, y: rangeeY[0] },
                    { x: colonneX, y: rangeeY[0] },
                    { x: -colonneX, y: rangeeY[1] },
                    { x: colonneX, y: rangeeY[1] },
                    { x: 0, y: 320 },
                    { x: 0, y: -130 },
                ]
                : [
                    { x: 0, y: -ry },
                    { x: rx, y: 0 },
                    { x: 0, y: ry },
                    { x: -rx, y: 0 },
                    { x: rx, y: ry },
                    { x: -rx, y: -ry },
                ];

            nouveaux.forEach((noeud, i) => {
                const a = ancrages[i % ancrages.length];
                noeud.position({ x: centre.x + a.x, y: centre.y + a.y });
            });

            return;
        }

        if (nouveaux.length === 1) {
            nouveaux[0].position({
                x: centre.x + Math.cos(base) * PAS_CHAINE_X,
                y: centre.y + Math.sin(base) * PAS_CHAINE_Y,
            });

            return;
        }

        // A partir de quatre, un eventail les serre trop : les cards se
        // toucheraient. On les empile en COLONNE dans l'axe du mouvement,
        // a un pas qui garantit un vrai intervalle entre deux cards.
        if (nouveaux.length >= 4) {
            const y0 = -((nouveaux.length - 1) * PAS_COLONNE) / 2;

            nouveaux.forEach((noeud, i) => {
                const oy = y0 + i * PAS_COLONNE;
                noeud.position({
                    x: centre.x + Math.cos(base) * PAS_CHAINE_X - Math.sin(base) * oy,
                    y: centre.y + Math.sin(base) * PAS_CHAINE_Y + Math.cos(base) * oy,
                });
            });

            return;
        }

        const ouverture = etroit() ? OUVERTURE_PROCHE * 1.4 : OUVERTURE_PROCHE;
        const depart = base - ouverture / 2;
        const pas = ouverture / (nouveaux.length - 1);

        nouveaux.forEach((noeud, i) => {
            const angle = depart + pas * i;
            noeud.position({
                x: centre.x + Math.cos(angle) * RX_PROCHE,
                y: centre.y + Math.sin(angle) * RY_PROCHE,
            });
        });
    }

    /** Une grille a droite de l'actif : des freres, lus comme tels. */
    function placerEnGrille(noeuds, centre, base = 0) {
        const colonnes = etroit() ? 2 : Math.min(4, Math.ceil(Math.sqrt(noeuds.length)));
        const pasX = etroit() ? 250 : 280;
        const pasY = PAS_COLONNE;
        const lignes = Math.ceil(noeuds.length / colonnes);
        const y0 = centre.y - ((lignes - 1) * pasY) / 2;

        noeuds.forEach((noeud, i) => {
            const col = i % colonnes;
            const ligne = Math.floor(i / colonnes);
            // La grille part dans l'axe du mouvement, puis s'etale.
            const ox = RX_PROCHE * 0.8 + col * pasX;
            const oy = (y0 - centre.y) + ligne * pasY;
            noeud.position({
                x: centre.x + Math.cos(base) * ox - Math.sin(base) * oy,
                y: centre.y + Math.sin(base) * ox + Math.cos(base) * oy,
            });
        });
    }

    // =====================================================================
    // L'exploration focalisee
    // =====================================================================

    /** Ce qui a deja ete revele reste visible, attenue : c'est le contexte. */
    const revelees = new Set();

    /**
     * Ce qui a deja une position sur la carte.
     *
     * La carte s'ETEND, elle ne se recompose pas : un noeud pose une fois ne
     * bouge plus. C'est ce qui permet a la personne de reconnaitre ou elle se
     * trouve d'un clic a l'autre, au lieu de voir le dessin se reorganiser
     * sous ses yeux a chaque fois.
     */
    const placees = new Set();

    /** La pile de navigation, pour que « Revenir » remonte vraiment (§Retour). */
    const pile = [];

    let actif = null;
    let mode = 'exploration';

    const sortants = (noeud, vue) => noeud.outgoers('edge').filter((e) => e.data('view') === vue || e.data('view') === 'both');

    /**
     * Le bord droit de tout ce qui est deja sur la carte.
     *
     * Une scene se pose APRES lui. La carte ne se recompose jamais : sans
     * cette borne, les nouvelles cards se seraient posees sur les anciennes —
     * mesure : 3 collisions sur la scene du moteur, 10 sur celle de Decision.
     */
    function bordDroitPlace() {
        const posees = cy.nodes().filter((n) => placees.has(n.id()));

        if (posees.empty()) {
            return 0;
        }

        return posees.boundingBox({ includeLabels: true }).x2;
    }

    /**
     * LA SCENE DU MOTEUR — huit etapes revelees d'un coup.
     *
     * ## Pourquoi elle existe
     *
     * Avec « un clic = un saut », atteindre un resultat demandait onze
     * interactions : intention, entree, puis les huit etapes une a une, puis
     * le resultat. Le Mermaid complet l'a rendu visible. Le contrat produit
     * est de 3 interactions, 4 au maximum.
     *
     * AUCUN concept n'est retire : les huit etapes sont toutes la, dans
     * l'ordre, et chacune garde son panneau explicatif au clic. Ce qui change
     * est la NAVIGATION — elles ne sont plus huit niveaux obligatoires.
     *
     * ## La disposition
     *
     * Un serpentin de trois colonnes : la lecture descend en zigzag, donc
     * l'ordre reste continu d'une rangee a l'autre. Une seule ligne de huit
     * cards ferait 2000 px de modele et retomberait au plancher de zoom.
     */
    function ouvrirSceneMoteur(entree) {
        const etapes = ORDRE_TRONC.map((id) => cy.getElementById(id)).filter((n) => n.nonempty());
        const base = entree.position();

        const colonnes = etroit() ? 2 : 4;
        const pasX = etroit() ? 186 : 236;
        const pasY = etroit() ? 92 : 118;
        const rangees = Math.ceil(etapes.length / colonnes);

        // En TERRAIN LIBRE, a droite de tout ce qui existe deja.
        const x0 = Math.max(base.x + PAS_CHAINE_X, bordDroitPlace() + 140);
        const y0 = base.y - ((rangees - 1) * pasY) / 2;

        cy.edges().removeClass('bp-retour');

        etapes.forEach((etape, i) => {
            etape.position({
                x: x0 + (i % colonnes) * pasX,
                y: y0 + Math.floor(i / colonnes) * pasY,
            });

            // Le lien qui CHANGE DE RANGEE : en bezier, il traversait la grille
            // en longue diagonale et se lisait comme une liaison parasite. Il
            // devient un coude — on descend, puis on repart.
            if (i > 0 && i % colonnes === 0) {
                etapes[i - 1].edgesTo(etape).addClass('bp-retour');
            }

            revelees.add(etape.id());
            placees.add(etape.id());
        });

        actif = entree;
        appliquerVisibilite();

        // On cadre LES ETAPES, pas l'entree : elle reste a gauche, en
        // contexte. L'inclure etirait la boite sur toute la distance qui les
        // separe — zoom 0,78 et libelles a 10,9 px, mesure.
        let scene = cy.collection();
        etapes.forEach((e) => { scene = scene.union(e); });

        focaliserScene(scene);
    }

    /**
     * LA SCENE DE CONVERGENCE — Decision et ses six resultats.
     *
     * Decision n'ouvre pas un niveau de plus : elle EST l'aboutissement. Les
     * six resultats apparaissent donc ensemble, trois au-dessus et trois en
     * dessous, Decision restant le centre visuel.
     *
     * Le leger dezoom est deliberé et borne : `facteur` reduit l'echelle sans
     * jamais passer sous le plancher de lisibilite, la ou un `fit()` global
     * l'aurait ecrasee.
     *
     * C'est la seule entorse assumee a « un seul voisin par clic », et elle
     * est justifiee : une convergence ne se parcourt pas, elle se constate.
     */
    function ouvrirSceneDecision(decision) {
        const resultats = cy.nodes('[kind = "outcome"]').toArray();
        const centre = decision.position();

        // Un EVENTAIL RADIAL, et ce n'est pas un choix esthetique.
        //
        // Deux colonnes de trois avaient ete essayees : les aretes vers la
        // seconde colonne TRAVERSAIENT les cards de la premiere — visible sur
        // la capture, et contraire au §4 (« les edges arrivent sur le bord de
        // la card, pas a travers le texte »).
        //
        // Sur un arc, chaque arete est un RAYON partant de Decision : deux
        // rayons ne se croisent jamais, et aucun ne traverse une card voisine
        // tant que l'arc est assez ouvert pour les espacer.
        //
        // Decision reste l'ancre : tout part d'elle.
        const n = resultats.length;
        const rx = etroit() ? 150 : 430;
        const ry = etroit() ? 300 : 280;
        const ouverture = etroit() ? Math.PI * 0.9 : Math.PI * 0.56;
        const depart = -ouverture / 2;
        const pas = n > 1 ? ouverture / (n - 1) : 0;

        resultats.forEach((resultat, i) => {
            const angle = depart + pas * i;

            resultat.position({
                x: centre.x + Math.cos(angle) * rx,
                y: centre.y + Math.sin(angle) * ry,
            });

            revelees.add(resultat.id());
            placees.add(resultat.id());
        });

        actif = decision;
        appliquerVisibilite();

        let ensemble = decision;
        resultats.forEach((r) => { ensemble = ensemble.union(r); });

        focaliserScene(ensemble, 0.95);
    }

    function entrerDans(noeud, { empiler = true } = {}) {
        mode = 'exploration';

        if (empiler) {
            empilerDepuis(noeud);
        }

        // Deux SCENES court-circuitent le pas a pas, et c'est ce qui tient le
        // contrat de 3 interactions : le tronc commun s'ouvre en entier apres
        // l'entree, et Decision revele ses six resultats d'un coup.
        if (noeud.data('kind') === 'entry') {
            revelees.add(noeud.id());
            ouvrirSceneMoteur(noeud);

            return;
        }

        if (noeud.id() === 'step:decision') {
            ouvrirSceneDecision(noeud);

            return;
        }

        const vue = 'detail';

        // Une etape du tronc deja revelee par la scene n'ouvre aucun niveau :
        // elle explique, elle ne ramifie pas. Sinon on rendrait a l'utilisateur
        // les huit paliers que la scene vient justement d'eviter.
        if (noeud.data('kind') === 'step') {
            actif = noeud;
            appliquerVisibilite();
            focaliser(noeud);

            return;
        }

        const proches = sortants(noeud, vue).targets();

        // UN SEUL SAUT — « les elements DIRECTEMENT lies apparaissent autour
        // de lui », dit l'addendum focus, et la mesure le confirme : avec un
        // second anneau, l'ecart maximal a l'actif passait a 705 px de modele
        // pour une demi-largeur utile de 464 (le panneau de detail occupe la
        // droite). L'echelle tombait donc au plancher, 0,78, et les libelles a
        // 11,7 px. Montrer un pas de plus coutait la lisibilite de tous.
        const parent = noeud.incomers('edge')
            .filter((e) => e.data('view') === vue || e.data('view') === 'both')
            .sources()
            .first();

        // Seuls les noeuds JAMAIS POSES sont places : tout ce qui est deja sur
        // la carte y reste, exactement ou la personne l'a vu la derniere fois.
        const nouveaux = proches.toArray().filter((n) => !placees.has(n.id()));

        [noeud, ...proches.toArray()].forEach((n) => revelees.add(n.id()));
        if (parent && parent.nonempty()) {
            revelees.add(parent.id());
        }

        actif = noeud;

        placerNouveaux(noeud, nouveaux, parent);
        nouveaux.forEach((n) => placees.add(n.id()));
        appliquerVisibilite();

        // Ce qu'on s'engage a garder dans le cadre : le chemin d'ou l'on
        // vient, et ce qui vient d'apparaitre.
        const essentiels = [noeud, ...proches.toArray()].map((n) => ({ noeud: n, repere: false }));
        if (parent && parent.nonempty()) {
            essentiels.push({ noeud: parent, repere: true });
        }

        // En colonne, centrer sur la RACINE laisserait toute la suite d'un
        // seul cote du cadre. L'ecran initial etroit se cadre donc comme une
        // scene : sur l'ensemble.
        if (etroit() && (!parent || parent.empty())) {
            let ensemble = noeud;
            proches.forEach((p) => { ensemble = ensemble.union(p); });
            focaliserScene(ensemble);

            return;
        }

        focaliser(noeud, essentiels);
    }

    /**
     * Qui est visible, qui est actif, qui n'est que du contexte.
     *
     * Rien n'est jamais retire de ce qui a ete revele : revenir en arriere ne
     * doit pas faire disparaitre le chemin parcouru.
     */
    function appliquerVisibilite() {
        cy.elements().addClass('bp-replie').removeClass('bp-actif bp-contexte');

        const visibles = cy.nodes().filter((n) => revelees.has(n.id()));
        visibles.removeClass('bp-replie');
        visibles.edgesWith(visibles)
            .filter((e) => e.data('view') === 'detail' || e.data('view') === 'both')
            .removeClass('bp-replie');

        if (!actif) {
            return;
        }

        const voisinage = actif.union(actif.neighborhood());
        visibles.not(voisinage).addClass('bp-contexte');
        cy.edges(':visible').not(actif.connectedEdges()).addClass('bp-contexte');

        actif.addClass('bp-actif');
        actif.connectedEdges(':visible').addClass('bp-actif').removeClass('bp-contexte');
    }

    // =====================================================================
    // La vue d'ensemble : un MODE distinct, pas un `fit` sur tout
    // =====================================================================

    /**
     * Une representation synthetique, en colonnes calculees.
     *
     * Elle ne cherche pas a montrer chaque noeud : les etapes du moteur sont
     * repliees en deux agregats par le payload, et les Boucles ne sont PAS
     * deployees — seul leur point d'entree figure. C'est ce qui permet de
     * garder un texte lisible au lieu des 4 px mesures avant correctif.
     */
    function vueDEnsemble() {
        mode = 'synthese';
        actif = null;
        pile.length = 0;

        // Un FLUX VERTICAL, l'orientation du schema de MASTER : les quatre
        // portes en haut, le moteur qui descend, les resultats en eventail.
        //
        // La version en COLONNES a ete mesuree et rejetee : 7 colonnes de
        // 330 px faisaient 1677 px d'emprise dans un cadre de 1360, donc un
        // zoom au plancher (0,78) et des libelles a 11,7 px. Ici la dimension
        // large porte les 6 resultats une seule fois, pas sept colonnes.
        const outcomes = cy.nodes('[kind = "outcome"]').toArray();

        // QUATRE BANDES, et des positions posees.
        //
        // Une colonne par etape — la version precedente — faisait 1677 px
        // d'emprise pour un cadre de 1360 : echelle au plancher et libelles a
        // 11,7 px. Un empilement vertical du tronc, lui, faisait 916 px de
        // haut pour 660. Les bandes repartissent sur les DEUX axes, et c'est
        // ce qui permet de tenir a echelle lisible.
        const bandes = [
            {
                y: 0,
                pas: 268,
                noeuds: cy.nodes('[kind = "intent"]').union(cy.getElementById('loops')).toArray(),
            },
            {
                y: etroit() ? 210 : 200,
                pas: 252,
                noeuds: [
                    cy.getElementById('step:clarify'),
                    cy.getElementById('step:match'),
                    cy.getElementById('step:exchange'),
                    cy.getElementById('agg:engine'),
                    cy.getElementById('agg:decide'),
                ],
            },
            { y: etroit() ? 370 : 355, pas: 250, noeuds: outcomes.slice(0, 3) },
            { y: etroit() ? 490 : 470, pas: 250, noeuds: outcomes.slice(3) },
        ];

        bandes.forEach((bande) => {
            const n = bande.noeuds.length;
            const x0 = -((n - 1) * bande.pas) / 2;
            bande.noeuds.forEach((noeud, j) => {
                noeud.position({ x: x0 + j * bande.pas, y: bande.y });
            });
        });

        const montres = cy.nodes('[kind = "intent"], [kind = "outcome"], [kind = "aggregate"], [kind = "explore"]')
            .union(cy.getElementById('step:clarify'))
            .union(cy.getElementById('step:match'))
            .union(cy.getElementById('step:exchange'));

        cy.elements().addClass('bp-replie').removeClass('bp-actif bp-contexte bp-synthese');
        montres.removeClass('bp-replie').addClass('bp-synthese');
        montres.edgesWith(montres)
            .filter((e) => e.data('view') === 'overview' || e.data('view') === 'both')
            .removeClass('bp-replie');

        // Plancher plus haut qu'en exploration : cette vue doit se lire d'un
        // coup d'oeil, donc aucun libelle sous ~14 px.
        ajusterLisible(montres, 50, ZOOM_MIN_SYNTHESE);
    }

    /**
     * Un ajustement qui refuse de rendre le texte illisible.
     *
     * Si le contenu ne tient pas a l'echelle minimale, on cadre a cette
     * echelle et on laisse la personne se deplacer — la lecture prime sur
     * l'exhaustivite, c'est le contrat pose par MASTER.
     */
    function ajusterLisible(elements, padding = 70, plancher = ZOOM_MIN_LISIBLE) {
        const cadre = racine.getBoundingClientRect();
        const bb = elements.boundingBox();
        const echelle = Math.min(
            (cadre.width - padding * 2) / Math.max(bb.w, 1),
            (cadre.height - padding * 2) / Math.max(bb.h, 1),
        );
        const z = Math.min(ZOOM_MAX, Math.max(plancher, echelle));
        const centre = zoneUtile();
        const milieu = { x: bb.x1 + bb.w / 2, y: bb.y1 + bb.h / 2 };

        cy.animate(
            { zoom: z, pan: { x: centre.x - milieu.x * z, y: centre.y - milieu.y * z } },
            { duration: 420, easing: 'ease-in-out-cubic' },
        );
    }

    // =====================================================================
    // Panneau de detail (§10) — a cote du graphe, jamais devant
    // =====================================================================

    const champ = (nom) => document.querySelector(`[data-flowchart-panel-${nom}]`);

    function fermerPanneau() {
        if (panneau) {
            panneau.hidden = true;
        }
    }

    function ouvrirPanneau(noeud) {
        if (!panneau) {
            return;
        }

        const d = noeud.data();

        // Pour une Boucle : type ET statut, comme sur le noeud. Le type vient
        // du `LoopTypeRegistry` cote serveur — rien n'est devine ici.
        champ('kind').textContent = d.kind === 'loop'
            ? [d.type_label, d.access_label].filter(Boolean).join(' · ')
            : (d.access_label || '');

        champ('title').textContent = d.name || d.label || '';

        // La tagline de la Boucle d'abord — elle est ecrite pour elle ; a
        // defaut la description de son TYPE, qui explique au moins de quoi il
        // s'agit. Aucun contenu n'est invente.
        champ('body').textContent = d.kind === 'loop'
            ? (d.tagline || d.type_description || '')
            : (d.hint || d.tagline || '');

        champ('meta').textContent = d.kind === 'loop' && d.members ? `${d.members}` : '';

        const cta = champ('cta');

        // §10 des correctifs : la bascule passe par une CLASSE, jamais par
        // l'attribut `hidden` seul. Tailwind ecrit `[hidden]{display:none}`
        // dans preflight, donc AVANT les utilitaires : a specificite egale,
        // `.inline-flex` gagnait et la pastille vide restait a l'ecran.
        if (d.kind === 'loop' && d.url) {
            cta.href = d.url;
            cta.textContent = d.cta_label || '';
            cta.classList.remove('bp-invisible');
        } else {
            cta.classList.add('bp-invisible');
            cta.removeAttribute('href');
            cta.textContent = '';
        }

        panneau.hidden = false;
    }

    // =====================================================================
    // Gestes
    // =====================================================================

    cy.on('tap', 'node', (evenement) => {
        const noeud = evenement.target;
        const genre = noeud.data('kind');

        ouvrirPanneau(noeud);

        // Une Boucle est une destination, pas un embranchement : on la centre
        // et on montre sa fiche, ses soeurs restant en contexte autour.
        if (genre === 'loop') {
            empilerDepuis(noeud);
            actif = noeud;
            appliquerVisibilite();
            focaliser(noeud);

            return;
        }

        // Un DEBOUCHE remplit la zone de cards sous le graphe. Il ne deplie
        // rien : ce qu'il mene a voir n'est pas dans le graphe.
        if (genre === 'outlet') {
            empilerDepuis(noeud);
            actif = noeud;
            appliquerVisibilite();
            focaliser(noeud);
            montrerEchanges(noeud.data('outlet'));

            return;
        }

        entrerDans(noeud);
    });

    function empilerDepuis(noeud) {
        if (actif && actif.id() !== noeud.id()) {
            pile.push(actif.id());
        }
    }

    // Survol : l'etat est porte par une classe, comme les autres, plutot que
    // par un style inline — un seul endroit decide de l'apparence.
    cy.on('mouseover', 'node', (e) => {
        e.target.addClass('bp-survol');
        racine.style.cursor = 'pointer';
    });

    cy.on('mouseout', 'node', (e) => {
        e.target.removeClass('bp-survol');
        racine.style.cursor = '';
    });

    cy.on('tap', (evenement) => {
        if (evenement.target === cy) {
            fermerPanneau();

            if (actif) {
                focaliser(actif);
            }
        }
    });

    champ('close')?.addEventListener('click', () => {
        fermerPanneau();
        if (actif) {
            focaliser(actif);
        }
    });

    /**
     * Ouvre la zone de cards sous le graphe.
     *
     * Elle est RENDUE PAR LARAVEL, pas fabriquee ici : les Propositions et les
     * Demandes n'atteignent le navigateur que si le serveur a juge la personne
     * membre de cette Organization. Le JavaScript ne fait que devoiler ce qui
     * lui a ete donne — s'il n'y a rien, il n'y a rien a devoiler.
     */
    function montrerEchanges(quoi) {
        document.querySelectorAll('[data-flowchart-echanges]').forEach((zone) => {
            zone.hidden = zone.dataset.flowchartEchanges !== quoi;
        });

        document.querySelector(`[data-flowchart-echanges="${quoi}"]`)
            ?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function cacherEchanges() {
        document.querySelectorAll('[data-flowchart-echanges]').forEach((zone) => {
            zone.hidden = true;
        });
    }

    // --- Barre d'outils (§8) ------------------------------------------
    const surClic = (selecteur, action) => document.querySelector(selecteur)?.addEventListener('click', action);

    surClic('[data-flowchart-zoom-in]', () => zoomer(1.25));
    surClic('[data-flowchart-zoom-out]', () => zoomer(0.8));
    surClic('[data-flowchart-recenter]', () => (actif ? focaliser(actif) : entrerDans(root, { empiler: false })));
    surClic('[data-flowchart-fit]', () => ajusterLisible(cy.elements(':visible')));

    function zoomer(facteur) {
        const centre = zoneUtile();
        cy.animate(
            { zoom: { level: Math.min(ZOOM_MAX, Math.max(0.2, cy.zoom() * facteur)), renderedPosition: centre } },
            { duration: 220 },
        );
    }

    surClic('[data-flowchart-overview]', () => {
        fermerPanneau();
        vueDEnsemble();
    });

    // « Revenir » remonte d'un cran ET recentre sur le parent (§Retour).
    surClic('[data-flowchart-back]', () => {
        fermerPanneau();

        if (mode === 'synthese') {
            reinitialiser();

            return;
        }

        const precedent = pile.pop();

        if (!precedent) {
            reinitialiser();

            return;
        }

        entrerDans(cy.getElementById(precedent), { empiler: false });
    });

    surClic('[data-flowchart-reset]', () => {
        fermerPanneau();
        reinitialiser();
    });

    function reinitialiser() {
        cacherEchanges();
        revelees.clear();
        placees.clear();
        pile.length = 0;

        // La racine est l'origine du repere. Sans cela, Cytoscape lui donne
        // une position arbitraire et toute la carte se construit a partir
        // d'un point inconnu.
        root.position({ x: 0, y: 0 });
        placees.add(root.id());
        actif = null;
        mode = 'exploration';
        entrerDans(root, { empiler: false });
    }

    // La carte se recompose quand la place change (rotation, redimensionnement).
    let minuterie;
    window.addEventListener('resize', () => {
        clearTimeout(minuterie);
        minuterie = setTimeout(() => {
            cy.resize();

            if (mode === 'synthese') {
                vueDEnsemble();
            } else if (actif) {
                entrerDans(actif, { empiler: false });
            }
        }, 200);
    });

    // =====================================================================
    // Le theme change SANS rechargement
    // =====================================================================
    //
    // AUDIT du mecanisme existant, avant d'en inventer un :
    //
    // - `Alpine.store('visualTheme').apply()` (`resources/js/app.js:64`) ecrit
    //   `document.documentElement.dataset.bpTheme` et `localStorage.bpTheme` ;
    // - `Alpine.store('darkMode').toggle()` (`:37`) bascule la classe `dark`
    //   sur le meme element.
    //
    // NI l'un NI l'autre n'emet d'evenement JavaScript. Il n'y a donc rien a
    // ecouter, et un observateur d'attributs est la seule lecture fidele —
    // sans scrutation, comme demande.
    //
    // Les DEUX attributs sont surveilles, et ce n'est pas un exces : les
    // jetons different entre `[data-bp-theme="x"]` et `.dark[data-bp-theme="x"]`
    // (`components/theme-tokens.blade.php`). N'observer que le theme aurait
    // laisse le graphe aux couleurs claires sur un passage en sombre.
    const observateurTheme = new MutationObserver(() => {
        // `requestAnimationFrame` : l'attribut change AVANT que le navigateur
        // ait recalcule les variables CSS. Relire tout de suite rendrait les
        // anciennes valeurs.
        requestAnimationFrame(appliquerTheme);
    });

    observateurTheme.observe(document.documentElement, {
        attributes: true,
        attributeFilter: ['data-bp-theme', 'class'],
    });

    // Poignee de RECETTE, posee sur l'element plutot que sur `window` : elle
    // ne pollue aucun espace de noms global. Elle sert a mesurer l'etat reel
    // du graphe depuis Playwright (noeuds visibles, echelle, positions
    // rendues) — une capture d'ecran ne prouve pas qu'une branche s'est
    // depliee. Aucune logique produit n'en depend.
    racine.__bpFlowchart = cy;

    // Entree en scene : la question au centre, ses portes autour.
    reinitialiser();
}

// Le demarrage vient EN DERNIER, et ce n'est pas cosmetique : `demarrer()`
// rend le graphe synchroniquement, donc lit `CHEMINS` tout de suite. Place
// plus haut, l'appel tombait dans la zone morte temporelle de ce `const` —
// « Cannot read properties of undefined (reading 'need_help') », mesure en
// console. Les `function` se hissent, les `const` non.
if (racine && source) {
    demarrer(racine, JSON.parse(source.textContent));
}
