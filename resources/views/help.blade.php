<x-app-layout title="Aide">
    <x-page-container>
        <div class="mx-auto max-w-4xl rounded-[2rem] border border-[var(--bp-border)] bg-[var(--bp-surface)]/90 p-6 shadow-sm backdrop-blur md:p-10">
            <div class="mb-8">
                <p class="mb-3 inline-flex rounded-full bg-[color-mix(in_srgb,var(--bp-primary)_12%,transparent)] px-3 py-1 text-xs font-semibold uppercase tracking-[0.2em] text-[var(--bp-primary)]">
                    Centre d'aide
                </p>
                <h1 class="text-3xl font-semibold tracking-tight md:text-5xl">Comprendre BouclePro</h1>
                <p class="mt-4 max-w-2xl text-base leading-7 text-[var(--bp-muted)]">
                    Cette page présente les grands principes de la plateforme. Le contenu détaillé sera ajusté progressivement.
                </p>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <article class="rounded-3xl bg-[var(--bp-panel)] p-5 ring-1 ring-[var(--bp-border)]">
                    <h2 class="text-lg font-semibold">1. Rejoindre une boucle</h2>
                    <p class="mt-3 text-sm leading-6 text-[var(--bp-muted)]">
                        Les boucles rassemblent les membres autour d'un espace commun pour échanger, poser des questions et avancer ensemble.
                    </p>
                </article>

                <article class="rounded-3xl bg-[var(--bp-panel)] p-5 ring-1 ring-[var(--bp-border)]">
                    <h2 class="text-lg font-semibold">2. Proposer ou demander un service</h2>
                    <p class="mt-3 text-sm leading-6 text-[var(--bp-muted)]">
                        Chaque membre peut rendre visible ce qu'il sait faire ou exprimer un besoin afin de faciliter les mises en relation utiles.
                    </p>
                </article>

                <article class="rounded-3xl bg-[var(--bp-panel)] p-5 ring-1 ring-[var(--bp-border)]">
                    <h2 class="text-lg font-semibold">3. Echanger simplement</h2>
                    <p class="mt-3 text-sm leading-6 text-[var(--bp-muted)]">
                        Les échanges se construisent par messages, demandes d'aide et interactions directes entre membres de l'organisation.
                    </p>
                </article>

                <article class="rounded-3xl bg-[var(--bp-panel)] p-5 ring-1 ring-[var(--bp-border)]">
                    <h2 class="text-lg font-semibold">4. Suivre son activité</h2>
                    <p class="mt-3 text-sm leading-6 text-[var(--bp-muted)]">
                        Le profil, les points, les favoris et l'historique permettent de garder une vision claire de sa participation.
                    </p>
                </article>
            </div>
        </div>
    </x-page-container>
</x-app-layout>
