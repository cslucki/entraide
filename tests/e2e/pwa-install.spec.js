// TASK-1737 — l'ecran d'installation, dans un vrai navigateur.
//
// Ce que les tests PHP ne peuvent pas voir : la CSS appliquee, le bouton
// reellement visible ou non, le cookie pose par le navigateur, la touche
// Echap. Les tests feature mesurent le HTML EMIS ; ceux-ci mesurent ce que
// quelqu'un VOIT.
//
// Les dialogues natifs d'installation (Chrome, iOS) restent hors de portee de
// toute automatisation : ils appartiennent au systeme. La recette sur appareil
// reel, consignee dans le TASK file, est la seule a pouvoir les couvrir.
//
// Usage : npx playwright test tests/e2e/pwa-install.spec.js

import { test, expect } from '@playwright/test';

const UA_TELEPHONE =
    'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Mobile Safari/537.36';

const MOBILE = {
    viewport: { width: 390, height: 844 },
    deviceScaleFactor: 2,
    isMobile: true,
    hasTouch: true,
    // `DeviceKind` lit l'user-agent cote serveur : `isMobile` seul ne le
    // change pas, et le balisage ne serait pas emis.
    userAgent: UA_TELEPHONE,
};

test.describe('TASK-1737 ecran d\'installation', () => {
    test.use(MOBILE);

    // ── UN PIEGE MESURE, ET POURQUOI LE SERVICE WORKER EST NEUTRALISE ICI ──
    //
    // Mesure du 31/10/2026, en interceptant le reseau :
    //
    //   1re navigation (directe)  UA = ...Pixel 8... Mobile Safari
    //   2e  navigation (via SW)   UA = ...X11; Linux... HeadlessChrome/147...
    //
    // Le `fetch()` d'un Service Worker **n'herite pas** de l'user-agent
    // simule par Playwright : un worker est un contexte separe, et l'override
    // CDP ne porte que sur la page. Des la 2e navigation, le serveur voyait
    // donc `HeadlessChrome` — que `DeviceKind` range dans les OUTILS, examines
    // en premier — et n'emettait plus rien.
    //
    // C'est un artefact du banc, PAS un defaut produit : sur un vrai
    // telephone, la page et son Service Worker envoient le meme user-agent.
    // Le verifier a coute une heure ; le consigner evite de la repayer.
    //
    // Danger reel si on ne le traite pas : les tests qui attendent une ABSENCE
    // passeraient pour la mauvaise raison. On neutralise donc l'enregistrement
    // dans ce bloc, et le comportement du Service Worker garde son propre test
    // plus bas, ou il est bien actif.
    test.beforeEach(async ({ page }) => {
        await page.addInitScript(() => {
            Object.defineProperty(navigator, 'serviceWorker', { get: () => undefined });
        });
    });

    test('l\'ecran plein format s\'affiche sur /installer', async ({ page }) => {
        await page.goto('/installer');

        const ecran = page.locator('[data-bp-pwa-install="full"]');
        await expect(ecran).toBeVisible();

        // Le repli du mode installe, lui, reste masque dans un onglet.
        await expect(page.locator('.bp-installer-fallback')).toBeHidden();
    });

    test('aucun bouton d\'installation tant qu\'aucun prompt n\'existe', async ({ page }) => {
        await page.goto('/installer');

        // CDC 3.3 : « ne jamais afficher un bouton qui ne fonctionne pas ».
        // Chromium sans critere d'installabilite n'emet pas
        // `beforeinstallprompt` : le bouton doit rester invisible.
        await expect(page.locator('.bp-pwa__cta')).toBeHidden();

        // Ce sont les instructions de la plateforme qui tiennent l'ecran.
        await expect(page.locator('[data-bp-pwa-platform="generic"]')).toBeVisible();
    });

    test('le bouton apparait quand un prompt existe reellement', async ({ page }) => {
        await page.goto('/installer');
        await expect(page.locator('.bp-pwa__cta')).toBeHidden();

        // On simule l'evenement que le navigateur emettrait s'il jugeait la
        // page installable. On ne simule PAS l'installation elle-meme : elle
        // appartient au systeme.
        await page.evaluate(() => {
            const evenement = new Event('beforeinstallprompt');
            evenement.prompt = () => Promise.resolve();
            evenement.userChoice = Promise.resolve({ outcome: 'dismissed' });
            window.dispatchEvent(evenement);
        });

        await expect(page.locator('.bp-pwa__cta')).toBeVisible();

        // Et les instructions manuelles s'effacent : une seule voie a la fois.
        await expect(page.locator('.bp-pwa__manual')).toBeHidden();
    });

    test('le renoncement se pose et il tient', async ({ page, context }) => {
        await page.goto('/about');

        // Ce test mesure le MECANISME du renoncement, pas la forme qui le
        // porte : bandeau sous le defaut, plein ecran sous `install_first`.
        // Les deux offrent la meme porte de sortie, et le cookie est le meme.
        // Viser `[data-bp-pwa-install]` sans qualifier le mode rend donc ce
        // test independant du `PWA_INSTALL_POLICY` servi — que le banc E2E,
        // qui frappe le vrai serveur, ne controle pas.
        const invitation = page.locator('[data-bp-pwa-install]');
        await expect(invitation).toBeVisible();

        await invitation.locator('[data-bp-pwa-action="dismiss"]').click();
        await expect(page.locator('[data-bp-pwa-install]')).toHaveCount(0);

        // Le cookie est pose EN CLAIR : c'est le serveur qui doit pouvoir le
        // lire, et c'est pour cela qu'il est exempte de `EncryptCookies`.
        const cookies = await context.cookies();
        const renoncement = cookies.find((c) => c.name === 'bp_pwa_dismissed');
        expect(renoncement).toBeTruthy();
        expect(renoncement.value).toBe('1');

        // Le vrai test du renoncement : deux navigations plus tard, rien.
        //
        // `/installer` ne figure PLUS dans cette liste, et son retrait est le
        // correctif du 31/10/2026. Il y etait, et ce test gravait donc le
        // defaut : il exigeait qu'une page atteinte EXPRES reste vide pendant
        // trente jours. Un renoncement porte sur une invitation, pas sur une
        // destination — c'est le test suivant qui mesure cela.
        for (const chemin of ['/about', '/org/main']) {
            await page.goto(chemin);
            await expect(page.locator('[data-bp-pwa-install]')).toHaveCount(0);
        }
    });

    test('un renoncement ne vide jamais /installer', async ({ page, context }) => {
        // Le defaut de recette du 31/10/2026, SECONDE porte. Le cookie etait lu
        // avant la route dediee : un « plus tard » touche une fois rendait
        // `/installer` vide pendant trente jours, et plus aucun chemin ne menait
        // a l'installation pour qui avait change d'avis.
        //
        // Mesure dans un vrai navigateur, et c'est necessaire : la page vide
        // etait une affaire de CSS. Le texte du repli etait bien dans le HTML —
        // un `assertSee()` PHP le trouvait — mais `display: none` le rendait
        // invisible. Seul `innerText` voit ce qu'une personne voit.
        await context.addCookies([{
            name: 'bp_pwa_dismissed',
            value: '1',
            domain: 'test.laravel',
            path: '/',
        }]);

        await page.goto('/installer');
        await expect(page.locator('[data-bp-pwa-install="full"]')).toBeVisible();

        const texteVisible = await page.evaluate(() => document.body.innerText.trim());
        expect(texteVisible.length).toBeGreaterThan(40);

        // Et le renoncement garde tout son effet LA OU IL PORTE : corriger le
        // defaut en ignorant le cookie partout serait l'autre erreur.
        await page.goto('/about');
        await expect(page.locator('[data-bp-pwa-install]')).toHaveCount(0);
    });

    // ── Le defaut trouve en recette par Cyril, 31/10/2026 ───────────────────
    //
    // Deux symptomes, une seule cause : sur `/installer` la superposition EST
    // la page. La retirer laissait un ecran NOIR, et le cookie de 30 jours
    // faisait disparaitre le bandeau de tout le site — alors que la personne
    // avait simplement touche « Installer » puis voulu revenir.

    test('quitter /installer ramene au site, sans ecran noir', async ({ page }) => {
        await page.goto('/installer');
        await expect(page.locator('[data-bp-pwa-install="full"]')).toBeVisible();

        await page.locator('a.bp-pwa__dismiss').click();

        // `/` redirige vers la landing du tenant : DEUX navigations.
        // `domcontentloaded` se resout sur la PREMIERE et laissait lire un
        // document en cours de remplacement, dont `document.body` vaut
        // `null`. `networkidle` attend que la chaine entiere se pose.
        await page.waitForLoadState('networkidle');
        await expect(page).not.toHaveURL(/\/installer$/);

        // Et il y a QUELQUE CHOSE a l'ecran. C'est le test de l'ecran noir :
        // un corps dont le texte visible est vide serait la regression.
        const texteVisible = await page.evaluate(() => document.body.innerText.trim());
        expect(texteVisible.length).toBeGreaterThan(40);
    });

    test('quitter /installer ne renonce a rien : le bandeau reste', async ({ page, context }) => {
        await page.goto('/installer');
        await page.locator('a.bp-pwa__dismiss').click();
        await page.waitForLoadState('networkidle');
        await expect(page).not.toHaveURL(/\/installer$/);

        // Aucun cookie de renoncement : partir n'est pas refuser.
        const cookies = await context.cookies();
        expect(cookies.find((c) => c.name === 'bp_pwa_dismissed')).toBeFalsy();

        // Et l'invitation est toujours la, sur l'accueil comme ailleurs.
        // Sa FORME depend de la politique servie ; sa PRESENCE est ce que le
        // defaut de recette avait casse, et c'est donc ce qu'on mesure.
        await page.goto('/about');
        await expect(page.locator('[data-bp-pwa-install]')).toBeVisible();
    });

    test('Echap sur /installer ramene au site, il ne vide pas la page', async ({ page }) => {
        // Meme cause, autre porte : `Echap` appelle la meme fermeture. Sans
        // traitement, le defaut serait revenu par le clavier.
        await page.goto('/installer');
        await expect(page.locator('[data-bp-pwa-install="full"]')).toBeVisible();

        await page.keyboard.press('Escape');

        await page.waitForLoadState('networkidle');
        await expect(page).not.toHaveURL(/\/installer$/);
        const texteVisible = await page.evaluate(() => document.body.innerText.trim());
        expect(texteVisible.length).toBeGreaterThan(40);
    });

    test('le bandeau s\'affiche sur une vitrine, et mene a l\'ecran dedie', async ({ page }) => {
        await page.goto('/about');

        // Celui-ci mesure bien la FORME bandeau : son caractere non bloquant,
        // et son lien `.bp-pwa__bar-action` qui n'existe que la. Sous
        // `install_first` — le mode de recette (CDC 3.6) — la vitrine sert le
        // plein ecran : la precondition n'est pas remplie, et ce n'est PAS un
        // defaut. Un test dont la precondition manque doit etre IGNORE, jamais
        // rouge : un rouge inexplique est un rouge qu'on apprend a ignorer.
        const presentes = await page.locator('[data-bp-pwa-install]').count();
        const modeServi = presentes === 0
            ? 'none'
            : await page.locator('[data-bp-pwa-install]').first()
                .getAttribute('data-bp-pwa-install');
        test.skip(
            modeServi !== 'banner',
            `mode servi sur la vitrine = ${modeServi} ; PWA_INSTALL_POLICY n'est pas \`banner\``
        );

        const bandeau = page.locator('[data-bp-pwa-install="banner"]');
        await expect(bandeau).toBeVisible();

        // Non bloquant : le contenu de la page reste atteignable derriere.
        await expect(page.locator('body')).toBeVisible();

        await bandeau.locator('a.bp-pwa__bar-action').click();
        await expect(page).toHaveURL(/\/installer$/);
        await expect(page.locator('[data-bp-pwa-install="full"]')).toBeVisible();
    });

    // -- Le defaut de recette du 01/11/2026 : le haut INATTEIGNABLE ---------
    //
    // Capture d'un Pixel : icone coupee en haut, « Retour au site » coupe en
    // bas. Cause -- `.bp-pwa--full` est un flex COLONNE, donc
    // `justify-content: center` centre sur l'axe VERTICAL ; combine a
    // `overflow-y: auto`, le centrage pousse le haut du contenu AU-DESSUS de
    // l'origine du defilement quand il deborde. `scrollTop` ne pouvant pas
    // etre negatif, cette zone est definitivement perdue.
    //
    // Mesure avant correctif, a `scrollTop = 0` : haut de la carte a -45 px en
    // 390x600, -85 px en 360x520.
    //
    // Seul un vrai navigateur voit cela : ni PHP ni `curl` n'appliquent la
    // mise en page.
    for (const [nom, largeur, hauteur] of [
        ['Pixel avec barre', 412, 760],
        ['hauteur serree', 390, 600],
        ['Android courant', 360, 640],
        ['iPhone SE', 375, 667],
        ['telephone en paysage', 360, 520],
    ]) {
        test(`rien n'est rogne en haut -- ${nom} ${largeur}x${hauteur}`, async ({ page }) => {
            await page.setViewportSize({ width: largeur, height: hauteur });
            await page.goto('/installer');

            const mesure = await page.evaluate(() => {
                const racine = document.querySelector('[data-bp-pwa-install]');
                const carte = racine.firstElementChild;
                racine.scrollTop = 0;
                return { haut: Math.round(carte.getBoundingClientRect().top) };
            });

            // L'invariant : a l'origine du defilement, le haut du contenu est
            // VISIBLE. Du defilement n'est pas un defaut ; du contenu au-dessus
            // de zero, qu'aucun geste ne ramene, en est un.
            expect(
                mesure.haut,
                `Haut du contenu a ${mesure.haut}px : rogne, et aucun defilement `
                + 'ne le ramene. Ne pas remettre `justify-content: center` sur '
                + '`.bp-pwa--full` : son axe principal est VERTICAL.'
            ).toBeGreaterThanOrEqual(0);
        });
    }

    // Second volet du meme defaut : meme sans rognage, le contenu mesurait
    // 669 px pour une hauteur VISIBLE de 600 a 670 px — la barre d'adresse
    // mange 110 a 150 px que `100vh` ignore. Il fallait defiler pour atteindre
    // l'action d'un ecran dont c'est l'unique raison d'etre.
    //
    // 375x667 est la taille qui mesure vraiment le bloc `@media
    // (max-height: 740px)` : sans lui le contenu y faisait 689 px. 412x760 est
    // le cas de la capture de Cyril, au-dessus du seuil.
    for (const [nom, largeur, hauteur] of [['Pixel avec barre', 412, 760], ['iPhone SE', 375, 667]]) {
    test(`l ecran tient sans defilement -- ${nom} ${largeur}x${hauteur}`, async ({ page }) => {
        await page.setViewportSize({ width: largeur, height: hauteur });
        await page.goto('/installer');

        const r = await page.evaluate(() => {
            const racine = document.querySelector('[data-bp-pwa-install]');
            const sortie = racine.querySelector('.bp-pwa__dismiss').getBoundingClientRect();
            return {
                deborde: racine.scrollHeight > racine.clientHeight,
                sortieVisible: sortie.top >= 0 && sortie.bottom <= window.innerHeight,
            };
        });

        expect(r.deborde, 'Le contenu deborde sur un telephone courant.').toBe(false);
        expect(r.sortieVisible, 'L action secondaire exige de defiler.').toBe(true);
    });
    }

    test('une application installee ne voit jamais l\'ecran', async ({ page }) => {
        await page.goto('/installer');
        await expect(page.locator('[data-bp-pwa-install="full"]')).toBeVisible();

        // La media query `display-mode: standalone` n'est pas pilotable depuis
        // Playwright. On exerce donc la CEINTURE — le chemin
        // `navigator.standalone` des iOS anciens — qui pose le meme attribut et
        // declenche la meme regle CSS.
        await page.evaluate(() => {
            document.documentElement.setAttribute('data-bp-standalone', '1');
        });

        await expect(page.locator('[data-bp-pwa-install="full"]')).toBeHidden();
    });

    test('le focus part sur le titre et ne sort pas de l\'ecran', async ({ page }) => {
        await page.goto('/installer');

        // Le lecteur d'ecran doit annoncer le dialogue, pas la page derriere.
        await expect(page.locator('#bp-pwa-title')).toBeFocused();

        // La tabulation reste DANS le dialogue tant qu'il couvre la page.
        await page.keyboard.press('Tab');
        const dansLEcran = await page.evaluate(() =>
            !!document.activeElement?.closest('[data-bp-pwa-install]')
        );
        expect(dansLEcran).toBe(true);
    });

});

// Le Service Worker est ACTIF dans ce bloc : c'est lui qu'on mesure.
test.describe('TASK-1737 non-regression du cache (TASK-1692)', () => {
    test.use(MOBILE);

    test('aucune entree HTML privee n\'entre dans le Cache Storage', async ({ page }) => {
        // Garde de non-regression TASK-1692, exercee dans un vrai navigateur :
        // le Service Worker est enregistre sur ces pages depuis cette TASK, et
        // il ne doit toujours mettre en cache que des assets statiques.
        await page.goto('/org/main');
        await page.goto('/about');
        await page.goto('/installer');

        const entrees = await page.evaluate(async () => {
            if (!('caches' in window)) { return []; }
            const noms = await caches.keys();
            const toutes = [];
            for (const nom of noms) {
                const cache = await caches.open(nom);
                for (const requete of await cache.keys()) {
                    toutes.push(requete.url);
                }
            }
            return toutes;
        });

        for (const url of entrees) {
            const chemin = new URL(url).pathname;
            expect(
                /\.(js|css|png|jpe?g|gif|svg|ico|webp|woff2?)$|^\/site\.webmanifest$/.test(chemin),
                `Le Cache Storage contient [${chemin}], qui n'est pas un asset statique.`
            ).toBe(true);
        }
    });
});
