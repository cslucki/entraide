<x-app-layout title="{{ __('help.title') }}">
    <x-page-container>
        <div class="mx-auto max-w-4xl rounded-[2rem] border border-[var(--bp-border)] bg-[var(--bp-surface)]/90 p-6 shadow-sm backdrop-blur md:p-10">
            <div class="mb-8">
                <p class="mb-3 inline-flex rounded-full bg-[color-mix(in_srgb,var(--bp-primary)_12%,transparent)] px-3 py-1 text-xs font-semibold uppercase tracking-[0.2em] text-[var(--bp-primary)]">
                    {{ __('help.badge') }}
                </p>
                <h1 class="text-3xl font-semibold tracking-tight md:text-5xl">{{ __('help.title') }}</h1>
                <p class="mt-4 max-w-2xl text-base leading-7 text-[var(--bp-muted)]">
                    {{ __('help.intro') }}
                </p>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <article class="rounded-3xl bg-[var(--bp-panel)] p-5 ring-1 ring-[var(--bp-border)]">
                    <h2 class="text-lg font-semibold">{{ __('help.section1_title') }}</h2>
                    <p class="mt-3 text-sm leading-6 text-[var(--bp-muted)]">
                        {{ __('help.section1_body') }}
                    </p>
                </article>

                <article class="rounded-3xl bg-[var(--bp-panel)] p-5 ring-1 ring-[var(--bp-border)]">
                    <h2 class="text-lg font-semibold">{{ __('help.section2_title') }}</h2>
                    <p class="mt-3 text-sm leading-6 text-[var(--bp-muted)]">
                        {{ __('help.section2_body') }}
                    </p>
                </article>

                <article class="rounded-3xl bg-[var(--bp-panel)] p-5 ring-1 ring-[var(--bp-border)]">
                    <h2 class="text-lg font-semibold">{{ __('help.section3_title') }}</h2>
                    <p class="mt-3 text-sm leading-6 text-[var(--bp-muted)]">
                        {{ __('help.section3_body') }}
                    </p>
                </article>

                <article class="rounded-3xl bg-[var(--bp-panel)] p-5 ring-1 ring-[var(--bp-border)]">
                    <h2 class="text-lg font-semibold">{{ __('help.section4_title') }}</h2>
                    <p class="mt-3 text-sm leading-6 text-[var(--bp-muted)]">
                        {{ __('help.section4_body') }}
                    </p>
                </article>
            </div>
        </div>
    </x-page-container>
</x-app-layout>
