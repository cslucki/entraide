<x-admin-layout title="Newsletter">
    {{-- TASK-1883 (demande Cyril, 22/11) — newsletter des demandes et propositions
         d'aide. Le texte se modifie dans « Templates système » (weekly_help_digest) ;
         ici on choisit le titre, les nombres et les organisations, on regarde le
         resultat reel (code / apercu), puis on envoie. --}}
    @php
        $champ = 'w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100';
        $carte = 'rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800';
        $ancienneSelection = old('organization_ids', []);
    @endphp

    <div class="grid gap-6 xl:grid-cols-[minmax(0,26rem)_minmax(0,1fr)]" data-admin-newsletter
         x-data="{
            scope: @js(old('scope', 'all')),
            ids: @js(array_values($ancienneSelection)),
            subject: @js(old('subject', $gabarit?->subject ?? '')),
            demandes: @js((int) old('demand_count', 3)),
            propositions: @js((int) old('offer_count', 3)),
            billets: @js((int) old('blog_count', 3)),
            evenements: @js((int) old('event_count', 3)),
            locale: 'fr',
            onglet: 'apercu',
            rendu: null, chargement: false, erreur: null, minuteur: null,
            planifier() { clearTimeout(this.minuteur); this.minuteur = setTimeout(() => this.apercu(), 350); },
            async apercu() {
                if (this.scope === 'selection' && this.ids.length === 0) { this.rendu = null; this.erreur = 'Cochez au moins une organisation.'; return; }
                this.chargement = true; this.erreur = null;
                try {
                    const r = await fetch(@js(route('admin.newsletter.preview')), {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': @js(csrf_token()) },
                        body: JSON.stringify({ scope: this.scope, organization_ids: this.ids, subject: this.subject, demand_count: this.demandes, offer_count: this.propositions, blog_count: this.billets, event_count: this.evenements, locale: this.locale }),
                    });
                    if (!r.ok) { this.erreur = 'Aperçu impossible (' + r.status + ').'; return; }
                    this.rendu = await r.json();
                } catch (e) { this.erreur = 'Aperçu impossible.'; } finally { this.chargement = false; }
            },
         }"
         x-init="apercu(); $watch('scope', () => planifier()); $watch('ids', () => planifier()); $watch('subject', () => planifier()); $watch('demandes', () => planifier()); $watch('propositions', () => planifier()); $watch('billets', () => planifier()); $watch('evenements', () => planifier()); $watch('locale', () => apercu())">

        <form method="POST" action="{{ route('admin.newsletter.store') }}" class="space-y-5" data-admin-newsletter-form>
            @csrf

            <div class="{{ $carte }} space-y-4">
                <p class="text-sm text-gray-600 dark:text-gray-300">
                    Les dernières demandes et propositions d'aide, envoyées à chaque membre avec celles de
                    <strong>son</strong> organisation. Le texte se modifie dans
                    @if($gabarit)
                        <a href="{{ route('admin.system-email-templates.edit', $gabarit) }}" class="text-indigo-600 hover:underline dark:text-indigo-400">le modèle système</a>.
                    @else
                        les templates système (modèle <code>weekly_help_digest</code> introuvable).
                    @endif
                </p>

                <label class="block text-sm">
                    <span class="mb-1 block font-medium text-gray-700 dark:text-gray-200">Titre (objet de l'email)</span>
                    <input type="text" name="subject" x-model="subject" maxlength="255" class="{{ $champ }}" data-admin-newsletter-subject>
                    <span class="mt-1 block text-xs text-gray-500">Laissé tel quel, chaque membre reçoit l'objet du modèle dans sa langue ; modifié, votre titre est envoyé à tous. <code>@{{ organization_name }}</code> est remplacé par le nom de l'organisation du membre, <code>@{{ newsletter_date }}</code> par la date du jour de l'envoi.</span>
                </label>

                {{-- TASK-1891 (demande Cyril, 23/11) : les prochains evenements de
                     l'agenda, EN TETE de la newsletter. --}}
                <label class="block text-sm">
                    <span class="mb-1 block font-medium text-gray-700 dark:text-gray-200">Événements de l'agenda</span>
                    <input type="number" name="event_count" x-model.number="evenements" min="0" max="10" class="{{ $champ }}" data-admin-newsletter-event-count>
                    <span class="mt-1 block text-xs text-gray-500">Les prochains, ouverts à toute l'organisation du membre (ceux réservés à une Boucle n'y figurent pas) ; 0 : aucun événement, ni titre de section. Code du modèle : <code>{evenements;3}</code>.</span>
                </label>

                <div class="grid grid-cols-2 gap-3">
                    <label class="block text-sm">
                        <span class="mb-1 block font-medium text-gray-700 dark:text-gray-200">Demandes d'aide</span>
                        <input type="number" name="demand_count" x-model.number="demandes" min="0" max="10" class="{{ $champ }}">
                    </label>
                    <label class="block text-sm">
                        <span class="mb-1 block font-medium text-gray-700 dark:text-gray-200">Propositions d'aide</span>
                        <input type="number" name="offer_count" x-model.number="propositions" min="0" max="10" class="{{ $champ }}">
                    </label>
                </div>

                {{-- Demande Cyril (22/11) : les derniers billets de blog, en option. --}}
                <label class="block text-sm">
                    <span class="mb-1 block font-medium text-gray-700 dark:text-gray-200">Billets de blog</span>
                    <input type="number" name="blog_count" x-model.number="billets" min="0" max="10" class="{{ $champ }}" data-admin-newsletter-blog-count>
                    <span class="mt-1 block text-xs text-gray-500">Les plus récents de l'organisation du membre ; 0 : aucun billet, ni titre de section.</span>
                </label>
            </div>

            <fieldset class="{{ $carte }} space-y-3">
                <legend class="sr-only">Destinataires</legend>
                <span class="block text-sm font-medium text-gray-700 dark:text-gray-200">Destinataires</span>
                <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                    <input type="radio" name="scope" value="all" x-model="scope" class="text-indigo-600"> Toutes les organisations
                </label>
                <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                    <input type="radio" name="scope" value="selection" x-model="scope" class="text-indigo-600"> Les organisations cochées
                </label>
                <div x-show="scope === 'selection'" x-cloak class="max-h-56 space-y-1 overflow-y-auto rounded-lg border border-gray-100 p-3 dark:border-gray-700">
                    @foreach($organizations as $org)
                        <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                            <input type="checkbox" name="organization_ids[]" value="{{ $org->id }}" x-model="ids" class="rounded text-indigo-600"
                                   :disabled="scope !== 'selection'">
                            {{ $org->name }}
                        </label>
                    @endforeach
                </div>
                <p class="text-sm text-gray-700 dark:text-gray-200" data-admin-newsletter-recipients>
                    <template x-if="rendu"><span><strong x-text="rendu.recipients"></strong> membre(s) abonné(s) recevront la newsletter.</span></template>
                </p>
                <p class="text-xs text-gray-500">Les membres désinscrits, bloqués, et ceux d'une organisation sans rien à montrer (ni événement, ni demande, ni proposition, ni billet demandé) ne reçoivent rien.</p>
            </fieldset>

            @if($dernier)
                <label class="flex items-start gap-2 rounded-xl bg-amber-50 p-4 text-sm text-amber-800 dark:bg-amber-950/40 dark:text-amber-200" data-admin-newsletter-repeat>
                    <input type="checkbox" name="confirm_repeat" value="1" class="mt-0.5 rounded text-amber-600">
                    <span>
                        Une newsletter est déjà partie le {{ $dernier->created_at->timezone('Europe/Paris')->format('d/m/Y à H:i') }}
                        vers {{ $dernier->organizations->isEmpty() ? 'toutes les organisations' : $dernier->organizations->pluck('name')->join(', ') }}
                        @if($dernier->subject) — « {{ $dernier->objetLisible() }} » @endif
                        — {{ (int) $dernier->event_count }} événement(s), {{ $dernier->demand_count }} demande(s), {{ $dernier->offer_count }} proposition(s), {{ (int) $dernier->blog_count }} billet(s) ;
                        {{ $dernier->sent_count }} envoyé(s) sur {{ $dernier->total_recipients }}@if($dernier->failed_count), {{ $dernier->failed_count }} échec(s)@endif.
                        <a href="{{ route('admin.newsletter.show', $dernier) }}" class="font-semibold underline" data-admin-newsletter-repeat-link>Voir cet envoi</a>
                        <span class="mt-1 block">Je confirme vouloir en envoyer une autre.</span>
                    </span>
                </label>
            @endif

            <div class="flex justify-end">
                <button type="submit" class="rounded-lg bg-indigo-600 px-5 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50"
                        :disabled="!rendu || rendu.recipients === 0" data-admin-newsletter-submit>
                    Envoyer la newsletter
                </button>
            </div>
        </form>

        <section class="{{ $carte }} min-w-0" data-admin-newsletter-preview>
            <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                <div class="inline-flex rounded-lg border border-gray-200 p-0.5 text-sm dark:border-gray-700" role="tablist">
                    <button type="button" role="tab" x-on:click="onglet = 'apercu'" :aria-selected="onglet === 'apercu'"
                            :class="onglet === 'apercu' ? 'bg-indigo-600 text-white' : 'text-gray-600 dark:text-gray-300'" class="rounded-md px-3 py-1">Aperçu</button>
                    <button type="button" role="tab" x-on:click="onglet = 'code'" :aria-selected="onglet === 'code'"
                            :class="onglet === 'code' ? 'bg-indigo-600 text-white' : 'text-gray-600 dark:text-gray-300'" class="rounded-md px-3 py-1" data-admin-newsletter-tab-code>Code</button>
                </div>
                <div class="flex items-center gap-3 text-sm">
                    <select x-model="locale" class="rounded-lg border-gray-300 py-1 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100" aria-label="Langue de l'aperçu">
                        <option value="fr">Français</option>
                        <option value="en">English</option>
                    </select>
                    <span x-show="chargement" class="text-xs text-gray-500">Mise à jour…</span>
                </div>
            </div>

            <p x-show="erreur" x-text="erreur" class="text-sm text-red-600"></p>

            <template x-if="rendu && rendu.html">
                <div>
                    <p class="mb-2 text-sm text-gray-700 dark:text-gray-200">
                        <span class="text-gray-500">Objet :</span> <strong x-text="rendu.subject"></strong>
                        <span class="text-xs text-gray-500" x-text="'— exemple pour ' + rendu.organization"></span>
                    </p>
                    <iframe x-show="onglet === 'apercu'" :srcdoc="rendu.html" title="Aperçu de la newsletter" sandbox=""
                            class="h-[70vh] w-full rounded-lg border border-gray-200 bg-white dark:border-gray-700"></iframe>
                    <pre x-show="onglet === 'code'" x-text="rendu.html"
                         class="h-[70vh] overflow-auto whitespace-pre-wrap break-all rounded-lg bg-gray-900 p-4 text-xs text-gray-100"></pre>
                </div>
            </template>
        </section>
    </div>

    @if($historique->isNotEmpty())
        <section class="mt-8 {{ $carte }}" data-admin-newsletter-history>
            <h2 class="mb-3 text-sm font-semibold text-gray-900 dark:text-gray-100">Derniers envois</h2>
            <ul class="divide-y divide-gray-100 text-sm dark:divide-gray-700">
                @foreach($historique as $envoi)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                        <a href="{{ route('admin.newsletter.show', $envoi) }}" class="text-indigo-600 hover:underline dark:text-indigo-400">
                            {{ $envoi->created_at->timezone('Europe/Paris')->format('d/m/Y H:i') }}
                            — {{ $envoi->organizations->isEmpty() ? 'Toutes les organisations' : $envoi->organizations->pluck('name')->join(', ') }}
                        </a>
                        <span class="text-gray-500">{{ $envoi->sent_count }} / {{ $envoi->total_recipients }} envoyés</span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</x-admin-layout>
