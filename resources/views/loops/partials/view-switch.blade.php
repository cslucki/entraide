{{-- TASK-1880 (demande Cyril, 22/11) — la bascule Grille / Liste remonte dans la
     rangee du titre (bureau) et dans celle des boutons (smartphone, icones
     seules : `compact`, libelles gardes pour les lecteurs d'ecran). --}}
{{-- TASK-1864 — meme patron que l'annuaire (`members/index`) :
     de vrais liens qui posent `?view=`, pas un etat Alpine —
     l'adresse se partage et le retour arriere la conserve.
     `grid` est la valeur par defaut : son lien RETIRE le
     parametre plutot que d'ecrire `view=grid`. --}}
<div class="{{ $class ?? '' }} inline-flex shrink-0 overflow-hidden rounded-xl border border-gray-300 bg-white text-sm dark:border-gray-600 dark:bg-gray-800"
     role="group" aria-label="{{ __('loops.view_switch_label') }}" data-loops-view-switch @if($compact ?? false) data-loops-view-switch-compact @endif>
    <a href="{{ request()->fullUrlWithQuery(['view' => null]) }}"
       data-loops-view-switch-option="grid"
       @if(! $isListView) aria-current="true" @endif
       @class([
           ($compact ?? false) ? 'px-2' : 'px-3',
           'inline-flex items-center gap-1.5 py-2 font-medium transition focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-indigo-500',
           'bg-indigo-50 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300' => ! $isListView,
           'text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200' => $isListView,
       ])>
        <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true"><rect x="1.5" y="1.5" width="5.5" height="5.5" rx="1.25"/><rect x="9" y="1.5" width="5.5" height="5.5" rx="1.25"/><rect x="1.5" y="9" width="5.5" height="5.5" rx="1.25"/><rect x="9" y="9" width="5.5" height="5.5" rx="1.25"/></svg>
        <span @class(['sr-only' => $compact ?? false])>{{ __('loops.view_grid') }}</span>
    </a>
    <a href="{{ request()->fullUrlWithQuery(['view' => 'list']) }}"
       data-loops-view-switch-option="list"
       @if($isListView) aria-current="true" @endif
       @class([
           ($compact ?? false) ? 'px-2' : 'px-3',
           'inline-flex items-center gap-1.5 border-l border-gray-300 py-2 font-medium transition focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-indigo-500 dark:border-gray-600',
           'bg-indigo-50 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300' => $isListView,
           'text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200' => ! $isListView,
       ])>
        <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true"><rect x="1.5" y="2" width="13" height="2.5" rx="1"/><rect x="1.5" y="6.75" width="13" height="2.5" rx="1"/><rect x="1.5" y="11.5" width="13" height="2.5" rx="1"/></svg>
        <span @class(['sr-only' => $compact ?? false])>{{ __('loops.view_list') }}</span>
    </a>
</div>
