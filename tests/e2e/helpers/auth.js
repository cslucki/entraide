/**
 * L'authentification du banc Playwright — SOURCE OF TRUTH.
 *
 * TASK-1317 avait pose `ai/playwright/helpers/auth.js` comme un SHIM vide
 * pointant ici, avec une raison juste : `ai/` est integralement gitignore, et
 * un correctif d'authentification cache la serait invisible en revue alors que
 * 48 specs SUIVIES en dependent.
 *
 * TASK-1619 constate que le fichier vise n'a JAMAIS ete commite : `git log
 * --all -- tests/e2e/helpers/auth.js` ne rend rien. Le shim pointait donc dans
 * le vide, et TOUT le banc e2e etait inexecutable —
 * « Cannot find module .../tests/e2e/helpers/auth.js » sur n'importe quel
 * spec, pas seulement les nouveaux.
 *
 * C'est exactement la dette que la memoire du depot nomme : un correctif pose
 * dans `ai/` ne se propage pas. Ici il ne s'etait meme pas pose.
 *
 * Aucun identifiant n'est ecrit en dur : tout vient de l'environnement, et une
 * variable absente ECHOUE bruyamment plutot que de tenter une connexion
 * anonyme qui rendrait un 302 incomprehensible trois assertions plus loin.
 */

function requis(nom) {
    const valeur = process.env[nom];

    if (!valeur || valeur.trim() === '') {
        throw new Error(
            `Le banc Playwright a besoin de ${nom} dans l'environnement. ` +
            `Voir ai/playwright/README.md, section « Strict Account Separation ».`,
        );
    }

    return valeur;
}

/**
 * Connexion par identifiants EXPLICITES.
 *
 * On verifie l'arrivee plutot que de supposer : un mot de passe faux rend la
 * page de connexion avec un 200, et sans cette garde le spec continuerait
 * jusqu'a un `toBeVisible()` qui echouerait en accusant la mauvaise chose.
 */
export async function login(page, email, password) {
    await page.goto('/login');
    await page.waitForLoadState('domcontentloaded');

    // Le formulaire porte TROIS `button[type="submit"]` : deux selecteurs de
    // langue masques (EN / FR) precedent le vrai. Viser le premier venu
    // expirait sur un bouton invisible — on se scope donc au FORMULAIRE qui
    // contient le champ de mot de passe, seul endroit ou le bouton de
    // connexion puisse etre.
    const formulaire = page.locator('form:has(input[name="password"])').first();

    await formulaire.locator('input[name="email"]').fill(email);
    await formulaire.locator('input[name="password"]').fill(password);
    await Promise.all([
        page.waitForLoadState('networkidle'),
        formulaire.locator('button[type="submit"]:visible').last().click(),
    ]);

    if (new URL(page.url()).pathname.startsWith('/login')) {
        throw new Error(`Connexion refusee pour ${email} : le banc est reste sur /login.`);
    }
}

export async function logout(page) {
    // Le bouton de deconnexion vit dans un formulaire POST (protection CSRF) ;
    // viser la route en GET rendrait un 405.
    const bouton = page.locator('form[action$="/logout"] button[type="submit"]').first();

    if (await bouton.count()) {
        await Promise.all([page.waitForLoadState('networkidle'), bouton.click()]);
    }
}

export async function loginAsMember(page) {
    await login(page, requis('TEST_MEMBER1_LOGIN'), requis('TEST_MEMBER1_PASSWORD'));
}

export async function loginAsSecondMember(page) {
    await login(page, requis('TEST_MEMBER2_LOGIN'), requis('TEST_MEMBER2_PASSWORD'));
}

export async function loginAsAdmin(page) {
    await login(page, requis('TEST_ADMIN_LOGIN'), requis('TEST_ADMIN_PASSWORD'));
}

export async function loginAsCpmeMember(page) {
    await login(page, requis('TEST_MEMBER_OF_CPME1_LOGIN'), requis('TEST_MEMBER_OF_CPME1_PASSWORD'));
}
