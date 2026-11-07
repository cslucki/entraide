{{--
    TASK-1771 — la barre d'actions du MODE SELECTION, en haut de l'ecran.

    Modele du « contextual action bar » d'Android, celui que WhatsApp
    applique : un appui long selectionne, cette barre REMPLACE la barre du
    haut, et le message selectionne est surligne. Le retour, la touche Echap
    ou un second appui sur le meme message en sortent.

    ## Cette barre ne sait RIEN faire

    Elle n'appelle aucune methode Livewire. Elle emet `bp-selection-action`,
    et la bulle selectionnee execute avec ses PROPRES gestionnaires — ceux qui
    marchent deja, y compris la confirmation de suppression qui vit dans la
    bulle.

    C'est ce qui permet de la poser identiquement sur le ChatLoop et sur la
    Messagerie, dont les composants Livewire n'exposent pas les memes methodes
    (`deleteMessage` n'existe que sur LoopChat). Une barre qui appellerait
    `$wire` directement aurait exige de les aligner.

    ## Telephone uniquement

    Au-dessus de 768 px, la rangee d'actions permanente de la bulle est
    toujours la : deux surfaces pour un meme geste rouvriraient le defaut de
    TASK-1621.
--}}
<template x-teleport="body">
    <div x-data
         x-show="$store.bpSelection.ouvert"
         x-cloak
         x-on:keydown.escape.window="$store.bpSelection.fermer()"
         {{-- TASK-1772 (recette Cyril) — « si on clique en dehors du message,
              le message est deselectionne ». Ecoute de FENETRE : la rangee
              traite son propre appui avant que celui-ci n'arrive ici, et le
              store ignore tout appui venu d'une rangee ou de cette barre. --}}
         x-on:click.window="$store.bpSelection.fermerSiDehors($event.target)"
         data-selection-bar
         {{-- TASK-1771 (demande Cyril) — la barre CHANGE DE COULEUR.
              Elle recouvre la barre du haut : si elle lui ressemble, on ne
              voit pas qu'on a change de mode, et on cherche le nom de la
              Boucle qui n'y est plus. Elle prend donc la couleur d'action du
              theme, et tout ce qu'elle porte passe en blanc.
              En style EN LIGNE, et pas en classe : `bg-[var(--bp-primary)]`
              est une valeur arbitraire batie sur un jeton — exactement ce qui
              a rendu le surlignage transparent plus tot dans cette meme TASK,
              et ce que TASK-1475 avait deja paye. --}}
         style="background-color: var(--bp-primary)"
         class="fixed inset-x-0 top-0 z-[70] flex h-[45px] items-center gap-1 px-2 text-white shadow-md md:hidden"
         role="toolbar"
         aria-label="{{ __('messages.message_actions') }}">

        {{-- Sortir. A gauche, a la place exacte du « retour » de la barre
             qu'elle recouvre : le pouce n'a pas a apprendre un second
             emplacement. --}}
        <button type="button"
                data-selection-exit
                x-on:click="$store.bpSelection.fermer()"
                class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl text-white/90 transition hover:bg-white/15 hover:text-white"
                aria-label="{{ __('messages.selection_exit') }}">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
        </button>

        {{-- TASK-1772 (recette Cyril) — la barre NOMME l'auteur du message
             selectionne (« Moi », ou le nom affiche). Avec « 1 message
             selectionne », passer d'un message a un autre ne changeait
             visiblement que l'anneau de la bulle : les trois premieres icones
             sont les memes pour tous, et la barre semblait « scotchee » au
             premier message. Le nom change a chaque appui, et dit tout de
             suite qu'on agit sur SON message ou sur celui d'un autre. --}}
        <span class="min-w-0 flex-1 truncate text-sm font-semibold text-white"
              data-selection-author
              x-text="$store.bpSelection.auteur || @js(__('messages.selection_one'))"></span>

        {{-- Les actions. Chacune ne s'affiche que si la bulle selectionnee l'a
             declaree : c'est elle qui connait les droits, pas cette barre. --}}
        <template x-if="$store.bpSelection.actions.reply">
            <button type="button" data-selection-action="reply"
                    x-on:click="$dispatch('bp-selection-action', { id: $store.bpSelection.id, action: 'reply' })"
                    class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl text-white/90 transition hover:bg-white/15 hover:text-white"
                    aria-label="{{ __('messages.reply') }}" title="{{ __('messages.reply') }}">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3"/></svg>
            </button>
        </template>

        <template x-if="$store.bpSelection.actions.pin">
            <button type="button" data-selection-action="pin"
                    x-on:click="$dispatch('bp-selection-action', { id: $store.bpSelection.id, action: 'pin' })"
                    class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl text-white/90 transition hover:bg-white/15 hover:text-white"
                    x-bind:aria-label="$store.bpSelection.actions.pinned ? @js(__('messages.unpin')) : @js(__('messages.pin'))"
                    x-bind:title="$store.bpSelection.actions.pinned ? @js(__('messages.unpin')) : @js(__('messages.pin'))">
                {{-- TASK-1771 — `x-bind:d` et NON deux `<template x-if>` imbriques.
                     Alpine clone le contenu d'un `<template>` avec `cloneNode`
                     sur son `content.firstElementChild` : a l'interieur d'un
                     `<svg>`, ce noeud n'est pas celui qu'il attend, et l'erreur
                     remonte en « Cannot read properties of undefined (reading
                     'cloneNode') ». Mesuree sur la page : deux `PAGEERROR` par
                     rendu. Une erreur Alpine interrompt le traitement des
                     directives qui suivent — c'est ce qui faisait disparaitre
                     le surlignage. --}}
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          x-bind:d="$store.bpSelection.actions.pinned
                              ? 'm3 3 18 18M8 6.5V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v7l2 3H8.5M12 18v3'
                              : 'M8 3h8a2 2 0 0 1 2 2v7l2 3H4l2-3V5a2 2 0 0 1 2-2Zm4 12v6'"/>
                </svg>
            </button>
        </template>

        <template x-if="$store.bpSelection.actions.copy">
            <button type="button" data-selection-action="copy"
                    x-on:click="$store.bpSelection.copier(); $store.bpSelection.fermer()"
                    class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl text-white/90 transition hover:bg-white/15 hover:text-white"
                    aria-label="{{ __('messages.copy') }}" title="{{ __('messages.copy') }}">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="11" height="11" rx="2" stroke-linecap="round" stroke-linejoin="round"/><rect x="4" y="4" width="11" height="11" rx="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
        </template>

        <template x-if="$store.bpSelection.actions.edit">
            <button type="button" data-selection-action="edit"
                    x-on:click="$dispatch('bp-selection-action', { id: $store.bpSelection.id, action: 'edit' })"
                    class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl text-white/90 transition hover:bg-white/15 hover:text-white"
                    aria-label="{{ __('messages.edit') }}" title="{{ __('messages.edit') }}">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487a2.1 2.1 0 1 1 2.97 2.97L8.63 18.66l-4.243.53.53-4.243L16.862 4.487Z"/></svg>
            </button>
        </template>

        <template x-if="$store.bpSelection.actions.delete">
            <button type="button" data-selection-action="delete"
                    x-on:click="$dispatch('bp-selection-action', { id: $store.bpSelection.id, action: 'delete' })"
                    class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl text-white/90 transition hover:bg-white/20 hover:text-white"
                    aria-label="{{ __('messages.delete') }}" title="{{ __('messages.delete') }}">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166M19.228 5.79 18.16 19.673A2.25 2.25 0 0 1 15.916 21H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0"/></svg>
            </button>
        </template>
    </div>
</template>
