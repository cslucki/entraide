{{-- TASK-1903 — sommaire du centre institutionnel : les quatre rubriques,
     chacune vers son URL DEDIEE (decision Cyril : jamais d'ancre `#…`), donc
     les URL declarees a Google Play. La rubrique affichee porte aria-current.

     @param array<string, array{title: string}> $rubriques
     @param array<string, string> $urls
     @param string|null $focus --}}
<nav aria-labelledby="legal-toc-title"
     class="mb-8 rounded-3xl bg-[var(--bp-panel)] p-5 text-sm text-[var(--bp-muted)] ring-1 ring-[var(--bp-border)]">
    <h2 id="legal-toc-title" class="mb-2 text-lg font-semibold text-[var(--bp-heading)]">
        {{ $focus !== null ? __('legal.center_title') : __('legal.toc_title') }}
    </h2>
    <ul class="grid gap-2 sm:grid-cols-2">
        @foreach ($rubriques as $id => $rubrique)
            <li>
                <a href="{{ $urls[$id] }}" data-legal-toc="{{ $id }}"
                   @if ($id === $focus) aria-current="page" @endif
                   class="font-medium text-indigo-600 hover:underline dark:text-indigo-400">{{ $rubrique['title'] }}</a>
            </li>
        @endforeach
    </ul>
</nav>
