{{--
    TASK-1471 — LE producteur des variables CSS de theme, une seule fois.

    ## Pourquoi ce fichier existe

    La boucle qui ecrit `--bp-*` vivait en double : `layouts/app.blade.php` en
    portait quatre blocs, `layouts/org-admin.blade.php` en portait cinq. Deux
    copies d'une meme generation, qui avaient DEJA diverge — org-admin ecrivait
    un `.dark[data-bp-theme="<defaut>"]` explicite que la boucle suivante
    regenerait de toute facon, aux memes valeurs.

    Et les deux landings publiques reelles (`hero-v2`, `artscilab-hero`), qui
    sont des documents HTML autonomes, n'en avaient aucune : `--bp-primary` y
    etait litteralement absent. Styler quoi que ce soit avec `var(--bp-primary)`
    sur ces pages aurait ete un no-op SILENCIEUX — le pire des echecs.

    ## Ce que ce composant ne fait pas

    Il ne decide rien. Il ne cree aucune autorite de theme : la donnee vient de
    `bp_themes()`, qui lit la meme source que les layouts lisaient (cache
    `storage/app/bouclepro-themes.php`, repli `config/bouclepro_themes.php`).

    Il n'emet AUCUN JavaScript. Le script `localStorage` des layouts applicatifs
    reste chez eux : sur une landing publique, honorer une preference de theme
    laissee dans le navigateur ferait afficher a un visiteur les couleurs d'une
    AUTRE Organization que celle qu'il consulte. La landing pose sa cle de theme
    cote serveur, sur `<html data-bp-theme>`.

    ## L'ordre des regles compte

    `:root` porte le theme par defaut ; `[data-bp-theme="x"]` le surcharge par
    theme ; `.dark` puis `.dark[data-bp-theme="x"]` font de meme en sombre.
    C'est exactement l'ordre des deux layouts d'origine — la specificite CSS
    n'a pas bouge.
--}}
@php
    $bp = bp_themes();
    $bpThemes = $bp['themes'];
    $bpDefaultTheme = $bp['default'];
@endphp
<style>
    :root {
        @foreach($bpThemes[$bpDefaultTheme]['tokens'] as $token => $value)
        --bp-{{ $token }}: {{ $value }};
        @endforeach
    }

    @foreach($bpThemes as $key => $theme)
    [data-bp-theme="{{ $key }}"] {
        @foreach($theme['tokens'] as $token => $value)
        --bp-{{ $token }}: {{ $value }};
        @endforeach
    }
    @endforeach

    .dark {
        @foreach($bpThemes[$bpDefaultTheme]['dark'] as $token => $value)
        --bp-{{ $token }}: {{ $value }};
        @endforeach
    }

    @foreach($bpThemes as $key => $theme)
    .dark[data-bp-theme="{{ $key }}"] {
        @foreach($theme['dark'] as $token => $value)
        --bp-{{ $token }}: {{ $value }};
        @endforeach
    }
    @endforeach
</style>
