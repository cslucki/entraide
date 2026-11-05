@props(['actif' => null, 'url'])

{{--
    TASK-1755 §5.2 (maquette M3) — les pastilles de filtre.

    Quatre pastilles et non des onglets : la liste reste la meme, on la
    restreint. Un onglet promettrait des contenus differents.

    La definition d'un filtre vit dans `CarteEntraide::correspondAuFiltre()`,
    que le controleur applique et que ce composant se contente de NOMMER. Si la
    pastille et la liste pouvaient diverger, ce serait pire que pas de filtre.
--}}
<div class="flex flex-wrap gap-1.5 px-4 pb-3" data-conversation-filtres>
    @foreach(\App\Support\Entraide\CarteEntraide::FILTRES as $_f)
        @php $_estActif = ($actif ?? 'tous') === $_f; @endphp
        <a href="{{ $url($_f) }}"
           data-conversation-filtre="{{ $_f }}"
           @if($_estActif) aria-current="page" @endif
           class="rounded-full px-3 py-1 text-xs font-medium transition
               {{ $_estActif
                   ? 'bg-indigo-600 text-white'
                   : 'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600' }}">
            {{ __('messages.filtres.'.$_f) }}
        </a>
    @endforeach
</div>
