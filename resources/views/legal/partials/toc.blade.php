{{-- TASK-1903 — sommaire des quatre pages legales, en tete de CHACUNE
     (decision Cyril), par leurs URL REELLES — jamais d'ancre `#…` — dont
     celles declarees a Google Play. La page affichee porte aria-current.

     @param array<string, array{title: string}> $rubriques
     @param array<string, string> $urls
     @param string $courante --}}
<nav aria-labelledby="legal-toc-title"
     class="mb-8 rounded-3xl bg-[var(--bp-panel)] p-5 text-sm text-[var(--bp-muted)] ring-1 ring-[var(--bp-border)]">
    <h2 id="legal-toc-title" class="mb-2 text-lg font-semibold text-[var(--bp-heading)]">
        {{ __('legal.toc_title') }}
    </h2>
    <ul class="grid gap-2 sm:grid-cols-2">
        @foreach ($rubriques as $id => $rubrique)
            <li>
                <a href="{{ $urls[$id] }}" data-legal-toc="{{ $id }}"
                   @if ($id === $courante) aria-current="page" @endif
                   class="{{ $id === $courante ? 'font-semibold underline' : 'font-medium' }} text-indigo-600 hover:underline dark:text-indigo-400">{{ $rubrique['title'] }}</a>
            </li>
        @endforeach
    </ul>
</nav>
