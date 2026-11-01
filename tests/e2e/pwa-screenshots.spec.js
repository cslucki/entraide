// TASK-1737 — le GENERATEUR des captures du manifest.
//
// ## Pourquoi un fichier versionne et pas un script jetable
//
// Le CDC (section 3.7) exige des captures « veridiques ». Une capture faite a
// la main vieillit en silence : l'interface change, le manifest montre encore
// l'ancienne. Ici la commande est dans le depot, relisible en revue, et
// rejouable le jour ou l'ecran bouge.
//
// ## Ce qu'elles ne montrent JAMAIS : des donnees de personnes
//
// La base servie en local est `bouclepro_prod_work`, une copie de travail
// issue de la baseline PROD : elle porte des donnees realistes de production.
// Un fichier de `public/` est PUBLIC, et il est commite.
//
// Les captures ne visent donc que des surfaces ANONYMES :
//
//   - `/org/main`  : la vitrine publique, deja servie a tout visiteur ;
//   - `/about`     : la page institutionnelle publique.
//
// Aucune connexion, aucun identifiant de test, aucune page authentifiee.
// `/boucles` a ete ECARTEE bien qu'elle reponde 200 : elle nomme des Boucles,
// et la copie locale peut en contenir que la production n'expose pas encore.
//
// Usage :
//   npx playwright test tests/e2e/pwa-screenshots.spec.js
//
// Les fichiers produits vont dans `public/brand/screenshots/` et sont commites.

import { test, expect } from '@playwright/test';
import fs from 'fs';
import path from 'path';

// `form_factor: narrow` du manifest. 1080x1920 est la definition d'un
// telephone courant ; le viewport est en points, le facteur d'echelle fait le
// reste.
const LARGEUR = 540;
const HAUTEUR = 960;
const ECHELLE = 2;

const DOSSIER = path.resolve('public/brand/screenshots');

// JPEG et non PNG : mesure du 31/10/2026, les memes captures en PNG
// pesaient 739 ko et 1,17 Mo. Un manifest est telecharge avant la moindre
// invite d'installation ; on ne lui fait pas porter deux megaoctets pour des
// vignettes affichees en petit. Qualite 82 : aucune difference visible a
// l'oeil, un ordre de grandeur de moins sur le disque.
const PAGES = [
    { chemin: '/org/main', fichier: 'phone-accueil.jpg', label: 'La page d\'accueil de BouclePro' },
    { chemin: '/about', fichier: 'phone-a-propos.jpg', label: 'A propos de BouclePro' },
];

test.describe('TASK-1737 captures du manifest', () => {
    test.use({
        viewport: { width: LARGEUR, height: HAUTEUR },
        deviceScaleFactor: ECHELLE,
        isMobile: true,
        hasTouch: true,
    });

    for (const { chemin, fichier } of PAGES) {
        test(`capture ${fichier}`, async ({ page }) => {
            fs.mkdirSync(DOSSIER, { recursive: true });

            // La capture doit montrer la PAGE, pas l'invitation a installer :
            // le cookie de renoncement l'eteint proprement, par le chemin que
            // l'application utilise vraiment.
            await page.context().addCookies([{
                name: 'bp_pwa_dismissed',
                value: '1',
                domain: new URL(page.context()._options?.baseURL || 'https://test.laravel').hostname,
                path: '/',
            }]);

            await page.goto(chemin, { waitUntil: 'networkidle' });

            // Garde : une capture d'une page en erreur serait pire que pas de
            // capture du tout — le manifest montrerait une panne.
            await expect(page.locator('body')).toBeVisible();

            const destination = path.join(DOSSIER, fichier);
            await page.screenshot({ path: destination, fullPage: false, type: 'jpeg', quality: 82 });

            const taille = fs.statSync(destination).size;
            expect(taille).toBeGreaterThan(10_000);
            // Garde de poids : une capture qui enfle finirait dans le manifest.
            expect(taille).toBeLessThan(400_000);
            console.log(`  ${fichier} : ${taille} octets`);
        });
    }
});
