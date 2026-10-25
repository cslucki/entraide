/**
 * TASK-1702 — le pont entre un pop-up de l'editeur et la Card qu'il contient.
 *
 * Le defaut mesure : les quatre boutons de la barre — Boucle, Dossier, Liste de
 * taches, Co-ecriture — ouvraient un pop-up VIDE. Le store gouvernait
 * l'enveloppe, mais les Cards gardaient leur propre `open` a `false`, et leur
 * `toggle()` — celui qui CHARGE les donnees et demarre les sondages — n'etait
 * plus appele par personne depuis que TASK-1085 avait masque leur ancien bouton
 * de repli (`x-show="false"`).
 *
 * Ce fichier exerce la correction sans navigateur ni session : c'est pour cela
 * que le pont a ete sorti de `app.js`, qui n'est pas importable.
 *
 * Lancer avec : node --test tests/js/editor-panneaux.test.mjs
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    EVENEMENT_PANNEAU,
    annoncerLePanneau,
    brancherCarteSurSonPanneau,
} from '../../resources/js/editor/panneaux.js';

/**
 * Une Card reduite a ce que le pont touche : son `open` et son `toggle()`.
 *
 * `toggle()` compte ses appels ET bascule `open`, comme les vraies : un double
 * qui ne basculerait pas laisserait passer une correction qui ouvre deux fois
 * de suite.
 */
function carteDouble() {
    return {
        open: false,
        appels: 0,
        toggle() {
            this.open = !this.open;
            this.appels += 1;
        },
    };
}

/**
 * Un `window` de substitution, pour ne dependre d'aucun global.
 *
 * `EventTarget` et `CustomEvent` sont globaux depuis Node 18 : rien a importer,
 * et surtout rien a simuler — c'est la MEME implementation que le navigateur
 * expose, pas un double maison.
 */
function fenetre() {
    return new EventTarget();
}

test('le bouton de la barre OUVRE reellement la Card', () => {
    const w = fenetre();
    const carte = carteDouble();
    brancherCarteSurSonPanneau(carte, 'boucle', w);

    annoncerLePanneau('boucle', w);

    assert.equal(carte.open, true, 'la Card est restee fermee : le pop-up serait vide');
    assert.equal(carte.appels, 1, 'toggle() doit etre appele UNE fois — c\'est lui qui charge les donnees');
});

test('refermer le pop-up referme la Card', () => {
    // Sans ce sens-la, les sondages des Cards Boucle et Taches continueraient
    // de tourner pop-up ferme : une requete toutes les quelques secondes pour
    // un panneau que personne ne regarde.
    const w = fenetre();
    const carte = carteDouble();
    brancherCarteSurSonPanneau(carte, 'boucle', w);

    annoncerLePanneau('boucle', w);
    annoncerLePanneau(null, w);

    assert.equal(carte.open, false);
    assert.equal(carte.appels, 2);
});

test('une Card ne reagit QUE a son propre panneau', () => {
    // Les quatre Cards ecoutent le meme evenement. Sans la comparaison de nom,
    // ouvrir « Dossier » chargerait aussi les Boucles et les taches.
    const w = fenetre();
    const boucle = carteDouble();
    const dossier = carteDouble();
    brancherCarteSurSonPanneau(boucle, 'boucle', w);
    brancherCarteSurSonPanneau(dossier, 'dossier', w);

    annoncerLePanneau('dossier', w);

    assert.equal(dossier.open, true);
    assert.equal(boucle.open, false, 'la Card Boucle a reagi au panneau d\'une autre');
    assert.equal(boucle.appels, 0);
});

test('passer d\'un panneau a l\'autre ferme le premier et ouvre le second', () => {
    const w = fenetre();
    const boucle = carteDouble();
    const dossier = carteDouble();
    brancherCarteSurSonPanneau(boucle, 'boucle', w);
    brancherCarteSurSonPanneau(dossier, 'dossier', w);

    annoncerLePanneau('boucle', w);
    annoncerLePanneau('dossier', w);

    assert.equal(boucle.open, false, 'la Card precedente est restee ouverte');
    assert.equal(dossier.open, true);
});

test('une annonce repetee ne rebascule pas la Card', () => {
    // Le point le plus delicat : la comparaison porte sur l'ETAT, pas sur
    // l'evenement. Un `toggle()` appele deux fois de suite refermerait la Card
    // et le pop-up redeviendrait vide — le defaut d'origine, par un autre
    // chemin.
    const w = fenetre();
    const carte = carteDouble();
    brancherCarteSurSonPanneau(carte, 'boucle', w);

    annoncerLePanneau('boucle', w);
    annoncerLePanneau('boucle', w);
    annoncerLePanneau('boucle', w);

    assert.equal(carte.open, true);
    assert.equal(carte.appels, 1, 'toggle() a ete rappele pour rien : la Card se serait refermee');
});

test('une Card deja ouverte que l\'on ferme ailleurs se resynchronise', () => {
    // `close-other-sidebar-cards` met `open` a false SANS passer par
    // `toggle()`. Le pont doit donc se fier a l'etat courant de la Card, et
    // pas a ce qu'il croit avoir fait.
    const w = fenetre();
    const carte = carteDouble();
    brancherCarteSurSonPanneau(carte, 'boucle', w);

    annoncerLePanneau('boucle', w);
    carte.open = false; // comme le ferait l'autre ecouteur
    annoncerLePanneau('boucle', w);

    assert.equal(carte.open, true, 'la Card ne s\'est pas rouverte apres une fermeture laterale');
});

test('se debrancher coupe reellement l\'ecoute', () => {
    const w = fenetre();
    const carte = carteDouble();
    const debrancher = brancherCarteSurSonPanneau(carte, 'boucle', w);

    debrancher();
    annoncerLePanneau('boucle', w);

    assert.equal(carte.appels, 0);
});

test('le nom de l\'evenement est celui que le store emet', () => {
    // Garde de non-regression du CONTRAT : store et pont doivent nommer la
    // meme chose. Les separer en deux chaines litterales a deux endroits est
    // precisement ce qui a casse ce mecanisme une premiere fois.
    const w = fenetre();
    let recu = null;
    w.addEventListener(EVENEMENT_PANNEAU, (e) => { recu = e.detail; });

    annoncerLePanneau('todo', w);

    assert.deepEqual(recu, { open: 'todo' });
});
