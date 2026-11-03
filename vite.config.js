import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            // TASK-1608 : `flowchart.js` est une entree DEDIEE, chargee par la
            // seule page `/org/{organization}/flowchart` — meme patron que
            // `deep-chat-init.js`. Cytoscape reste donc hors de `app.js`, et
            // aucune autre page du produit n'en porte le poids.
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/js/deep-chat-init.js',
                'resources/js/flowchart.js',
                // TASK-1739 — point d'entree PROPRE a l'ecran des
                // notifications, pour la meme raison que les deux ci-dessus :
                // `pushManager` et la demande de permission n'ont rien a
                // peser sur toutes les pages du produit.
                'resources/js/push-device.js',
                // TASK-1745 — la proposition du premier lancement installe.
                // Une entree NON declaree ici est absente du manifeste, et
                // `@vite()` leve alors une ViteException en pleine page.
                // Le defaut n'etait pas visible sur cette branche seule :
                // le bloc n'y est jamais rendu, faute de canal au catalogue.
                // C'est l'integration de recette T1744+T1745 qui l'a montre.
                'resources/js/push-first-run.js',
            ],
            refresh: true,
        }),
    ],
});
