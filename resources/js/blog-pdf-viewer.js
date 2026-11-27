/**
 * TASK-1907 — visionneuse PDF des articles (PDF.js).
 *
 * Charge A LA DEMANDE par app.js, seulement quand la page contient un PDF
 * d'article (`figure[data-bp-pdf-src]`) : les autres pages ne paient rien.
 * Le meme rendu sur ordinateur, Android et iPhone (decision Cyril, 27/11).
 *
 * Le fichier vient de la route gardee : le navigateur y envoie la session,
 * les droits de l'article s'appliquent a chaque lecture. Le lien « Ouvrir le
 * PDF » rendu par le serveur reste en place : c'est la voie de secours.
 */
import * as pdfjs from 'pdfjs-dist';
import workerUrl from 'pdfjs-dist/build/pdf.worker.min.mjs?url';

pdfjs.GlobalWorkerOptions.workerSrc = workerUrl;

// Au-dela, on affiche le debut et on renvoie au fichier complet : un PDF de
// 300 pages rendu d'un bloc ferait souffrir un telephone.
const MAX_PAGES = 30;

async function renderViewer(figure) {
    if (figure.dataset.bpPdfReady) return;
    figure.dataset.bpPdfReady = '1';

    const src = figure.dataset.bpPdfSrc;
    const viewer = document.createElement('div');
    viewer.className = 'bp-pdf-viewer';
    viewer.setAttribute('role', 'document');
    viewer.setAttribute('aria-label', figure.querySelector('figcaption')?.textContent || 'PDF');

    const status = document.createElement('p');
    status.className = 'bp-pdf-status';
    status.textContent = 'Chargement du PDF…';
    viewer.append(status);
    figure.insertBefore(viewer, figure.querySelector('a'));

    try {
        const pdf = await pdfjs.getDocument({ url: src, withCredentials: true }).promise;
        const total = pdf.numPages;
        const shown = Math.min(total, MAX_PAGES);
        status.textContent = total > 1 ? `${total} pages` : '1 page';

        for (let n = 1; n <= shown; n++) {
            const page = await pdf.getPage(n);
            const width = Math.max(viewer.clientWidth - 16, 200);
            const base = page.getViewport({ scale: 1 });
            const scale = width / base.width;
            const ratio = window.devicePixelRatio || 1;
            const viewport = page.getViewport({ scale: scale * ratio });

            const canvas = document.createElement('canvas');
            canvas.className = 'bp-pdf-page';
            canvas.width = Math.floor(viewport.width);
            canvas.height = Math.floor(viewport.height);
            canvas.style.width = `${Math.floor(viewport.width / ratio)}px`;
            canvas.setAttribute('aria-label', `Page ${n}`);
            viewer.append(canvas);

            await page.render({ canvas, viewport }).promise;
        }

        if (total > shown) {
            const more = document.createElement('p');
            more.className = 'bp-pdf-status';
            more.textContent = `${shown} premières pages sur ${total} : ouvrez le PDF pour la suite.`;
            viewer.append(more);
        }
    } catch {
        // Le lien de secours reste disponible.
        status.textContent = 'Aperçu indisponible : ouvrez le PDF.';
    }
}

export function initPdfViewers(root = document) {
    const figures = [...root.querySelectorAll('figure[data-bp-pdf-src]')];
    if (!figures.length) return;

    if (!('IntersectionObserver' in window)) {
        figures.forEach(renderViewer);
        return;
    }

    // Rendu a l'approche de l'ecran : un PDF en bas d'article ne se
    // telecharge pas tant qu'on n'y est pas.
    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (entry.isIntersecting) {
                observer.unobserve(entry.target);
                renderViewer(entry.target);
            }
        });
    }, { rootMargin: '300px 0px' });

    figures.forEach((figure) => observer.observe(figure));
}
