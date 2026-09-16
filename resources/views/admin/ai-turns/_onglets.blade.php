{{-- TASK-1585 — les deux fonctions de l'Inspector : lire, ou produire puis lire. --}}
<nav class="flex gap-2 text-sm" data-inspector-tabs>
    <a href="{{ route('admin.ai-turns') }}"
       class="px-3 py-1.5 rounded-lg border {{ $actif === 'observer' ? 'bg-indigo-600 border-indigo-600 text-white' : 'border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700' }}"
       @if ($actif === 'observer') aria-current="page" @endif>Observer</a>
    <a href="{{ route('admin.ai-turns.test') }}"
       class="px-3 py-1.5 rounded-lg border {{ $actif === 'tester' ? 'bg-indigo-600 border-indigo-600 text-white' : 'border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700' }}"
       @if ($actif === 'tester') aria-current="page" @endif>Tester une requête</a>
</nav>
