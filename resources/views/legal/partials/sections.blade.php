{{-- TASK-1898 — rendu commun des pages legales publiques (confidentialite,
     suppression de compte). Les textes vivent dans lang/{fr,en}/ ; ici, aucun
     texte en dur. Chaque section peut porter : `paragraphs`, `items`
     (liste a puces), `steps` (liste numerotee), `after` (paragraphes apres
     la liste) et `link` (clef de $links : lien interne en fin de section).
     TASK-1903 — `id` (facultatif) : ancre de la section, pour un sommaire ;
     $headingTag (defaut h2) : niveau du titre de section, h3 sous une rubrique
     du centre institutionnel.

     @param array $sections
     @param array<string, array{url: string, label: string}> $links
     @param string $headingTag --}}
@php($headingTag = $headingTag ?? 'h2')
<div class="space-y-6 text-[var(--bp-muted)] text-sm leading-relaxed">
    @foreach ($sections as $section)
        <section @isset($section['id']) id="{{ $section['id'] }}" @endisset class="scroll-mt-24 rounded-3xl bg-[var(--bp-panel)] p-5 ring-1 ring-[var(--bp-border)]">
            <{{ $headingTag }} class="text-lg font-semibold text-[var(--bp-heading)] mb-3">{{ $section['title'] }}</{{ $headingTag }}>

            @foreach ($section['paragraphs'] ?? [] as $paragraph)
                <p class="mt-2 first:mt-0">{{ $paragraph }}</p>
            @endforeach

            @if (! empty($section['steps']))
                <ol class="mt-2 list-decimal space-y-1 pl-5">
                    @foreach ($section['steps'] as $step)
                        <li>{{ $step }}</li>
                    @endforeach
                </ol>
            @endif

            @if (! empty($section['items']))
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    @foreach ($section['items'] as $item)
                        <li>{{ $item }}</li>
                    @endforeach
                </ul>
            @endif

            @foreach ($section['after'] ?? [] as $paragraph)
                <p class="mt-3">{{ $paragraph }}</p>
            @endforeach

            @if (isset($section['link'], $links[$section['link']]))
                <a href="{{ $links[$section['link']]['url'] }}"
                   class="inline-flex items-center gap-2 mt-3 text-indigo-600 dark:text-indigo-400 hover:underline font-medium">
                    {{ $links[$section['link']]['label'] }} →
                </a>
            @endif
        </section>
    @endforeach
</div>
