{{-- One named governance action. A form rather than a link: it changes state. --}}
@props(['action', 'role', 'label', 'confirm' => null, 'variant' => 'menu'])

{{-- TASK-1693 bug N — deux presentations, une seule action.

     `menu` : entree de menu deroulant, pleine largeur, alignee a gauche.
     `inline` : pastille visible posee en face du nom, pour les ecrans
     d'ADMINISTRATION — ou l'on vient precisement pour agir, et ou cacher le
     changement de role derriere un « ... » sans libelle le rendait
     introuvable.

     Aucune regle ne change : meme formulaire, meme route, memes gardes
     calculees par le roster. Seule la forme du bouton differe. --}}
<form method="POST" action="{{ $action }}" class="{{ $variant === 'inline' ? 'inline' : '' }}"
      @if($confirm) onsubmit="return confirm('{{ $confirm }}')" @endif>
    @csrf @method('PUT')
    <input type="hidden" name="role" value="{{ $role }}">
    @if($variant === 'inline')
        <button type="submit" data-governance-inline="{{ $role }}"
                class="rounded-md border border-gray-300 px-2 py-1 text-[11px] font-medium text-gray-600 transition hover:border-indigo-400 hover:bg-indigo-50 hover:text-indigo-700 focus-visible:ring-2 focus-visible:ring-indigo-500 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-indigo-900/25 dark:hover:text-indigo-200">
            {{ $label }}
        </button>
    @else
        {{-- Une entree de menu : pleine largeur, alignee a gauche, sans bordure.
             Le bouton encadre d'avant supposait qu'on l'affichait a cote d'un nom. --}}
        <button type="submit"
                class="w-full rounded-lg px-2.5 py-2 text-left text-xs font-medium text-gray-700 transition hover:bg-indigo-50 hover:text-indigo-700 focus-visible:ring-2 focus-visible:ring-indigo-500 dark:text-gray-200 dark:hover:bg-indigo-900/25 dark:hover:text-indigo-200">
            {{ $label }}
        </button>
    @endif
</form>
