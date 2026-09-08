<x-app-layout :title="$workshop->title">
    {{-- TASK-1450 — La page PUBLIQUE d'un atelier (Growth V3 §8) : titre, promesse, description, format, duree. Ni meeting_url, ni participants, ni CRM, ni CTA d'inscription inventee (B4). Pas de Shell Welcome ici (MASTER Q76). --}}
    <section class="min-h-screen bg-[var(--bp-page)] px-4 py-6 text-[var(--bp-text)] md:px-8 md:py-8">
        <article class="mx-auto max-w-3xl rounded-[2rem] border border-[var(--bp-border)] bg-[var(--bp-surface)]/80 p-6 shadow-sm md:p-10" data-workshop-page="{{ $workshop->slug }}" data-workshop-format="{{ $workshop->format }}">
            <p class="text-xs font-semibold uppercase tracking-wide text-[var(--bp-muted)]">{{ __('workshops.public_eyebrow', ['name' => $organization->name]) }}</p>
            <h1 class="mt-2 text-3xl font-bold md:text-4xl" data-workshop-title>{{ $workshop->title }}</h1>
            @if($workshop->promise)
                <p class="mt-3 text-lg text-[var(--bp-muted)]" data-workshop-promise>{{ $workshop->promise }}</p>
            @endif
            <dl class="mt-6 flex flex-wrap gap-x-6 gap-y-2 text-sm">
                <div><dt class="inline font-semibold">{{ __('workshops.col_format') }} :</dt> <dd class="inline" data-workshop-format-label>{{ __('workshops.format_'.$workshop->format) }}</dd></div>
                @if($workshop->duration_minutes)
                    <div><dt class="inline font-semibold">{{ __('workshops.col_duration') }} :</dt> <dd class="inline" data-workshop-duration>{{ __('workshops.public_duration', ['minutes' => $workshop->duration_minutes]) }}</dd></div>
                @endif
            </dl>
            @if($workshop->description)
                <div class="prose prose-sm mt-6 max-w-none whitespace-pre-line text-[var(--bp-text)]" data-workshop-description>{{ $workshop->description }}</div>
            @endif
            <p class="mt-8 rounded-xl border border-dashed border-[var(--bp-border)] px-4 py-3 text-sm text-[var(--bp-muted)]" data-workshop-sessions-soon>{{ __('workshops.public_sessions_soon') }}</p>
            <p class="mt-6"><a href="{{ route('organization.home', ['organization' => $organization->slug]) }}" class="text-sm font-semibold text-[var(--bp-muted)] hover:underline">{{ __('workshops.public_back', ['name' => $organization->name]) }}</a></p>
        </article>
    </section>
</x-app-layout>
