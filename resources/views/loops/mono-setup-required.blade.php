<x-app-layout>
    <section class="min-h-screen bg-[var(--bp-page)] px-4 py-6 text-[var(--bp-text)]">
        <div class="mx-auto max-w-2xl text-center py-16">
            <div class="w-16 h-16 mx-auto mb-6 rounded-2xl bg-[color-mix(in_srgb,var(--bp-primary)_12%,transparent)] flex items-center justify-center">
                <svg class="w-8 h-8 text-[var(--bp-primary)]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 10h8M8 14h5m8-2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
            <h1 class="text-2xl font-bold mb-3">Configuration mono-boucle</h1>
            <p class="text-[var(--bp-muted)] mb-8 max-w-md mx-auto">
                Votre organisation utilise le mode mono-boucle&nbsp;: un seul espace de collaboration est accessible aux membres. Un administrateur doit définir la Boucle principale dans les paramètres de l'organisation.
            </p>
            <div class="space-y-4 mx-auto max-w-lg">
                <div class="bg-[var(--bp-panel)] rounded-2xl border border-[var(--bp-border)] p-6 text-left space-y-4">
                    <h2 class="font-semibold">Qu'est-ce qu'une boucle ?</h2>
                    <p class="text-sm text-[var(--bp-muted)]">Une boucle est un espace de collaboration où les membres échangent des messages, posent des questions et s'entraident. Dans votre organisation, une seule boucle centrale est utilisée.</p>
                    <div class="border-t border-[var(--bp-border)] pt-4">
                        <p class="text-sm text-[var(--bp-muted)]">Pour activer votre boucle, connectez-vous en tant qu'administrateur et définissez la Boucle principale depuis les paramètres de l'organisation.</p>
                        <a href="{{ route('help') }}" class="inline-flex mt-4 text-sm font-medium text-[var(--bp-primary)] hover:underline">En savoir plus →</a>
                    </div>
                </div>

                <div class="bg-[var(--bp-panel)] rounded-2xl border border-[var(--bp-border)] p-6 text-left space-y-4">
                    <h2 class="font-semibold">Créer une organisation</h2>
                    <p class="text-sm text-[var(--bp-muted)]">Si vous n'avez pas encore d'organisation, les Boucles ne peuvent pas être configurées. Rendez-vous dans votre <a href="{{ route('profile.edit') }}" class="text-[var(--bp-primary)] hover:underline">Espace paramètres</a> ou contactez votre administrateur pour créer ou activer une organisation.</p>
                    <div class="border-t border-[var(--bp-border)] pt-4">
                        <p class="text-sm text-[var(--bp-muted)]">Besoin d'aide pour créer votre organisation&nbsp;? Consultez les ressources disponibles ou contactez l'équipe BouclePro.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>
</x-app-layout>
