import { test, expect } from '@playwright/test';
import { login } from './helpers/auth.js';

/**
 * TASK-1626 — les icones sont-elles VRAIMENT les memes, a l'ecran ?
 *
 * Le banc Feature prouve que les deux vues lisent la meme source. Il ne peut
 * pas prouver que le resultat se voit : c'est le role de ce spec, avec un
 * VRAI viewport — seul `test.use({ viewport })` le change.
 */
const CLES = ['loops', 'flux', 'exchanges', 'messages', 'agenda', 'members', 'blog'];

async function ouvrir(page) {
    await login(page, process.env.TEST_MEMBER1_LOGIN, process.env.TEST_MEMBER1_PASSWORD);
    await page.goto('/loops');
    await page.waitForLoadState('networkidle');
}

/** Le trace `d` de chaque onglet de la barre mobile. */
async function tracesMobile(page) {
    return page.evaluate(() => {
        const out = {};
        document.querySelectorAll('[data-bp-nav-item]').forEach((a) => {
            const p = a.querySelector('svg path');
            if (p) { out[a.dataset.bpNavItem] = p.getAttribute('d'); }
        });
        return out;
    });
}

for (const largeur of [320, 375, 390, 430]) {
    test.describe(`mobile ${largeur} px`, () => {
        test.use({ viewport: { width: largeur, height: 844 } });

        test('les icones sont dessinees, nettes et alignees', async ({ page }) => {
            expect(await page.evaluate(() => window.innerWidth)).toBeLessThan(768);
            await ouvrir(page);

            const mesure = await page.evaluate(() => {
                const onglets = [...document.querySelectorAll('[data-bp-nav-item]')];
                return onglets.map((a) => {
                    const svg = a.querySelector('svg');
                    const r = svg.getBoundingClientRect();
                    return {
                        cle: a.dataset.bpNavItem,
                        largeur: Math.round(r.width),
                        hauteur: Math.round(r.height),
                        haut: Math.round(r.top),
                        trait: svg.getAttribute('stroke-width'),
                        aUnTrace: !! svg.querySelector('path')?.getAttribute('d'),
                    };
                });
            });

            expect(mesure.length).toBeGreaterThanOrEqual(6);
            for (const m of mesure) {
                expect(m.aUnTrace, `${m.cle} sans trace`).toBe(true);
                // 24 px partout : une icone tronquee ou retrecie se verrait ici.
                expect(m.largeur, `${m.cle} largeur`).toBe(24);
                expect(m.hauteur, `${m.cle} hauteur`).toBe(24);
                // Une grammaire = une epaisseur.
                expect(m.trait, `${m.cle} trait`).toBe('1.8');
            }
            // Toutes sur la MEME ligne : aucun decalage vertical.
            expect(new Set(mesure.map((m) => m.haut)).size).toBe(1);
        });
    });
}

test.describe('bureau 1440 px', () => {
    test.use({ viewport: { width: 1440, height: 900 } });

    test('le rail dessine les memes traces que la barre mobile', async ({ page }) => {
        await ouvrir(page);

        const rail = await page.evaluate(() => {
            const out = {};
            document.querySelectorAll('aside a[title], aside a[aria-label]').forEach((a) => {
                const p = a.querySelector('svg path');
                const t = (a.getAttribute('title') || a.getAttribute('aria-label') || '').trim();
                if (p && t) { out[t] = p.getAttribute('d'); }
            });
            return out;
        });

        // La barre mobile est `md:hidden` mais reste DANS le DOM : ses traces
        // se lisent donc sans changer de viewport.
        const mobile = await tracesMobile(page);

        expect(Object.keys(rail).length).toBeGreaterThan(4);

        // Chaque trace de la barre mobile doit exister a l'identique au rail.
        const tracesRail = new Set(Object.values(rail));
        for (const cle of CLES) {
            if (! mobile[cle]) { continue; }
            expect(tracesRail.has(mobile[cle]), `« ${cle} » : le rail ne dessine pas le meme trace que la barre`).toBe(true);
        }
    });

    test('la barre mobile reste cachee et le rail visible', async ({ page }) => {
        await ouvrir(page);

        await expect(page.locator('[data-bp-mobile-nav]')).toBeHidden();
        await expect(page.locator('aside').first()).toBeVisible();
    });
});
