{{--
    Une fenetre pour un geste ponctuel.

    Elle est rendue par Livewire et fermee par lui : `close` nomme la methode du
    composant. C'est deliberé — une fermeture purement cote navigateur laisserait
    a l'ecran la liste d'avant, alors que passer par le serveur re-rend le
    contenu dans la meme reponse.

    Alpine ne sert qu'a ce qu'on attend d'une fenetre : la touche d'echappement
    et le clic sur le fond appellent la meme methode que la croix.
--}}
@props([
    'title',
    'close',          // Nom de la methode Livewire qui ferme.
    'width' => 'max-w-lg',
])

<div
    x-data
    x-on:keydown.escape.window="$wire.{{ $close }}()"
    {{-- TASK-1769 — au CENTRE, sur tous les formats.
         `sm:items-center` ne centrait qu'a partir de 640 px : aucun telephone
         n'y arrive, et la fenetre s'ouvrait collee en bas, sous le pouce
         plutot que sous l'oeil.
         `--bp-vvh` et non `100dvh` : un `fixed inset-0` se cale sur le
         viewport de MISE EN PAGE. Android le retrecit avec le clavier, donc
         `items-center` recentre tout seul — iOS NE LE RETRECIT PAS, le clavier
         recouvre le bas de l'ecran et une fenetre centree dans 844 px se
         retrouve a moitie dessous. La variable est posee par
         `<x-visual-viewport-height>`, deja montee sur cette page. C'est le
         seul endroit de cette TASK ou une erreur ne se verrait pas en recette
         de bureau. --}}
    class="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-4"
    style="height: var(--bp-vvh, 100dvh)"
    role="dialog"
    aria-modal="true"
    aria-label="{{ $title }}"
>
    {{-- Le fond. Un bouton et non un div : fermer au clic doit aussi se faire
         au clavier, et un lecteur d'ecran doit pouvoir l'annoncer. --}}
    <button type="button"
            wire:click="{{ $close }}"
            class="absolute inset-0 bg-gray-900/45 backdrop-blur-sm"
            tabindex="-1"
            aria-label="{{ __('loops.modal_close') }}"></button>

    <div class="relative flex w-full {{ $width }} flex-col overflow-hidden rounded-3xl border border-[var(--bp-border)] bg-[var(--bp-panel)] shadow-2xl"
         style="max-height: calc(var(--bp-vvh, 85dvh) - 2rem)">
        <div class="flex shrink-0 items-center gap-3 border-b border-[var(--bp-border)] px-4 py-3">
            <h2 class="min-w-0 flex-1 truncate text-sm font-bold text-[var(--bp-text)]">{{ $title }}</h2>
            <button type="button" wire:click="{{ $close }}"
                    class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-[var(--bp-muted)] transition hover:bg-[var(--bp-surface)] hover:text-[var(--bp-text)]"
                    aria-label="{{ __('loops.modal_close') }}">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto p-4">
            {{ $slot }}
        </div>
    </div>
</div>
