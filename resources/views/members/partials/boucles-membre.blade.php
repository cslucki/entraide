{{--
    TASK-1762 point 14 — les Boucles d'un membre, pliees au-dela de trois.

    ## Un partiel, parce que DEUX presentations le rendent

    La carte et la ligne « Nouveaux arrivés » affichaient chacune leur copie du
    bloc — deja identiques au gap pres, donc promises a diverger. Le pli du
    point 14 aurait du etre ecrit deux fois ; il est ecrit ici une fois.

    ## La regle d'affichage, arbitree par Cyril le 05/11

    - Trois Boucles ou moins : tous les badges, comme avant.
    - Au-dela : les TROIS premieres + une pastille « +N » en accent — jamais
      plus de QUATRE pastilles, sa limite. Le survol de la pastille liste les
      noms caches ; le clic deplie les badges SUR PLACE, sans quitter
      l'annuaire, et « réduire » referme.

    ## Sans JavaScript : tout est visible

    `x-show` ne retire rien du DOM. Si Alpine ne demarre pas, les badges
    replies s'affichent tous — l'etat degrade montre PLUS, jamais moins.

    Attend : $boucles, $member, $orgParamAnnuaire, $gap (classes du conteneur).
--}}
@php
    $bouclesVisibles = $boucles->take(3);
    $bouclesRepliees = $boucles->slice(3);
@endphp
{{--
    TASK-1812 correctif 05 — demande de Cyril : la meme icone « Boucles » que
    le rail gauche, EN PETIT, comme repere visuel a cote des noms de Boucles.
    Decorative : le `title` du conteneur et le nom de chaque badge portent
    deja l'information, `aria-hidden` evite de la lire deux fois au lecteur
    d'ecran.
--}}
<div class="flex flex-wrap items-center {{ $gap ?? 'gap-1' }}" title="{{ __('directory.loops_title') }}"
     data-member-loops="{{ $member->id }}"
     @if($bouclesRepliees->isNotEmpty()) x-data="{ deplie: false }" @endif>
    <svg class="h-3.5 w-3.5 shrink-0 text-violet-400 dark:text-violet-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ config('navigation_icons.loops') }}"/></svg>
    @foreach($bouclesVisibles as $boucle)
    <a href="{{ $orgParamAnnuaire && Route::has('organization.loops.show')
            ? route('organization.loops.show', ['organization' => $orgParamAnnuaire, 'loop' => $boucle->slug ?: $boucle->getKey()])
            : route('loops.show', $boucle->slug ?: $boucle->getKey()) }}"
       data-member-loop="{{ $boucle->id }}"
       class="px-2 py-0.5 bg-violet-50 dark:bg-violet-900/20 text-violet-700 dark:text-violet-300 rounded text-xs font-medium hover:bg-violet-100 dark:hover:bg-violet-900/40 transition">
        {{ $boucle->name }}
    </a>
    @endforeach

    @if($bouclesRepliees->isNotEmpty())
    @foreach($bouclesRepliees as $boucle)
    <a href="{{ $orgParamAnnuaire && Route::has('organization.loops.show')
            ? route('organization.loops.show', ['organization' => $orgParamAnnuaire, 'loop' => $boucle->slug ?: $boucle->getKey()])
            : route('loops.show', $boucle->slug ?: $boucle->getKey()) }}"
       data-member-loop="{{ $boucle->id }}" data-member-loop-replie
       x-show="deplie" x-cloak
       class="px-2 py-0.5 bg-violet-50 dark:bg-violet-900/20 text-violet-700 dark:text-violet-300 rounded text-xs font-medium hover:bg-violet-100 dark:hover:bg-violet-900/40 transition">
        {{ $boucle->name }}
    </a>
    @endforeach

    {{-- La pastille dit COMBIEN, l'infobulle dit QUI : on peut jeter un oeil
         sans cliquer. `<button>` et non `<a>` : ce geste ne mene nulle part. --}}
    <button type="button" x-show="! deplie" @click="deplie = true"
            data-member-loops-plus="{{ $member->id }}"
            title="{{ $bouclesRepliees->pluck('name')->implode(', ') }}"
            aria-label="{{ trans_choice('directory.loops_more', $bouclesRepliees->count(), ['count' => $bouclesRepliees->count()]) }} : {{ $bouclesRepliees->pluck('name')->implode(', ') }}"
            class="px-2 py-0.5 rounded text-xs font-semibold bg-[var(--bp-accent)]/10 text-[var(--bp-accent)] hover:bg-[var(--bp-accent)]/20 transition cursor-pointer">
        +{{ $bouclesRepliees->count() }}
    </button>
    <button type="button" x-show="deplie" x-cloak @click="deplie = false"
            data-member-loops-reduire
            class="px-2 py-0.5 rounded text-xs font-medium text-gray-400 hover:text-[var(--bp-accent)] transition cursor-pointer">
        {{ __('directory.loops_reduce') }}
    </button>
    @endif
</div>
