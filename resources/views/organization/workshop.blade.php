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
            {{-- TASK-1451 (B4-A) : les sessions publiees a venir — informatives ; l'inscription vient par son propre flux. --}}
            @if($sessions->isNotEmpty())
                <h2 class="mt-8 text-lg font-semibold" data-workshop-sessions-title>{{ __('workshops.public_sessions_title') }}</h2>
                <ul class="mt-3 space-y-2">
                    @foreach($sessions as $session)
                    <li class="rounded-xl border border-[var(--bp-border)] px-4 py-3 text-sm" data-workshop-session="{{ $session->id }}">
                        <time datetime="{{ $session->starts_at->toIso8601String() }}" class="font-semibold" data-workshop-session-start>{{ $session->localStartsAt()->translatedFormat(__('workshops.public_session_date_format')) }}</time>
                        @if($session->localEndsAt())<span class="text-[var(--bp-muted)]" data-workshop-session-end> → {{ $session->localEndsAt()->translatedFormat(__('workshops.public_session_time_format')) }}</span>@endif
                        <span class="text-[var(--bp-muted)]">({{ $session->timezone }})</span>
                        @if($session->location)<span class="block text-[var(--bp-muted)]" data-workshop-session-location>{{ $session->location }}</span>@endif
                        @if($session->capacity)<span class="block text-xs text-[var(--bp-muted)]" data-workshop-session-capacity>{{ __('workshops.public_session_capacity', ['count' => $session->capacity]) }}</span>@endif
                        {{-- TASK-1452 (B4-B) : « je choisis cette session » — un interet Guest, jamais une inscription. --}}
                        @if($canSelect)
                            @if(in_array($session->id, $selectedSessionIds, true))
                                <form method="POST" action="{{ route('organization.workshop.session.interest.withdraw', ['organization' => $organization->slug, 'workshop' => $workshop->slug, 'session' => $session->id]) }}" class="mt-2" data-workshop-interest-withdraw="{{ $session->id }}">
                                    @csrf @method('DELETE')
                                    <span class="mr-2 inline-block rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-800" data-workshop-interest-selected>{{ __('workshops.public_interest_selected') }}</span>
                                    <button type="submit" class="text-xs text-[var(--bp-muted)] underline">{{ __('workshops.public_interest_withdraw') }}</button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('organization.workshop.session.interest', ['organization' => $organization->slug, 'workshop' => $workshop->slug, 'session' => $session->id]) }}" class="mt-2" data-workshop-interest="{{ $session->id }}">
                                    @csrf
                                    <input type="hidden" name="attribution[shortcut]" value="{{ request()->query('shortcut') }}">
                                    <input type="hidden" name="attribution[utm_source]" value="{{ request()->query('utm_source') }}">
                                    <input type="hidden" name="attribution[utm_medium]" value="{{ request()->query('utm_medium') }}">
                                    <input type="hidden" name="attribution[utm_campaign]" value="{{ request()->query('utm_campaign') }}">
                                    <button type="submit" class="rounded-full bg-indigo-600 px-4 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700">{{ __('workshops.public_interest_select') }}</button>
                                </form>
                            @endif
                        @endif
                    </li>
                    @endforeach
                </ul>
                {{-- MASTER Q79 : le choix est memorise, aucune promesse « compte = participation confirmee » avant le flux Registration ; le lien « Creer un compte » existe car creer un compte est reellement possible. --}}
                @if(session('workshop_interest'))<p class="mt-3 rounded-xl bg-emerald-50 px-4 py-2 text-sm text-emerald-800" data-workshop-interest-flash>{{ __('workshops.public_interest_flash') }} <a href="{{ route('organization.register', ['organization' => $organization->slug]) }}" class="font-semibold underline" data-workshop-interest-account>{{ __('workshops.public_interest_account') }}</a></p>@endif
                <p class="mt-3 text-xs text-[var(--bp-muted)]" data-workshop-registration-soon>{{ __('workshops.public_registration_soon') }}</p>
            @else
                <p class="mt-8 rounded-xl border border-dashed border-[var(--bp-border)] px-4 py-3 text-sm text-[var(--bp-muted)]" data-workshop-sessions-soon>{{ __('workshops.public_sessions_soon') }}</p>
            @endif
            <p class="mt-6"><a href="{{ route('organization.home', ['organization' => $organization->slug]) }}" class="text-sm font-semibold text-[var(--bp-muted)] hover:underline">{{ __('workshops.public_back', ['name' => $organization->name]) }}</a></p>
        </article>
    </section>
</x-app-layout>
