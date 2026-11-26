{{-- TASK-1903 — contenu des mentions legales (editeur, directeur de la
     publication, hebergeur, open source, partenaires), deplace tel quel de
     l'ancienne page mentions-legales pour devenir la premiere rubrique du
     centre institutionnel. La rubrique « Donnees personnelles » (liens vers
     confidentialite et suppression) disparait : ces deux rubriques sont
     desormais dans la meme page.

     @param string $headingTag  niveau des titres (h3 sous la rubrique, h2 si
                                la rubrique est le titre de la page) --}}
<div class="space-y-6 text-[var(--bp-muted)] text-sm leading-relaxed">
    <section class="rounded-3xl bg-[var(--bp-panel)] p-5 ring-1 ring-[var(--bp-border)]">
        <{{ $headingTag }} class="text-lg font-semibold text-[var(--bp-heading)] mb-3">{{ __('legal.editor_title') }}</{{ $headingTag }}>
        {!! __('legal.editor_text') !!}
        <address class="not-italic mt-2 space-y-1">
            <p class="font-medium text-[var(--bp-heading)]">
                Association AMT
            </p>
            <p>31b rue Espérandieu<br>13001 Marseille FRANCE</p>
            <p>RNA : W133002043</p>
            <p>SIRET : 47874023600029</p>
            <p>Code APE : 8559A — Formation continue d'adultes</p>
            <p>N° de déclaration d'activité d'organisme de formation : 93131908813</p>
            <p class="text-sm text-[var(--bp-muted)]">auprès de la DREETS PACA</p>
        </address>
    </section>

    <section class="rounded-3xl bg-[var(--bp-panel)] p-5 ring-1 ring-[var(--bp-border)]">
        <{{ $headingTag }} class="text-lg font-semibold text-[var(--bp-heading)] mb-3">{{ __('legal.director_title') }}</{{ $headingTag }}>
        <p>Cyril SLUCKI</p>
        <p class="mt-1">
            <a href="#" onclick="this.href='mailto:'+'cyril'+'@'+'teletravailleurs.com';return true;"
               class="text-indigo-600 dark:text-indigo-400 hover:underline">cyril@teletravailleurs.com</a>
        </p>
        <p>
            <a href="tel://+33637931282"
               class="text-indigo-600 dark:text-indigo-400 hover:underline">+336 37 93 12 82</a>
        </p>
    </section>

    <section class="rounded-3xl bg-[var(--bp-panel)] p-5 ring-1 ring-[var(--bp-border)]">
        <{{ $headingTag }} class="text-lg font-semibold text-[var(--bp-heading)] mb-3">{{ __('legal.host_title') }}</{{ $headingTag }}>
        <p>
            <a href="https://cloud.laravel.com" target="_blank" rel="noopener noreferrer"
               class="text-indigo-600 dark:text-indigo-400 hover:underline">
                Laravel Cloud
            </a>
        </p>
    </section>

    <section class="rounded-3xl bg-[var(--bp-panel)] p-5 ring-1 ring-[var(--bp-border)]">
        <{{ $headingTag }} class="text-lg font-semibold text-[var(--bp-heading)] mb-3">{{ __('legal.opensource_title') }}</{{ $headingTag }}>
        <p>
            {{ __('legal.opensource_text') }}
        </p>
        <a href="https://github.com/cslucki/entraide"
           target="_blank" rel="noopener noreferrer"
           class="inline-flex items-center gap-2 mt-3 text-indigo-600 dark:text-indigo-400 hover:underline font-medium">
            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path fill-rule="evenodd" d="M12 2C6.477 2 2 6.484 2 12.017c0 4.425 2.865 8.18 6.839 9.504.5.092.682-.217.682-.483 0-.237-.008-.868-.013-1.703-2.782.605-3.369-1.343-3.369-1.343-.454-1.158-1.11-1.466-1.11-1.466-.908-.62.069-.608.069-.608 1.003.07 1.531 1.032 1.531 1.032.892 1.53 2.341 1.088 2.91.832.092-.647.35-1.088.636-1.338-2.22-.253-4.555-1.113-4.555-4.951 0-1.093.39-1.988 1.029-2.688-.103-.253-.446-1.272.098-2.65 0 0 .84-.27 2.75 1.026A9.564 9.564 0 0112 6.844c.85.004 1.705.115 2.504.337 1.909-1.296 2.747-1.027 2.747-1.027.546 1.379.202 2.398.1 2.651.64.7 1.028 1.595 1.028 2.688 0 3.848-2.339 4.695-4.566 4.942.359.31.678.921.678 1.856 0 1.338-.012 2.419-.012 2.747 0 .268.18.58.688.482A10.019 10.019 0 0022 12.017C22 6.484 17.522 2 12 2z" clip-rule="evenodd"/>
            </svg>
            github.com/cslucki/entraide
        </a>
    </section>

    <section class="rounded-3xl bg-[var(--bp-panel)] p-5 ring-1 ring-[var(--bp-border)]">
        <{{ $headingTag }} class="text-lg font-semibold text-[var(--bp-heading)] mb-3">{{ __('legal.partnership_title') }}</{{ $headingTag }}>
        <p>{{ __('legal.partnership_text') }}</p>
        <a href="{{ route('partenaires.request.create') }}"
           class="inline-flex items-center gap-2 mt-3 text-indigo-600 dark:text-indigo-400 hover:underline font-medium">
            {{ __('legal.partnership_link') }}
        </a>
    </section>
</div>
