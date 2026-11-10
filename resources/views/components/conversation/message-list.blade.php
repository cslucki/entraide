@props([
    'autoScroll' => true,
    'hasMessages' => null,
    'wireKeyPrefix' => 'msg',
])

@php
    $shouldShowEmpty = $hasMessages === null
        ? (empty($messages) || ($messages instanceof \Illuminate\Support\Collection && $messages->isEmpty()))
        : ! $hasMessages;
@endphp

<div
    @if($autoScroll)
        x-data="{ atBottom: true, observer: null, preservingScroll: false, previousScrollHeight: 0 }"
        x-init="
            $el.scrollTop = $el.scrollHeight;
            observer = new MutationObserver(() => {
                if (atBottom) requestAnimationFrame(() => $el.scrollTop = $el.scrollHeight);
            });
            observer.observe($el, { childList: true, subtree: true });
        "
        x-on:scroll="atBottom = ($el.scrollTop + $el.clientHeight >= $el.scrollHeight - 10)"
        x-on:loading-older.window="previousScrollHeight = $el.scrollHeight; preservingScroll = true"
        x-on:older-messages-loaded.window="requestAnimationFrame(() => { if (preservingScroll) { $el.scrollTop = $el.scrollHeight - previousScrollHeight; preservingScroll = false } })"
        {{-- TASK-1801 correctif 06 (demande de Cyril, « Derniers messages »
             utilise ce lien bien plus souvent qu'avant) — `scrollIntoView()`
             EST programmatique ne respecte pas `overflow: hidden` : l'appel
             natif peut encore deplacer le scroll d'un ANCETRE (ici `body`),
             meme si celui-ci n'accepte plus le defilement de l'UTILISATEUR.
             Sur telephone, ce seul pixel de defilement DOCUMENT suffit a
             retracter la barre d'URL de Chrome — exactement ce que
             `loops/show.blade.php` (TASK-1684) s'assurait de ne JAMAIS
             declencher, puisque l'en-tete et le composeur fixes en
             dependent. On ajuste donc UNIQUEMENT `$el.scrollTop` (ce
             conteneur, connu et deja borne) plutot que de laisser le
             navigateur remonter toute la chaine de conteneurs defilants. --}}
        x-on:scroll-to-message.window="requestAnimationFrame(() => { const target = document.getElementById('loop-message-' + $event.detail.messageId); if (target) { const conteneur = $el.getBoundingClientRect(); const cible = target.getBoundingClientRect(); $el.scrollTop += (cible.top - conteneur.top) - 16; target.classList.add('ring-2', 'ring-violet-400', 'rounded-xl'); setTimeout(() => target.classList.remove('ring-2', 'ring-violet-400', 'rounded-xl'), 1800) } })"
    @endif
    {{-- TASK-1772 (recette Cyril, telephone) — `overflow-x-hidden` : le fil
         se laissait faire defiler HORIZONTALEMENT. Mesure a 390 px : le
         conteneur avait 402 px de contenu pour 390 de large. Le coupable
         est le bouton de reaction, pose en absolu a 1,75 rem HORS de la
         bulle (`right: -1.75rem`) : sur une bulle recue a sa largeur
         maximale, il depasse du bord. Invisible (opacite 0) mais present —
         l'opacite ne retire rien a la mise en page. Le fil ne defile que
         verticalement, par contrat. --}}
    {{ $attributes->merge(['class' => 'flex-1 overflow-y-auto overflow-x-hidden min-h-0 px-4 py-4 space-y-1', 'style' => 'scrollbar-width: thin;']) }}
>
    @if(isset($messages))
        {{ $messages }}
    @endif

    @if(isset($empty) && $shouldShowEmpty)
        {{ $empty }}
    @endif

    @if(isset($after))
        {{ $after }}
    @endif
</div>
