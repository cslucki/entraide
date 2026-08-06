{{-- One named governance action. A form rather than a link: it changes state. --}}
@props(['action', 'role', 'label', 'confirm' => null])

<form method="POST" action="{{ $action }}"
      @if($confirm) onsubmit="return confirm('{{ $confirm }}')" @endif>
    @csrf @method('PUT')
    <input type="hidden" name="role" value="{{ $role }}">
    {{-- Une entree de menu : pleine largeur, alignee a gauche, sans bordure.
         Le bouton encadre d'avant supposait qu'on l'affichait a cote d'un nom. --}}
    <button type="submit"
            class="w-full rounded-lg px-2.5 py-2 text-left text-xs font-medium text-gray-700 transition hover:bg-indigo-50 hover:text-indigo-700 focus-visible:ring-2 focus-visible:ring-indigo-500 dark:text-gray-200 dark:hover:bg-indigo-900/25 dark:hover:text-indigo-200">
        {{ $label }}
    </button>
</form>
