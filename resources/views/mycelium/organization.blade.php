{{--
    TASK-1349 — la Constitution PUBLIEE d'une organisation.

    Atteignable uniquement si l'organisation a explicitement coche l'opt-in ET
    qu'une version est active ; sinon le controleur rend 404. Ce qui est montre
    ici est volontairement pauvre : nom, texte, version, date, heritage. Ni
    doctrine, ni auteur, ni historique, ni reglage, ni interaction.
--}}
<x-app-layout :title="__('mycelium.org_title', ['name' => $organization->name])">
    <x-page-container>
        <div class="mx-auto max-w-3xl space-y-8 py-8">

            <header>
                <a href="{{ route('mycelium') }}"
                   class="inline-flex items-center gap-1.5 text-xs font-medium text-[var(--bp-muted)] transition hover:text-[var(--bp-text)]"
                   data-mycelium-back>
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                    {{ __('mycelium.org_back_to_mycelium') }}
                </a>
                <p class="mt-4 text-[11px] font-semibold uppercase tracking-[0.25em] text-[var(--bp-primary)]">{{ __('mycelium.org_subtitle') }}</p>
                <h1 class="mt-1 text-3xl font-semibold tracking-tight text-[var(--bp-text)] sm:text-4xl" data-mycelium-org-name>{{ $organization->name }}</h1>
                <p class="mt-3 text-sm text-[var(--bp-muted)]" data-mycelium-org-meta>
                    {{ __('mycelium.org_version', ['version' => $constitution->version]) }}
                    @if($constitution->activated_at)
                        · {{ __('mycelium.org_activated_at', ['date' => $constitution->activated_at->isoFormat('LL')]) }}
                    @endif
                </p>
            </header>

            {{-- Meme mise en page que dans le hub : un lecteur qui arrive ici
                 par une URL partagee doit voir EXACTEMENT le meme document. --}}
            <section class="overflow-hidden rounded-3xl border border-[var(--bp-border)] bg-[var(--bp-panel)] shadow-sm">
                <div class="h-1 w-full"
                     style="background: linear-gradient(90deg, var(--bp-primary), color-mix(in srgb, var(--bp-primary) 25%, transparent))"
                     aria-hidden="true"></div>
                <div class="p-7 sm:p-9" data-mycelium-org-text>
                    @include('mycelium.partials.document', ['text' => $constitution->body])
                </div>
            </section>

            {{-- L'heritage : le lecteur doit comprendre que ce texte ne vit pas
                 seul, et qu'il ne peut pas assouplir la racine. --}}
            <section class="rounded-2xl border border-[var(--bp-border)] bg-[var(--bp-surface)] p-6" data-mycelium-org-inheritance>
                <h2 class="text-sm font-semibold text-[var(--bp-text)]">{{ __('mycelium.org_inherits') }}</h2>
                <p class="mt-2 text-sm leading-6 text-[var(--bp-muted)]">{{ __('mycelium.inheritance_body') }}</p>
                <div class="mt-3 flex flex-wrap items-center gap-x-5 gap-y-2">
                    <a href="{{ route('mycelium') }}"
                       class="inline-flex text-xs font-semibold text-[var(--bp-primary)] hover:underline">
                        {{ __('mycelium.tree_open_root') }}
                    </a>

                    <a href="{{ route('organization.home', ['organization' => $organization->slug]) }}"
                       class="inline-flex items-center gap-1 text-xs font-semibold text-[var(--bp-primary)] hover:underline"
                       data-mycelium-organization-site="{{ $organization->slug }}">
                        {{ __('mycelium.org_visit_site') }}
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/>
                        </svg>
                    </a>
                </div>
            </section>

        </div>
    </x-page-container>
</x-app-layout>
