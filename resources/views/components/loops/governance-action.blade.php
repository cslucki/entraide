{{-- One named governance action. A form rather than a link: it changes state. --}}
@props(['action', 'role', 'label', 'confirm' => null])

<form method="POST" action="{{ $action }}"
      @if($confirm) onsubmit="return confirm('{{ $confirm }}')" @endif>
    @csrf @method('PUT')
    <input type="hidden" name="role" value="{{ $role }}">
    <button type="submit"
            class="min-h-[44px] rounded-lg border border-gray-200 px-2.5 text-xs font-medium text-gray-700 transition hover:border-indigo-300 hover:text-indigo-700 focus-visible:ring-2 focus-visible:ring-indigo-500 dark:border-gray-600 dark:text-gray-200 dark:hover:border-indigo-500 dark:hover:text-indigo-300">
        {{ $label }}
    </button>
</form>
