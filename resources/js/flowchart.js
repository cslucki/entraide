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
 * ## Les couleurs sortent du theme de l'Organization
 *
 * Lues dans les jetons `--bp-*` poses par `<x-theme-tokens>`. Changer le theme
 * d'une Organization change la carte, sans une ligne de JavaScript.
 */

const racine = document.querySelector('[data-flowchart-canvas]');
const source = document.querySelector('[data-flowchart-graph]');

if (racine && source) {
    demarrer(racine, JSON.parse(source.textContent));
}

/** Un jeton de theme, avec un repli explicite : un `var()` vide serait invisible. */
function jeton(nom, repli) {
    const valeur = getComputedStyle(document.documentElement).getPropertyValue(nom).trim();

    return valeur || repli;
}

function demarrer(racine, graph) {
    const couleurs = {
        page: jeton('--bp-page', '#0f172a'),
        surface: jeton('--bp-surface', '#1e293b'),
        primary: jeton('--bp-primary', '#1B1FCC'),
        primaryDeep: jeton('--bp-primary-deep', '#141799'),
        accent: jeton('--bp-accent', '#7c3aed'),
        validation: jeton('--bp-validation', '#16a34a'),
        info: jeton('--bp-info', '#0284c7'),
        text: jeton('--bp-text', '#e2e8f0'),
        muted: jeton('--bp-muted', '#94a3b8'),
        border: jeton('--bp-border', '#334155'),
    };

    // La couleur porte le SENS, pas la decoration : une porte d'entree, une
    // etape du mecanisme, un resultat, une Boucle reelle.
    const parGenre = {
        root: couleurs.primaryDeep,
        intent: couleurs.primary,
        step: couleurs.info,
        outcome: couleurs.validation,
        explore: couleurs.accent,
        loop: couleurs.accent,
    };

    const cy = cytoscape({
        container: racine,
        elements: { nodes: graph.nodes, edges: graph.edges },
        // §21 : la carte s'explore, elle ne s'edite pas.
        autoungrabify: true,
        boxSelectionEnabled: false,
        minZoom: 0.2,
        maxZoom: 2.5,
        style: [
            {
                selector: 'node',
                style: {
                    'background-color': (n) => parGenre[n.data('kind')] || couleurs.muted,
                    label: 'data(label)',
                    color: couleurs.text,
                    'font-size': 11,
                    'font-weight': 600,
                    'text-valign': 'bottom',
                    'text-margin-y': 6,
                    'text-wrap': 'wrap',
                    'text-max-width': 130,
                    'text-outline-width': 3,
                    'text-outline-color': couleurs.page,
                    width: 26,
                    height: 26,
                    'border-width': 0,
                    'transition-property': 'background-color, width, height, opacity, border-width',
                    'transition-duration': 220,
                },
            },
            { selector: 'node[kind = "root"]', style: { width: 54, height: 54, 'font-size': 13 } },
            { selector: 'node[kind = "intent"]', style: { width: 38, height: 38, 'font-size': 12 } },
            { selector: 'node[kind = "explore"]', style: { width: 38, height: 38, 'font-size': 12, shape: 'round-diamond' } },
            { selector: 'node[kind = "loop"]', style: { shape: 'round-rectangle', width: 30, height: 22 } },
            { selector: 'node[kind = "outcome"]', style: { shape: 'round-hexagon' } },
            {
                selector: 'edge',
                style: {
                    width: 1.5,
                    'line-color': couleurs.border,
                    'target-arrow-color': couleurs.border,
                    'target-arrow-shape': 'triangle',
                    'arrow-scale': 0.7,
                    'curve-style': 'bezier',
                    opacity: 0.55,
                    'transition-property': 'line-color, opacity, width',
                    'transition-duration': 220,
                },
            },
            // Le chemin actif : mis en evidence, le reste attenue (§9).
            {
                selector: '.bp-actif',
                style: { 'border-width': 3, 'border-color': couleurs.primary, opacity: 1 },
            },
            {
                selector: 'edge.bp-actif',
                style: { 'line-color': couleurs.primary, 'target-arrow-color': couleurs.primary, width: 2.5, opacity: 1 },
            },
            { selector: '.bp-attenue', style: { opacity: 0.18 } },
            { selector: '.bp-replie', style: { display: 'none' } },
        ],
    });

    // =====================================================================
    // Revelation progressive — §3 : simple au depart, detail a la demande
    // =====================================================================

    /** Ce qui est visible au chargement : le point d'entree et ses portes. */
    const premierNiveau = cy.nodes().filter((n) => {
        const genre = n.data('kind');

        return genre === 'root' || genre === 'intent' || genre === 'explore';
    });

    let deplie = null;

    function replierTout() {
        cy.elements().removeClass('bp-actif bp-attenue');
        cy.elements().addClass('bp-replie');
        premierNiveau.removeClass('bp-replie');
        aretesEntre(premierNiveau).removeClass('bp-replie');
        deplie = null;
        fermerPanneau();
    }

    /** Les aretes dont les DEUX extremites sont dans l'ensemble donne. */
    function aretesEntre(noeuds) {
        return noeuds.edgesWith(noeuds);
    }

    /**
     * Tout ce qui descend d'un noeud, sans jamais remonter.
     *
     * `successors()` suit les aretes sortantes : la chaine du mecanisme puis
     * les resultats pour une intention, les Boucles pour le noeud d'exploration.
     */
    function branche(noeud) {
        return noeud.union(noeud.successors());
    }

    function deplier(noeud) {
        const ensemble = branche(noeud).union(premierNiveau);

        cy.elements().addClass('bp-replie');
        ensemble.removeClass('bp-replie');
        aretesEntre(ensemble).removeClass('bp-replie');

        const actif = branche(noeud);
        cy.elements().removeClass('bp-actif').addClass('bp-attenue');
        actif.union(aretesEntre(actif)).removeClass('bp-attenue').addClass('bp-actif');

        deplie = noeud;

        disposer(ensemble.not('.bp-replie'), noeud);
    }

    /** Une mise en page animee, recentree sur le noeud choisi (§9). */
    function disposer(visibles, centre) {
        const etroit = racine.clientWidth < 720;

        visibles
            .layout({
                name: 'breadthfirst',
                directed: true,
                roots: cy.getElementById('root'),
                padding: 24,
                spacingFactor: etroit ? 0.9 : 1.15,
                // §14 : vertical sur mobile, horizontal quand la place existe.
                transform: etroit ? undefined : (node, pos) => ({ x: pos.y, y: pos.x }),
                animate: true,
                animationDuration: 420,
                fit: true,
                stop: () => {
                    if (centre) {
                        cy.animate({ center: { eles: branche(centre) }, fit: { eles: branche(centre), padding: 48 } }, { duration: 380 });
                    }
                },
            })
            .run();
    }

    // =====================================================================
    // Panneau de detail (§10) — le graphe reste simple, la fiche vit a cote
    // =====================================================================

    const panneau = document.querySelector('[data-flowchart-panel]');
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

        champ('kind').textContent = d.access_label || '';
        champ('title').textContent = d.label || '';
        champ('body').textContent = d.hint || d.tagline || '';
        champ('meta').textContent = d.kind === 'loop' && d.members ? `${d.members}` : '';

        const cta = champ('cta');
        if (d.kind === 'loop' && d.url) {
            cta.href = d.url;
            cta.textContent = d.cta_label || '';
            cta.hidden = false;
        } else {
            cta.hidden = true;
            cta.removeAttribute('href');
        }

        panneau.hidden = false;
    }

    // =====================================================================
    // Gestes
    // =====================================================================

    cy.on('tap', 'node', (evenement) => {
        const noeud = evenement.target;
        const genre = noeud.data('kind');

        if (genre === 'intent' || genre === 'explore') {
            // Re-cliquer la branche ouverte la referme : le geste est reversible.
            if (deplie && deplie.id() === noeud.id()) {
                replierTout();
                disposer(premierNiveau, null);

                return;
            }

            deplier(noeud);
        }

        ouvrirPanneau(noeud);
    });

    cy.on('tap', (evenement) => {
        if (evenement.target === cy) {
            fermerPanneau();
        }
    });

    document.querySelector('[data-flowchart-overview]')?.addEventListener('click', () => {
        cy.elements().removeClass('bp-replie bp-attenue bp-actif');
        disposer(cy.elements(), null);
        fermerPanneau();
    });

    document.querySelector('[data-flowchart-back]')?.addEventListener('click', () => {
        replierTout();
        disposer(premierNiveau, null);
    });

    document.querySelector('[data-flowchart-reset]')?.addEventListener('click', () => {
        replierTout();
        disposer(premierNiveau, null);
        cy.zoom(1);
        cy.center();
    });

    // La carte se recompose quand la place change (rotation, redimensionnement).
    let minuterie;
    window.addEventListener('resize', () => {
        clearTimeout(minuterie);
        minuterie = setTimeout(() => {
            disposer(cy.elements().not('.bp-replie'), deplie);
        }, 180);
    });

    // Entree en scene : le premier niveau, et lui seul.
    replierTout();
    disposer(premierNiveau, null);
}
