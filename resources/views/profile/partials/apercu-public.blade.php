{{--
    TASK-1788 correctif 04 — ce que l'en-tete du profil public ne porte plus.

    Cyril, en recette : « la c'est totalement surcharge ! ». L'en-tete melait
    l'identite (qui est cette personne, comment la joindre) et le palmares
    (notes, compteurs, points, bilan, remerciements, badges). Le second noyait
    le premier.

    L'en-tete garde donc l'identite seule — photo, nom, structure, slogan,
    localisation, liens. Tout le reste vit ici, et ce fichier est le SEUL
    exemplaire : il est inclus une fois, dans la colonne de droite.

    ## Pourquoi un seul exemplaire suffit aux deux largeurs

    La grille parente est `md:grid md:grid-cols-3` : elle ne s'applique qu'a
    partir de `md`. En dessous, les deux colonnes redeviennent de simples blocs
    empiles dans l'ORDRE DU DOCUMENT — et la colonne de droite vient apres la
    gauche. Ce bloc atterrit donc tout seul en fin d'ecran sur smartphone,
    exactement ce qui a ete demande, sans un second exemplaire a maintenir.

    Le piege evite : dupliquer le bloc (un `md:hidden` en bas de page, un
    `hidden md:block` dans la colonne) aurait rendu les memes marqueurs DEUX
    fois dans le document, et garanti que les deux copies divergent au premier
    changement. Meme lecon qu'en T1762 sur la structure.
--}}
@php($_aQuelqueChoseAMontrer = $user->rating || $completedCount || ! \App\Support\Entraide\BilanEntraide::estVide($bilan))

@if($_aQuelqueChoseAMontrer)
{{-- TASK-1788 correctif 05 — un titre, au MEME gabarit que « Blog » juste
     au-dessus : meme balise, memes classes, meme casse. Deux cartes voisines
     qui s'annoncent differemment se liraient comme deux choses de natures
     differentes. --}}
<section data-profile-stats>
    <h2 class="text-sm font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wider mb-3">{{ __('profile.stats_section') }}</h2>
<div class="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800"
     data-profile-apercu>

    <!-- Stats chips -->
    <div class="flex flex-wrap gap-2">
        @if($user->rating)
        <span class="inline-flex items-center gap-1 px-2.5 py-1 bg-yellow-50 dark:bg-yellow-900/20 text-yellow-700 dark:text-yellow-300 rounded-lg text-xs font-medium">
            ⭐ {{ number_format($user->rating, 1) }}
        </span>
        @endif
        <span class="inline-flex items-center gap-1 px-2.5 py-1 bg-gray-50 dark:bg-gray-700/50 text-gray-600 dark:text-gray-300 rounded-lg text-xs font-medium">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            {{ trans_choice('profile.exchanges', $completedCount, ['count' => $completedCount]) }}
        </span>
        {{-- TASK-1755 §9.6 — le TEMPS disponible, pas un solde comptable. Meme
             donnee, lecture d'entraide (arbitrage Q8 : double affichage). --}}
        {{-- `data-profile-temps` EN PLUS de `data-entraide-temps` : ce dernier
             est pose sur toutes les durees du produit — y compris sur les cartes
             d'annonce de cette meme page. Il ne peut donc pas servir a dire ou
             se trouve CE bandeau. --}}
        <span class="inline-flex items-center gap-1 px-2.5 py-1 bg-indigo-50 dark:bg-indigo-900/20 text-indigo-600 dark:text-indigo-300 rounded-lg text-xs font-medium"
              data-profile-temps data-entraide-temps>
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            {{ \App\Support\Entraide\TempsPartage::solde($user->points_balance) }}
        </span>
    </div>

    {{-- TASK-1755 §9.6 (maquette M4 droite) — LE BILAN D'ENTRAIDE.

         Le profil disait un solde. C'est un chiffre de portefeuille : il ne
         raconte rien de ce que la personne a fait pour les autres. Trois
         nombres le remplacent, et les MOTS de remerciement les accompagnent —
         un compteur seul ne rend pas la gratitude.

         Rien ne s'affiche quand le bilan est vide : « 0 personne · 0 fois ·
         0 min » est un reproche, pas un bilan. --}}
    @unless(\App\Support\Entraide\BilanEntraide::estVide($bilan))
    <div class="mt-4" data-entraide-bilan>
        <div class="grid grid-cols-3 gap-2 text-center">
            @foreach([
                ['valeur' => $bilan['personnes_aidees'], 'cle' => 'personnes_aidees', 'marqueur' => 'personnes'],
                ['valeur' => $bilan['fois_aide'], 'cle' => 'fois_aide', 'marqueur' => 'fois'],
            ] as $_chiffre)
                <div class="rounded-xl border border-gray-200 bg-white px-2 py-3 dark:border-gray-700 dark:bg-gray-800">
                    <p class="text-xl font-bold text-indigo-600 dark:text-indigo-400 tabular-nums"
                       data-entraide-bilan-{{ $_chiffre['marqueur'] }}>{{ $_chiffre['valeur'] }}</p>
                    <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ trans_choice('profile.bilan.'.$_chiffre['cle'], $_chiffre['valeur']) }}</p>
                </div>
            @endforeach
            <div class="rounded-xl border border-gray-200 bg-white px-2 py-3 dark:border-gray-700 dark:bg-gray-800">
                <p class="text-xl font-bold text-indigo-600 dark:text-indigo-400 tabular-nums" data-entraide-bilan-temps>
                    {{ \App\Support\Entraide\BilanEntraide::tempsOffert($bilan['temps_offert']) }}
                </p>
                <p class="text-[11px] text-gray-500 dark:text-gray-400">{{ __('profile.bilan.temps_offert') }}</p>
            </div>
        </div>

        {{-- TASK-1788 correctif 05 — les REMERCIEMENTS ne sont plus repris ici.

             Ils doublonnaient la section « Avis » de la colonne de gauche, qui
             les porte deja en entier, avec leur note et leur date. Le compteur
             du bilan les compte toujours ; seule la reprise des citations
             disparait. --}}
    </div>
    @endunless

</div>
</section>
@endif
