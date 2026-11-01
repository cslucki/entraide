import { test, expect } from '@playwright/test';
import { login } from './helpers/auth.js';

/**
 * TASK-1738 — partage d'un lien externe vers une Boucle (Web Share Target).
 *
 * Ce que ce banc mesure dans un VRAI navigateur, et que PHPUnit ne peut pas :
 * la redirection 303 suivie par le moteur (l'URL partagee DISPARAIT de la
 * barre d'adresse), le detour par la connexion qui conserve l'intention, et
 * le formulaire de collage comme repli.
 *
 * Ce qu'il ne mesure PAS : la feuille de partage native d'Android — elle
 * exige la PWA installee sur un appareil reel, c'est la recette de Cyril.
 * Ici on entre par l'URL que cette feuille appellerait, ce qui est
 * exactement ce que recoit le serveur.
 *
 * La publication REELLE n'est pas jouee ici : elle ecrirait un message dans
 * une Boucle de la base de developpement a chaque passage du banc. Elle est
 * couverte par la suite PHPUnit (TASK1738ShareTargetTest) et par la recette
 * humaine.
 */

// Sans parentheses, deliberement : `UrlPreviewService::extractFirstUrl()` —
// le MEME extracteur que la ChatLoop — s'arrete a la premiere parenthese,
// comme pour tout lien colle dans le composeur.
const URL_PARTAGEE = 'https://fr.wikipedia.org/wiki/Entraide';

test('un partage recu sans session mene a la connexion puis a l ecran de choix, URL nettoyee', async ({ page }) => {
    // L'arrivee de la feuille de partage : URL en query string.
    await page.goto('/partage?url=' + encodeURIComponent(URL_PARTAGEE) + '&title=' + encodeURIComponent('Entraide'));
    await page.waitForLoadState('networkidle');

    // Sans session, la cible protegee renvoie vers la connexion — et la
    // query string a DISPARU en route : plus d'URL partagee dans la barre.
    expect(page.url()).toContain('/login');
    expect(page.url()).not.toContain('wikipedia');

    // La connexion N'EFFACE PAS l'intention : elle vit en session, et
    // `regenerate()` conserve le contenu.
    await login(page, process.env.TEST_MEMBER1_LOGIN, process.env.TEST_MEMBER1_PASSWORD);
    await page.goto('/partage/choisir');
    await page.waitForLoadState('networkidle');

    await expect(page.locator('body')).toContainText(URL_PARTAGEE);
    expect(page.url()).not.toContain('wikipedia');
});

test('un partage recu avec une session active arrive directement sur l ecran de choix', async ({ page }) => {
    await login(page, process.env.TEST_MEMBER1_LOGIN, process.env.TEST_MEMBER1_PASSWORD);

    await page.goto('/partage?url=' + encodeURIComponent(URL_PARTAGEE));
    await page.waitForLoadState('networkidle');

    // Le 303 a ete suivi : URL nue, lien affiche, et le geste de validation
    // humaine est la — un bouton de publication, pas une publication faite.
    expect(page.url()).toMatch(/\/partage\/choisir$/);
    await expect(page.locator('body')).toContainText(URL_PARTAGEE);
    await expect(page.locator('form[action$="/partage/publier"] button[type="submit"]')).toBeVisible();

    // Le commentaire est FACULTATIF : le champ n'est pas marque requis.
    await expect(page.locator('#commentaire')).not.toHaveAttribute('required', /.*/);
});

test('sans lien en attente, l ecran offre le collage de repli (iPhone/desktop)', async ({ page }) => {
    await login(page, process.env.TEST_MEMBER1_LOGIN, process.env.TEST_MEMBER1_PASSWORD);

    // Visite directe, aucune intention : le repli de collage, qui est le
    // chemin officiel des plateformes sans Web Share Target (CDC 4.3).
    await page.goto('/partage/choisir');
    await page.waitForLoadState('networkidle');

    const champ = page.locator('form[action$="/partage"] input[name="url"]');
    await expect(champ).toBeVisible();

    // Le collage repasse par la MEME reception : apres soumission, l'URL
    // collee s'affiche sur l'ecran de choix, et la barre d'adresse est nue.
    await champ.fill(URL_PARTAGEE);
    await page.locator('form[action$="/partage"] button[type="submit"]').click();
    await page.waitForLoadState('networkidle');

    expect(page.url()).toMatch(/\/partage\/choisir$/);
    await expect(page.locator('body')).toContainText(URL_PARTAGEE);
});

test('un texte sans lien exploitable ne laisse rien en attente', async ({ page }) => {
    await login(page, process.env.TEST_MEMBER1_LOGIN, process.env.TEST_MEMBER1_PASSWORD);

    await page.goto('/partage?text=' + encodeURIComponent('du texte sans aucun lien'));
    await page.waitForLoadState('networkidle');

    // Pas d'intention fantome : l'ecran retombe sur le collage.
    expect(page.url()).toMatch(/\/partage\/choisir$/);
    await expect(page.locator('form[action$="/partage"] input[name="url"]')).toBeVisible();
});

test('le manifest declare la cible de partage', async ({ request }) => {
    const reponse = await request.get('/site.webmanifest');
    expect(reponse.ok()).toBe(true);

    const manifest = await reponse.json();
    expect(manifest.share_target).toEqual({
        action: '/partage',
        method: 'GET',
        params: { url: 'url', text: 'text', title: 'title' },
    });
});
