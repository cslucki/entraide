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
            ],
            refresh: true,
        }),
    ],
});
