{{-- TASK-1685 — le panneau d'un refus INTER-TENANT, partage par `errors/404`
     et `errors/403`.

     Il existe une seule fois parce que les deux pages doivent dire la MEME
     chose : un membre refuse sur l'espace d'une autre Organization ne doit pas
     lire deux textes differents selon que la garde qui l'a arrete etait un
     controleur (404, doctrine T1601-T1606) ou une policy (403, ex.
     `Dossier::viewAny`).

     Le STATUT reste celui que l'appelant a choisi — ce partiel ne le touche
     pas. Et il ne nomme RIEN du tenant vise : ni son nom, ni son slug, ni
     aucune de ses donnees. La seule Organization qui apparait est celle du
     visiteur, par le bouton de retour.

     Attend en scope : $backUrl (string). --}}
<svg class="mx-auto mb-8 h-16 w-16 text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true" data-error-icon="lock">
    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z"/>
</svg>

<h1 class="mb-4 text-2xl sm:text-3xl font-bold tracking-tight text-white" data-error-title>
    {{ __('errors.404_cross_tenant_title') }}
</h1>

<p class="mb-3 text-base sm:text-lg leading-relaxed text-gray-300" data-error-body>
    {{ __('errors.404_cross_tenant_body') }}
</p>

<p class="mb-10 text-sm leading-relaxed text-gray-500" data-error-hint>
    {{ __('errors.404_cross_tenant_hint') }}
</p>

{{-- Un BOUTON, pas un lien discret : c'est la seule chose a faire depuis cette
     page, elle doit se voir. --}}
<a
    href="{{ $backUrl }}"
    class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-indigo-600 px-5 text-sm font-semibold text-white transition hover:bg-indigo-500"
    data-error-back
>
    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
    </svg>
    {{ __('errors.404_cross_tenant_back') }}
</a>
