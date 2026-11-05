import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';
import typography from '@tailwindcss/typography';

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        // TASK-1750 — le scanner ne voyait QUE les vues.
        //
        // Des classes utilitaires vivent aussi en PHP, dans les catalogues qui
        // decrivent une couleur par cas : `App\Support\ProfileLinks` (les
        // pastilles de liens) et `App\Support\GuestShell\GuestShellDiagnosis`.
        // Non scannees, elles etaient PURGEES — et le defaut etait sournois :
        // la classe paraissait marcher quand une vue l'employait par ailleurs,
        // et disparaissait pour les autres. Sans ce chemin, `text-pink-400`
        // n'existait pas dans la feuille produite alors que `text-amber-400`,
        // oui.
        './app/**/*.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
        },
    },

    plugins: [forms, typography],
};
