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

// La langue du manifest. Lue ICI plutot qu'ecrite en dur : c'est elle qui
// doit gouverner la capture, et les deux ne peuvent plus diverger.
//
// TASK-1805 — `/site.webmanifest` est desormais une route Laravel
// (`PwaManifestController`), plus un fichier statique de `public/` : seul
// `name`/`short_name` y varient par environnement, `lang` reste fixe et vit
// dans le GABARIT (`resources/pwa/site-webmanifest-template.json`), lu ici
// directement plutot que de dependre d'un serveur deja demarre.
const LANGUE = JSON.parse(fs.readFileSync(path.resolve('resources/pwa/site-webmanifest-template.json'), 'utf8')).lang;

test.describe('TASK-1737 captures du manifest', () => {
    test.use({
        viewport: { width: LARGEUR, height: HAUTEUR },
        deviceScaleFactor: ECHELLE,
        isMobile: true,
        hasTouch: true,

        // TASK-1745 (verification du point 4, 03/11) — la LOCALE, et c'est un
        // defaut constate, pas une precaution.
        //
        // Sans elle, Playwright negocie en `en-US` : `phone-a-propos.jpg`
        // etait entierement en ANGLAIS (« Connect the right people at the
        // right moment ») alors que le manifest declare `lang: fr` et que son
        // propre `label` est en francais. Android aurait montre une capture
        // anglaise dans la boite d'installation d'une application annoncee
        // francaise.
        //
        // Le defaut etait invisible : rien ne reliait la langue du manifest a
        // celle du navigateur qui capture. La garde `<html lang>` ci-dessous
        // ferme le trou.
        locale: LANGUE,
        extraHTTPHeaders: { 'Accept-Language': `${LANGUE},${LANGUE.split('-')[0]};q=0.9` },
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

            // Garde de LANGUE : la page servie doit etre dans la langue que le
            // manifest annonce. C'est ce qui manquait le 03/11 — une capture
            // anglaise dans un manifest francais, et rien pour le dire.
            await expect(
                page.locator('html'),
                `la capture doit etre servie en « ${LANGUE} », la langue declaree au manifest`,
            ).toHaveAttribute('lang', new RegExp(`^${LANGUE.split('-')[0]}`, 'i'));

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
