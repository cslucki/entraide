/**
 * TASK-1702 — le pont entre un pop-up de l'editeur et la Card qu'il contient.
 *
 * ## Le defaut que ce fichier repare
 *
 * TASK-1085 a sorti quatre Cards — Boucle, Dossier, Taches, Co-ecriture — de la
 * colonne laterale de l'editeur d'article pour les mettre dans des pop-ups, en
 * gardant « leur etat Alpine et tout leur comportement : seul leur contenant
 * change ». C'etait le bon principe, et il manquait une chose : le
 * DECLENCHEUR.
 *
 * Leur ancien bouton de repli est reste dans le balisage, en `x-show="false"` :
 * plus personne ne clique dessus, donc plus personne n'appelle `toggle()`. Or
 * `toggle()` ne faisait pas qu'afficher le corps de la Card — c'est lui qui
 * CHARGE les donnees et demarre les sondages (`loadMessages`, `loadCurrent`,
 * `loadDossiers`, `loadTodos`). Le bouton de la barre ouvrait donc bien le
 * pop-up, mais sur une Card restee fermee : un cadre, un titre, et rien dedans.
 *
 * ## Pourquoi un module a part, et pas quelques lignes dans `app.js`
 *
 * Parce que cette logique est la correction elle-meme, et qu'elle doit pouvoir
 * etre mise a l'epreuve sans navigateur ni session. `app.js` est un monolithe
 * qui demarre toute l'application ; rien de ce qu'il contient n'est
 * testable isolement. Ici, `tests/js/editor-panneaux.test.mjs` l'exerce
 * directement, comme `tiptap/markdown-paste.js` l'est deja.
 */

/** Le nom de l'evenement par lequel le store annonce le panneau ouvert. */
export const EVENEMENT_PANNEAU = 'editor-panel-changed';

/**
 * Annonce quel panneau est ouvert, ou `null` si aucun.
 *
 * @param {string|null} ouvert
 * @param {EventTarget} cible  Le `window` du document, ou un double en test.
 */
export function annoncerLePanneau(ouvert, cible = globalThis) {
    cible.dispatchEvent(new CustomEvent(EVENEMENT_PANNEAU, { detail: { open: ouvert } }));
}

/**
 * Branche une Card sur le pop-up qui la contient.
 *
 * Ne reecrit AUCUNE Card : appelle leur `toggle()` existant quand le store
 * ouvre ou ferme leur panneau. Tout ce que `toggle()` faisait — le chargement,
 * le sondage, l'evenement `close-other-sidebar-cards` — se remet a se produire
 * exactement comme avant TASK-1085.
 *
 * La comparaison tient les DEUX sens, ouverture et fermeture. Sans le second,
 * les sondages des Cards Boucle et Taches continueraient de tourner pop-up
 * ferme — une requete toutes les quelques secondes pour un panneau que
 * personne ne regarde.
 *
 * @param {{open: boolean, toggle: Function}} carte
 * @param {string} nom      Le nom du panneau : 'boucle', 'dossier', 'todo', 'coauthors'.
 * @param {EventTarget} cible
 * @returns {Function}      De quoi se debrancher, utile aux tests.
 */
export function brancherCarteSurSonPanneau(carte, nom, cible = globalThis) {
    const ecouteur = (evenement) => {
        if ((evenement.detail?.open === nom) !== carte.open) {
            carte.toggle();
        }
    };

    cible.addEventListener(EVENEMENT_PANNEAU, ecouteur);

    return () => cible.removeEventListener(EVENEMENT_PANNEAU, ecouteur);
}
