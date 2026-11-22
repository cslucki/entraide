<x-admin-layout title="Envoi de la newsletter">
    {{-- TASK-1883 — progression : la page met les lots de 50 en file l'un apres
         l'autre (Managed Queue quand elle est configuree), puis relit l'etat
         jusqu'a la derniere livraison. Quitter la page suspend la mise en file ;
         y revenir la reprend la ou elle en etait. --}}
    <div class="max-w-3xl space-y-6" data-admin-newsletter-progress
         x-data="{
            etat: @js($progression),
            arret: false,
            get traites() { return this.etat.sent + this.etat.failed + this.etat.ambiguous + this.etat.skipped; },
            get pourcent() { return this.etat.total > 0 ? Math.round(this.traites / this.etat.total * 100) : 100; },
            async appel(url, methode) {
                const r = await fetch(url, { method: methode, headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': @js(csrf_token()) } });
                if (!r.ok) { this.arret = true; return; }
                this.etat = await r.json();
            },
            async avancer() {
                while (!this.arret && !this.etat.done) {
                    if (this.etat.pending > 0) {
                        await this.appel(@js(route('admin.newsletter.batch', $send)), 'POST');
                    } else {
                        await new Promise(r => setTimeout(r, 2000));
                        await this.appel(@js(route('admin.newsletter.progress', $send)), 'GET');
                    }
                }
            },
         }"
         x-init="avancer()">
        <a href="{{ route('admin.newsletter') }}" class="text-sm text-indigo-600 hover:underline dark:text-indigo-400">&larr; Newsletter</a>

        <div class="rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
            <p class="text-sm text-gray-600 dark:text-gray-300">
                {{ $send->created_at->timezone('Europe/Paris')->format('d/m/Y à H:i') }} —
                {{ $send->organizations->isEmpty() ? 'Toutes les organisations' : $send->organizations->pluck('name')->join(', ') }}
                — {{ $send->demand_count }} demande(s), {{ $send->offer_count }} proposition(s), {{ (int) $send->blog_count }} billet(s)
                @if($send->subject) — « {{ $send->subject }} » @endif
            </p>

            <div class="mt-4 flex items-center justify-between text-sm">
                <strong class="text-gray-900 dark:text-gray-100"><span x-text="traites"></span> / <span x-text="etat.total"></span></strong>
                <span class="text-gray-500" x-text="etat.done ? 'Terminé' : (arret ? 'Interrompu — rechargez la page' : 'Envoi en cours…')" data-admin-newsletter-status></span>
            </div>
            <div class="mt-2 h-2.5 w-full rounded-full bg-gray-200 dark:bg-gray-700" role="progressbar" :aria-valuenow="pourcent" aria-valuemin="0" aria-valuemax="100">
                <div class="h-2.5 rounded-full bg-indigo-600 transition-all" :style="'width:' + pourcent + '%'"></div>
            </div>

            <dl class="mt-4 grid grid-cols-2 gap-3 text-sm sm:grid-cols-5">
                <div><dt class="text-xs text-gray-500">Envoyés</dt><dd class="font-semibold text-emerald-600" x-text="etat.sent"></dd></div>
                <div><dt class="text-xs text-gray-500">En file</dt><dd class="font-semibold text-gray-900 dark:text-gray-100" x-text="etat.pending + etat.queued"></dd></div>
                <div><dt class="text-xs text-gray-500">Ignorés</dt><dd class="font-semibold text-gray-500" x-text="etat.skipped"></dd></div>
                <div><dt class="text-xs text-gray-500">Échecs</dt><dd class="font-semibold text-red-600" x-text="etat.failed"></dd></div>
                <div><dt class="text-xs text-gray-500">Incertains</dt><dd class="font-semibold text-amber-600" x-text="etat.ambiguous"></dd></div>
            </dl>
            <p class="mt-4 text-xs text-gray-500">
                « Ignorés » : désinscrits entre-temps, ou plus rien à montrer pour leur organisation.
                Chaque courriel est journalisé dans <a href="{{ route('admin.email-logs', ['source' => 'newsletter']) }}" class="text-indigo-600 hover:underline dark:text-indigo-400">l'historique des emails</a>.
            </p>
        </div>
    </div>
</x-admin-layout>
