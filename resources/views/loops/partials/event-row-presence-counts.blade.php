{{--
    Les deux pastilles icone+chiffre (participants, interesses) de la tuile
    presences — partagees par le declencheur CLIQUABLE du pop-up (agenda) et
    la version STATIQUE (Card, deja geree par `toggleAttendees()`). Un seul
    balisage pour les deux, jamais deux copies a faire diverger.

    TASK-1802 (suite, demande Cyril) — « ca se met a jour sans rafraichir » :
    sur l'agenda, ces chiffres suivent maintenant l'etat Alpine
    (`going`/`maybe`, mis a jour de facon optimiste par `respond()`, voir
    `agenda.blade.php`) plutot que la valeur figee au chargement. `$reactive`
    reste faux partout ailleurs (Card) : rien n'y change.
--}}
@php($reactive = $reactive ?? false)
<span class="flex items-center gap-1 text-sm font-semibold text-emerald-700 dark:text-emerald-300">
    <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z"/></svg>
    @if($reactive)
        <span x-text="going">{{ $event['counts']['going'] }}</span>
    @else
        <span>{{ $event['counts']['going'] }}</span>
    @endif
</span>
<span class="flex items-center gap-1 text-sm font-semibold text-amber-700 dark:text-amber-300">
    <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z"/></svg>
    @if($reactive)
        <span x-text="maybe">{{ $event['counts']['maybe'] }}</span>
    @else
        <span>{{ $event['counts']['maybe'] }}</span>
    @endif
</span>
