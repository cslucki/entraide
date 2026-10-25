{{--
    TASK-1703 — `--bp-vvh` : la hauteur du viewport VISUEL, posee par le seul
    abonnement qui la connaisse.

    ## Pourquoi cette variable existe

    Sur un ecran de conversation, le bas appartient au composeur. Trois choses
    le font bouger si on s'en remet aux unites CSS seules :

      * `100vh` ignore la barre d'URL du navigateur ;
      * `100dvh` la SUIT — un geste de defilement vers le haut la retracte, le
        viewport grandit, et le composeur ancre en bas se deplace sous le
        doigt ;
      * `100svh` ne varie jamais, mais ne sait rien du clavier virtuel.

    `window.visualViewport.height` est la seule mesure juste dans les trois
    cas. Le script ne calcule AUCUNE mise en page : il ecrit une variable, le
    CSS garde la main.

    ## Un seul endroit, deux appelants

    Ce bloc vivait en clair dans `loops.show` depuis TASK-1684 MICRO-01.
    TASK-1703 a trouve le MEME defaut sur la Messagerie — meme cause, meme
    correctif. Deux copies d'un calcul de viewport divergent a la premiere
    retouche ; il n'y en a donc qu'une, et le selecteur dit sur quelle page
    elle s'applique.

    Sans `visualViewport` (navigateur ancien), rien ne s'execute et le repli
    `svh` de la feuille de style s'applique.
--}}
@props(['selector'])

<script>
    (function () {
        var vv = window.visualViewport;
        if (!vv || !document.querySelector(@js($selector))) { return; }

        var apply = function () {
            document.documentElement.style.setProperty('--bp-vvh', Math.round(vv.height) + 'px');
        };

        apply();
        vv.addEventListener('resize', apply);
    })();
</script>
