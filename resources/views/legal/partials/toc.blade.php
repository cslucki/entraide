{{-- TASK-1903 — sommaire du centre institutionnel : les quatre rubriques,
     par ancres (fonctionne sans JavaScript). La rubrique affichee en mode cible
     porte aria-current.

     @param array<string, array{title: string}> $rubriques
     @param string|null $focus --}}
<nav id="sommaire" aria-labelledby="legal-toc-title"
     class="mb-8 scroll-mt-24 rounded-3xl bg-[var(--bp-panel)] p-5 text-sm text-[var(--bp-muted)] ring-1 ring-[var(--bp-border)]">
    <h2 id="legal-toc-title" class="mb-2 text-lg font-semibold text-[var(--bp-heading)]">
        {{ $focus !== null ? __('legal.center_title') : __('legal.toc_title') }}
    </h2>
    <ul class="grid gap-2 sm:grid-cols-2">
        @foreach ($rubriques as $id => $rubrique)
            <li>
                <a href="#{{ $id }}" data-legal-toc="{{ $id }}"
                   @if ($id === $focus) aria-current="location" @endif
                   class="font-medium text-indigo-600 hover:underline dark:text-indigo-400">{{ $rubrique['title'] }}</a>
            </li>
        @endforeach
    </ul>
</nav>
