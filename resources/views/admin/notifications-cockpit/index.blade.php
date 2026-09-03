{{--
    TASK-1380 — supervision des notifications.

    Cet ecran COMPTE, il ne lit pas. Aucun destinataire, aucune adresse, aucun
    corps de message, aucun identifiant d'objet metier n'y figure — et le bloc
    de fin le dit a l'ecran plutot que de laisser croire a un oubli.

    Chaque valeur porte un attribut `data-*` : ce sont les points d'accroche des
    tests, et ils rendent mesurable ce qui est affiche.
--}}
<x-admin-layout title="Supervision des notifications">
    <div class="max-w-6xl mx-auto space-y-6">

        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Supervision des notifications</h1>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                Ce que le système est en train de faire, toutes organisations confondues.
                Aucun contenu, aucun destinataire : cet écran compte, il ne lit pas.
            </p>
        </div>

        @php
            $incidents = $livraisons['_incidents'];
            $alerte = $alertes['bloquees_en_envoi'] > 0 || $alertes['en_attente_anciennes'] > 0 || $file['echouees'] > 0;
        @endphp

        {{-- ── Le verdict, en un coup d'œil ─────────────────────────────── --}}
        <div data-cockpit-verdict="{{ $alerte ? 'attention' : 'nominal' }}"
             class="rounded-2xl border p-4 {{ $alerte
                ? 'border-amber-300 bg-amber-50 dark:border-amber-700 dark:bg-amber-900/20'
                : 'border-emerald-300 bg-emerald-50 dark:border-emerald-700 dark:bg-emerald-900/20' }}">
            <div class="flex flex-wrap items-center gap-3">
                <span class="rounded-full px-3 py-1 text-xs font-bold text-white {{ $alerte ? 'bg-amber-600' : 'bg-emerald-600' }}">
                    {{ $alerte ? 'INTERVENTION REQUISE' : 'NOMINAL' }}
                </span>
                <span class="text-sm text-gray-700 dark:text-gray-300">
                    @if($alerte)
                        Aucune reprise n'est automatique : ces livraisons attendent un geste humain.
                    @else
                        Rien n'est bloqué, rien n'attend anormalement.
                    @endif
                </span>
            </div>

            <dl class="mt-3 grid grid-cols-1 gap-x-6 gap-y-1 text-sm sm:grid-cols-3">
                <div class="flex justify-between gap-3">
                    <dt class="text-gray-500 dark:text-gray-400">Bloquées en envoi</dt>
                    <dd data-alerte-bloquees class="font-semibold text-gray-900 dark:text-gray-100">{{ $alertes['bloquees_en_envoi'] }}</dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-gray-500 dark:text-gray-400">En attente anciennes</dt>
                    <dd data-alerte-attente class="font-semibold text-gray-900 dark:text-gray-100">{{ $alertes['en_attente_anciennes'] }}</dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-gray-500 dark:text-gray-400">Reprises manuelles</dt>
                    <dd data-alerte-reprises class="font-semibold text-gray-900 dark:text-gray-100">{{ $alertes['reprises_manuelles'] }}</dd>
                </div>
            </dl>

            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                Une livraison est considérée bloquée après {{ $alertes['seuil_secondes'] }} secondes —
                le job rend la main bien avant.
            </p>
        </div>

        {{-- ── Le transport ─────────────────────────────────────────────── --}}
        <div class="rounded-2xl border border-gray-200 dark:border-gray-700 p-4">
            <h2 class="text-base font-semibold text-gray-800 dark:text-gray-200 mb-3">Transport</h2>
            <div class="flex flex-wrap items-center gap-3">
                <span data-cockpit-badge="{{ $transport['badge'] }}"
                      class="rounded-full px-3 py-1 text-xs font-bold text-white {{ $transport['is_local_capture'] ? 'bg-emerald-600' : 'bg-red-600' }}">
                    {{ $transport['badge'] }}
                </span>
                <span class="text-sm text-gray-600 dark:text-gray-400">
                    {{ $transport['environment'] }} · {{ $transport['mailer'] }} ·
                    file <code class="text-xs">{{ $file['nom'] }}</code>
                </span>
            </div>
        </div>

        {{-- ── Les livraisons ───────────────────────────────────────────── --}}
        <div class="rounded-2xl border border-gray-200 dark:border-gray-700 p-4">
            <h2 class="text-base font-semibold text-gray-800 dark:text-gray-200 mb-1">Livraisons</h2>
            <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">
                Les états « ignorées » sont des décisions, pas des pannes : un membre qui a coupé
                l'email, ou un objet devenu inatteignable. Ils ne comptent pas dans les incidents.
            </p>

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div class="rounded-xl bg-gray-50 dark:bg-gray-800/50 p-3">
                    <div class="text-xs text-gray-500 dark:text-gray-400">En cours</div>
                    <div data-livraisons-en-cours class="text-xl font-bold text-gray-900 dark:text-white">{{ $livraisons['_en_cours'] }}</div>
                </div>
                <div class="rounded-xl bg-gray-50 dark:bg-gray-800/50 p-3">
                    <div class="text-xs text-gray-500 dark:text-gray-400">Envoyées</div>
                    <div data-livraisons-sent class="text-xl font-bold text-gray-900 dark:text-white">{{ $livraisons['sent'] }}</div>
                </div>
                <div class="rounded-xl bg-gray-50 dark:bg-gray-800/50 p-3">
                    <div class="text-xs text-gray-500 dark:text-gray-400">Ignorées</div>
                    <div data-livraisons-ignorees class="text-xl font-bold text-gray-900 dark:text-white">{{ $livraisons['_ignorees'] }}</div>
                </div>
                <div class="rounded-xl p-3 {{ $incidents > 0 ? 'bg-red-50 dark:bg-red-900/20' : 'bg-gray-50 dark:bg-gray-800/50' }}">
                    <div class="text-xs text-gray-500 dark:text-gray-400">Incidents</div>
                    <div data-livraisons-incidents class="text-xl font-bold text-gray-900 dark:text-white">{{ $incidents }}</div>
                </div>
            </div>

            <dl class="mt-4 grid grid-cols-1 gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
                @foreach(['pending' => 'En attente', 'sending' => 'En cours d\'envoi', 'sent' => 'Envoyées', 'failed' => 'Échouées', 'ambiguous' => 'Issue inconnue', 'skipped_preference' => 'Ignorées — préférence', 'skipped_unreachable' => 'Ignorées — inatteignable'] as $etat => $libelle)
                    <div class="flex justify-between gap-3">
                        <dt class="text-gray-500 dark:text-gray-400">{{ $libelle }}</dt>
                        <dd data-etat-{{ $etat }} class="font-semibold text-gray-900 dark:text-gray-100">{{ $livraisons[$etat] }}</dd>
                    </div>
                @endforeach
            </dl>

            <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                « Issue inconnue » n'est pas un échec : le transport a levé après avoir reçu le message,
                et personne ne sait s'il est parti. Le rejouer risquerait un double envoi.
            </p>
        </div>

        {{-- ── Les diagnostics ──────────────────────────────────────────── --}}
        @if(count($diagnostics) > 0)
            <div class="rounded-2xl border border-gray-200 dark:border-gray-700 p-4">
                <h2 class="text-base font-semibold text-gray-800 dark:text-gray-200 mb-3">Diagnostics rencontrés</h2>
                <div class="overflow-x-auto">
                    <table data-cockpit-diagnostics class="w-full text-sm">
                        <tbody>
                        @foreach($diagnostics as $d)
                            <tr class="border-t border-gray-100 dark:border-gray-800">
                                <td class="py-1.5"><code class="text-xs">{{ $d['code'] }}</code></td>
                                <td class="py-1.5 text-right font-semibold text-gray-900 dark:text-gray-100">{{ $d['total'] }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        {{-- ── La file ──────────────────────────────────────────────────── --}}
        <div class="rounded-2xl border border-gray-200 dark:border-gray-700 p-4">
            <h2 class="text-base font-semibold text-gray-800 dark:text-gray-200 mb-3">File dédiée</h2>
            <dl class="grid grid-cols-1 gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
                <div class="flex justify-between gap-3">
                    <dt class="text-gray-500 dark:text-gray-400">En attente</dt>
                    <dd data-file-attente class="font-semibold text-gray-900 dark:text-gray-100">{{ $file['en_attente'] }}</dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-gray-500 dark:text-gray-400">Prises par un worker</dt>
                    <dd data-file-prises class="font-semibold text-gray-900 dark:text-gray-100">{{ $file['prises'] }}</dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-gray-500 dark:text-gray-400">Plus ancien job</dt>
                    <dd data-file-plus-ancien class="font-semibold text-gray-900 dark:text-gray-100">{{ $file['plus_ancien'] ?? '—' }}</dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-gray-500 dark:text-gray-400">Jobs échoués</dt>
                    <dd data-file-echouees class="font-semibold text-gray-900 dark:text-gray-100">{{ $file['echouees'] }}</dd>
                </div>
            </dl>
            <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                La file <code>default</code> compte <span data-file-default>{{ $file['default_reference'] }}</span>
                jobs historiques. Elle n'appartient pas à cette verticale et n'est jamais consommée
                par le worker des notifications — elle figure ici pour qu'une dérive se voie.
            </p>
        </div>

        {{-- ── Le catalogue ─────────────────────────────────────────────── --}}
        <div class="rounded-2xl border border-gray-200 dark:border-gray-700 p-4">
            <h2 class="text-base font-semibold text-gray-800 dark:text-gray-200 mb-3">Catalogue</h2>
            <div class="overflow-x-auto">
                <table data-cockpit-catalogue class="w-full text-sm">
                    <thead class="text-xs text-gray-500 dark:text-gray-400">
                        <tr class="border-b border-gray-200 dark:border-gray-700">
                            <th class="py-2 text-left font-medium">Clé</th>
                            <th class="py-2 text-left font-medium">Canaux</th>
                            <th class="py-2 text-right font-medium">Émises</th>
                            <th class="py-2 text-right font-medium">Dernière</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach($catalogue as $entree)
                        <tr data-catalogue-cle="{{ $entree['cle'] }}" class="border-b border-gray-100 dark:border-gray-800">
                            <td class="py-2"><code class="text-xs">{{ $entree['cle'] }}</code></td>
                            <td class="py-2">
                                @foreach($entree['canaux'] as $canal)
                                    <span class="inline-block mr-2 rounded px-1.5 py-0.5 text-xs {{ $canal['configurable'] ? 'bg-gray-100 dark:bg-gray-700' : 'bg-indigo-100 dark:bg-indigo-900/40' }}">
                                        {{ __('notifications.channel_'.$canal['canal']) }}{{ $canal['configurable'] ? '' : ' · obligatoire' }}
                                    </span>
                                @endforeach
                            </td>
                            <td class="py-2 text-right font-semibold text-gray-900 dark:text-gray-100">{{ $entree['total'] }}</td>
                            <td class="py-2 text-right text-xs text-gray-500 dark:text-gray-400">{{ $entree['derniere'] ?? '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                Le catalogue est gouverné par le code, pas par cet écran : une clé absente n'existe pas.
            </p>
        </div>

        {{-- ── L'activité par organisation ──────────────────────────────── --}}
        @if(count($activite) > 0)
            <div class="rounded-2xl border border-gray-200 dark:border-gray-700 p-4">
                <h2 class="text-base font-semibold text-gray-800 dark:text-gray-200 mb-3">Activité par organisation</h2>
                <div class="overflow-x-auto">
                    <table data-cockpit-activite class="w-full text-sm">
                        <thead class="text-xs text-gray-500 dark:text-gray-400">
                            <tr class="border-b border-gray-200 dark:border-gray-700">
                                <th class="py-2 text-left font-medium">Organisation</th>
                                <th class="py-2 text-right font-medium">Notifications</th>
                                <th class="py-2 text-right font-medium">Non lues</th>
                            </tr>
                        </thead>
                        <tbody>
                        @foreach($activite as $ligne)
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                <td class="py-2 text-gray-900 dark:text-gray-100">{{ $ligne['nom'] }}</td>
                                <td class="py-2 text-right font-semibold text-gray-900 dark:text-gray-100">{{ $ligne['total'] }}</td>
                                <td class="py-2 text-right text-gray-900 dark:text-gray-100">{{ $ligne['non_lues'] }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        {{-- ── La preuve historique ─────────────────────────────────────── --}}
        <div class="rounded-2xl border border-gray-200 dark:border-gray-700 p-4">
            <h2 class="text-base font-semibold text-gray-800 dark:text-gray-200 mb-3">Preuve historique</h2>
            <dl class="grid grid-cols-1 gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
                <div class="flex justify-between gap-3">
                    <dt class="text-gray-500 dark:text-gray-400">Emails tracés</dt>
                    <dd data-preuves-total class="font-semibold text-gray-900 dark:text-gray-100">{{ $preuves['total'] }}</dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-gray-500 dark:text-gray-400">Envoyés</dt>
                    <dd data-preuves-envoyees class="font-semibold text-gray-900 dark:text-gray-100">{{ $preuves['envoyees'] }}</dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-gray-500 dark:text-gray-400">Échoués</dt>
                    <dd data-preuves-echouees class="font-semibold text-gray-900 dark:text-gray-100">{{ $preuves['echouees'] }}</dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt class="text-gray-500 dark:text-gray-400">Issue inconnue</dt>
                    <dd data-preuves-ambigues class="font-semibold text-gray-900 dark:text-gray-100">{{ $preuves['ambigues'] }}</dd>
                </div>
            </dl>
            <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                <span data-preuves-corps>{{ $preuves['corps_archive'] }}</span> de ces envois ont un corps archivé.
                Seule sa <strong>présence</strong> est comptée : le corps lui-même n'est jamais affiché.
                <a href="{{ route('admin.email-logs') }}" class="underline">Consulter l'historique détaillé</a>.
            </p>
        </div>

        {{-- ── Ce qui n'est pas montré, et pourquoi ─────────────────────── --}}
        <div data-cockpit-limites class="rounded-2xl bg-gray-50 dark:bg-gray-800/50 border border-gray-200 dark:border-gray-700 p-4">
            <h2 class="text-base font-semibold text-gray-800 dark:text-gray-200 mb-2">Ce que cet écran ne montre pas</h2>
            <ul class="space-y-1 text-sm text-gray-600 dark:text-gray-400">
                <li>Aucun destinataire, aucun acteur, aucune adresse email. Superviser n'est pas lire.</li>
                <li>Aucun corps de message, aucun sujet, aucun objet métier — seuls des compteurs.</li>
                <li>Aucun contenu de file d'attente : la charge d'un job n'est jamais affichée.</li>
                <li>Aucun identifiant, aucun jeton, aucune donnée de transport — même masqués.</li>
            </ul>
            <p class="mt-3 text-xs text-gray-400 dark:text-gray-500">
                Les préférences des membres ne sont pas ventilées par organisation : elles
                appartiennent à la personne, et les y rattacher ferait de sa boîte de réception
                un objet du tenant.
            </p>
        </div>

    </div>
</x-admin-layout>
