{{--
    TASK-1820 puis TASK-1821 — « Appareils Push » : inspecter et retirer
    individuellement les abonnements Push, de TOUS les comptes.

    T1820 bornait cet écran au compte connecté ; l'arbitrage T1821 l'ouvre à
    la plateforme entière (voir le docblock du contrôleur). La garde se durcit
    en conséquence, côté ROUTE : `can:superadmin-push-all-devices`.

    « Cet appareil » reste un calcul CLIENT, lié au navigateur et à l'origine
    courants : il ne peut correspondre qu'à l'abonnement que CE navigateur
    détient réellement — jamais à une ligne choisie par le serveur.

    Ce que cette page ne montre JAMAIS : l'endpoint complet, ni aucune clef.
    L'empreinte complete (`data-fingerprint-full`) est un hachage SHA-256 a
    sens unique — pas l'endpoint — porte uniquement pour que le NAVIGATEUR
    puisse reconnaitre localement sa propre ligne : il recalcule le SHA-256
    de son propre `pushManager.getSubscription().endpoint` et compare, sans
    jamais envoyer cet endpoint au serveur.

    « Cet appareil » n'est JAMAIS deduit de `last_seen_at` ni du libelle —
    uniquement d'une correspondance d'empreinte exacte, calculee cote
    navigateur. Sans correspondance, la page le dit explicitement plutot que
    de deviner.
--}}
<x-admin-layout :title="__('admin.all_push_devices_title_page')">
    <div
        x-data="{
            appareilCourantId: null,
            etatRecherche: 'en_cours',
            unsupported: false,

            async identifierAppareilCourant() {
                try {
                    if (!('serviceWorker' in navigator) || !('PushManager' in window) || !window.crypto?.subtle) {
                        this.unsupported = true;
                        this.etatRecherche = 'termine';
                        return;
                    }

                    const enregistrement = await navigator.serviceWorker.getRegistration();
                    if (!enregistrement) { this.etatRecherche = 'termine'; return; }

                    const abonnement = await enregistrement.pushManager.getSubscription();
                    if (!abonnement) { this.etatRecherche = 'termine'; return; }

                    const empreinte = await this.sha256Hex(abonnement.endpoint.trim());

                    const correspondance = this.$root.querySelector('[data-fingerprint-full=\'' + empreinte + '\']');
                    if (correspondance) {
                        this.appareilCourantId = correspondance.getAttribute('data-device-id');
                    }
                } catch (e) {
                    // Une erreur d'identification cote navigateur n'est jamais
                    // affichee comme une correspondance trouvee : repli sur
                    // « non identifie », jamais sur une supposition.
                } finally {
                    this.etatRecherche = 'termine';
                }
            },

            async sha256Hex(texte) {
                const octets = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(texte));
                return Array.from(new Uint8Array(octets)).map(o => o.toString(16).padStart(2, '0')).join('');
            },
        }"
        x-init="identifierAppareilCourant()"
    >
        <div class="mb-6">
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('admin.all_push_devices_intro') }}</p>
            <p class="mt-2 text-sm font-medium text-indigo-700 dark:text-indigo-300">
                {{ trans_choice('admin.push_devices_total', $total, ['count' => $total]) }}
            </p>

            {{-- TASK-1821 — en LOCAL uniquement : sous quel compte cet écran
                 est CONSULTÉ. C'est ce qui a manqué le 14/11, quand une liste
                 vide s'expliquait simplement par le compte connecté. --}}
            @if($identiteLocale)
                <p data-push-devices-connected-as class="mt-1 text-xs font-mono text-gray-500 dark:text-gray-400">
                    {{ __('admin.push_devices_connected_as', ['email' => $identiteLocale]) }}
                </p>
            @endif
        </div>

        {{-- ═══════════════════════════════════════════════════════════════
             TASK-1821 — RÉSUMÉ PAR ENVIRONNEMENT.

             Pas un total unique : chaque environnement a sa propre paire
             VAPID, donc « concordant / divergent » n'a de sens qu'à
             l'intérieur de celui qui a stampé la ligne. Agréger les deux,
             c'est produire le faux signal du 14/11 — cinq abonnements de
             production lus comme défectueux depuis une base locale.
             ═══════════════════════════════════════════════════════════════ --}}
        <div data-push-devices-resume class="mb-6 overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
            <table class="min-w-full text-left text-xs">
                <thead class="bg-gray-50 text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                    <tr>
                        <th class="px-3 py-2 font-semibold">{{ __('admin.push_devices_column_env') }}</th>
                        <th class="px-3 py-2 font-semibold">{{ __('admin.all_push_devices_summary_total') }}</th>
                        <th class="px-3 py-2 font-semibold">{{ __('admin.all_push_devices_summary_eligible') }}</th>
                        <th class="px-3 py-2 font-semibold">{{ __('admin.all_push_devices_summary_matching') }}</th>
                        <th class="px-3 py-2 font-semibold">{{ __('admin.all_push_devices_summary_diverging') }}</th>
                        <th class="px-3 py-2 font-semibold">{{ __('admin.all_push_devices_summary_unknown') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach($resume as $ligne)
                        <tr data-push-devices-resume-row="{{ $ligne['environnement'] }}">
                            <td class="px-3 py-2 font-mono">
                                {{ $ligne['environnement'] }}
                                @if($ligne['environnement'] === $environnementCourant)
                                    <span class="ml-1 rounded bg-indigo-100 px-1 text-[10px] font-semibold text-indigo-800 dark:bg-indigo-900/40 dark:text-indigo-200">
                                        {{ __('admin.all_push_devices_summary_current_env') }}
                                    </span>
                                @endif
                            </td>
                            <td class="px-3 py-2">{{ $ligne['total'] }}</td>
                            @if($ligne['comparable'])
                                <td class="px-3 py-2">{{ $ligne['eligibles'] }}</td>
                                <td class="px-3 py-2">{{ $ligne['concordants'] }}</td>
                                <td class="px-3 py-2 {{ $ligne['divergents'] > 0 ? 'font-semibold text-red-700 dark:text-red-300' : '' }}">{{ $ligne['divergents'] }}</td>
                                <td class="px-3 py-2">{{ $ligne['inconnus'] }}</td>
                            @else
                                {{-- Quatre colonnes que CE serveur ne peut pas
                                     remplir : il n'a pas la clef de cet
                                     environnement. Un chiffre inventé vaudrait
                                     moins que son absence. --}}
                                <td colspan="4" class="px-3 py-2 italic text-gray-500 dark:text-gray-400">
                                    {{ __('admin.all_push_devices_summary_not_comparable') }}
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- TASK-1821 — LES QUATRE NIVEAUX, nommés une fois, parce que les
             confondre est exactement ce qui a coûté les essais à l'aveugle
             de T1740 : un `201` du fournisseur n'a jamais prouvé qu'un
             téléphone ait affiché quoi que ce soit. --}}
        <details data-push-devices-legende class="mb-6 rounded-lg border border-dashed border-gray-300 p-3 text-xs dark:border-gray-600">
            <summary class="cursor-pointer font-semibold text-gray-700 dark:text-gray-200">
                {{ __('admin.all_push_devices_levels_title') }}
            </summary>
            <ol class="mt-2 list-decimal space-y-1 pl-5 text-gray-600 dark:text-gray-400">
                <li>{{ __('admin.all_push_devices_level_registered') }}</li>
                <li>{{ __('admin.all_push_devices_level_attempt') }}</li>
                <li>{{ __('admin.all_push_devices_level_accepted') }}</li>
                <li class="font-medium text-amber-700 dark:text-amber-300">{{ __('admin.all_push_devices_level_received') }}</li>
            </ol>
        </details>

        {{-- TASK-1821 — recherche par PROPRIÉTAIRE (nom ou email), jamais par
             endpoint ni par clef : ce ne sont pas des donnees que cet écran
             accepte de recevoir, pas davantage que d'afficher. --}}
        <form method="GET" action="{{ route('admin.outils.my-push-devices') }}" class="mb-6 flex max-w-md gap-2">
            <input type="search" name="q" value="{{ $recherche }}"
                   placeholder="{{ __('admin.all_push_devices_search_placeholder') }}"
                   class="min-h-11 w-full rounded-lg border border-gray-300 px-3 text-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100">
            <button type="submit"
                    class="inline-flex min-h-11 items-center rounded-lg border border-gray-300 px-4 text-sm font-semibold text-gray-700 dark:border-gray-600 dark:text-gray-200">
                {{ __('admin.all_push_devices_search_submit') }}
            </button>
        </form>

        {{-- L'etat d'identification de l'appareil courant — jamais une devinette. --}}
        <div class="mb-6 rounded-lg border p-3 text-sm"
             :class="unsupported || (etatRecherche === 'termine' && appareilCourantId === null)
                        ? 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-800 dark:bg-amber-900/20 dark:text-amber-200'
                        : 'border-gray-200 bg-gray-50 text-gray-600 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300'">
            <span x-show="etatRecherche === 'en_cours'" x-cloak data-push-devices-checking>{{ __('admin.push_devices_checking') }}</span>
            <span x-show="etatRecherche === 'termine' && unsupported" x-cloak data-push-devices-unsupported>{{ __('admin.push_devices_unsupported') }}</span>
            <span x-show="etatRecherche === 'termine' && !unsupported && appareilCourantId === null" x-cloak data-push-devices-not-identified>{{ __('admin.push_devices_not_identified') }}</span>
            <span x-show="etatRecherche === 'termine' && appareilCourantId !== null" x-cloak data-push-devices-identified>
                {{ __('admin.push_devices_this_device') }} : <span x-text="appareilCourantId"></span>
            </span>
        </div>

        @if(session('push_devices_resultat'))
            <div data-push-devices-resultat
                 class="mb-4 rounded-lg border border-indigo-200 bg-indigo-50 p-3 text-sm text-indigo-800 dark:border-indigo-800 dark:bg-indigo-900/20 dark:text-indigo-200">
                {{ session('push_devices_resultat') }}
            </div>
        @endif

        @if($appareils->isEmpty())
            <p data-push-devices-empty class="rounded-lg bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-900/20 dark:text-amber-200">
                {{ $recherche === '' ? __('admin.all_push_devices_empty') : __('admin.all_push_devices_empty_search') }}
            </p>
        @else
            <div class="space-y-3">
                @foreach($appareils as $appareil)
                    <div data-push-device-row
                         data-device-id="{{ $appareil->id }}"
                         data-fingerprint-full="{{ $appareil->empreinteComplete }}"
                         x-bind:class="appareilCourantId === '{{ $appareil->id }}' ? 'border-indigo-500 ring-1 ring-indigo-500' : 'border-gray-200 dark:border-gray-700'"
                         class="rounded-lg border p-4 text-sm transition">

                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <strong class="text-gray-900 dark:text-gray-100">
                                        {{ $appareil->libelle ?? __('admin.push_devices_device_unknown') }}
                                    </strong>

                                    {{-- « Cet appareil » : JAMAIS pose par le serveur — uniquement par
                                         la correspondance d'empreinte calculee cote navigateur. --}}
                                    <span x-show="appareilCourantId === '{{ $appareil->id }}'" x-cloak
                                          data-push-devices-this-device-badge
                                          class="inline-flex items-center rounded-full bg-indigo-600 px-2 py-0.5 text-xs font-semibold text-white">
                                        {{ __('admin.push_devices_this_device') }}
                                    </span>
                                </div>

                                {{-- TASK-1821 — le PROPRIÉTAIRE de la ligne.
                                     L'écran n'étant plus borné au compte
                                     connecté, retirer une ligne sans savoir
                                     à qui elle est serait une action à
                                     l'aveugle. --}}
                                <p data-push-devices-owner class="mt-1 text-xs font-medium text-gray-700 dark:text-gray-300">
                                    {{ $appareil->proprietaireNom ?? __('admin.push_devices_device_unknown') }}
                                    <span class="font-mono text-gray-500 dark:text-gray-400">· {{ $appareil->proprietaireEmail ?? '—' }}</span>
                                    <span data-push-devices-owner-org class="text-gray-500 dark:text-gray-400">
                                        · {{ $appareil->proprietaireOrganization ?? __('admin.all_push_devices_no_organization') }}
                                    </span>
                                </p>

                                <dl class="mt-2 grid gap-x-4 gap-y-1 text-xs text-gray-600 sm:grid-cols-2 dark:text-gray-400">
                                    <div>
                                        <dt class="inline font-medium">{{ __('admin.push_devices_column_provider') }} :</dt>
                                        <dd class="inline">{{ $appareil->fournisseur }}</dd>
                                    </div>
                                    <div>
                                        <dt class="inline font-medium">{{ __('admin.push_devices_column_fingerprint') }} :</dt>
                                        <dd class="inline font-mono">#{{ $appareil->empreinte }}</dd>
                                    </div>
                                    <div>
                                        <dt class="inline font-medium">{{ __('admin.push_devices_column_created') }} :</dt>
                                        <dd class="inline">{{ $appareil->creeLe?->format('d/m/Y H:i') ?? '—' }}</dd>
                                    </div>
                                    <div>
                                        <dt class="inline font-medium">{{ __('admin.push_devices_column_last_seen') }} :</dt>
                                        <dd class="inline">{{ $appareil->vuLe?->diffForHumans() ?? __('admin.push_devices_never_seen') }}</dd>
                                    </div>
                                    <div>
                                        <dt class="inline font-medium">{{ __('admin.push_devices_column_env') }} :</dt>
                                        <dd class="inline">{{ $appareil->environnement ?? '—' }}</dd>
                                    </div>
                                    <div class="sm:col-span-2">
                                        <dt class="inline font-medium">{{ __('admin.push_devices_column_vapid') }} :</dt>
                                        <dd class="inline {{ $appareil->etatVapid === \App\Models\PushSubscription::VAPID_DIVERGENTE ? 'font-semibold text-red-700 dark:text-red-300' : '' }}">
                                            {{ __($appareil->vapidLangKey, ['env' => $appareil->environnement ?? '—']) }}
                                        </dd>
                                    </div>
                                </dl>

                                {{-- ═══════════════════════════════════════════
                                     TASK-1821 — DIAGNOSTIC : les dernières
                                     livraisons Web Push.

                                     Elles sont celles du COMPTE, pas de cet
                                     appareil : `member_notification_deliveries`
                                     n'a pas d'identifiant d'abonnement, et son
                                     `diagnostic` ne porte qu'un agrégat
                                     (`devices:2 revoked:1`). Les afficher en
                                     face d'une ligne sans le dire reviendrait à
                                     inventer une traçabilité qui n'existe pas.
                                     L'avertissement est donc DANS le panneau,
                                     pas en note de bas de page.
                                     ═══════════════════════════════════════════ --}}
                                <details data-push-devices-diagnostic="{{ $appareil->id }}" class="mt-3 text-xs">
                                    <summary class="cursor-pointer font-semibold text-gray-600 dark:text-gray-300">
                                        {{ __('admin.all_push_devices_diagnostic_title') }}
                                    </summary>

                                    <p class="mt-1 rounded bg-amber-50 p-2 text-[11px] text-amber-800 dark:bg-amber-900/20 dark:text-amber-200">
                                        {{ __('admin.all_push_devices_diagnostic_user_level_warning') }}
                                    </p>

                                    @forelse($appareil->livraisonsDuProprietaire as $livraison)
                                        <div class="mt-2 border-l-2 border-gray-200 pl-2 dark:border-gray-700">
                                            <span class="font-mono font-semibold">{{ $livraison->status }}</span>
                                            <span class="text-gray-500 dark:text-gray-400">· {{ $livraison->notification_key }}</span>
                                            @if($livraison->diagnostic)
                                                <span class="font-mono text-gray-500 dark:text-gray-400">· {{ $livraison->diagnostic }}</span>
                                            @endif
                                            <span class="text-gray-500 dark:text-gray-400">
                                                · {{ \Illuminate\Support\Carbon::parse($livraison->updated_at)->format('d/m/Y H:i') }}
                                                · {{ __('admin.all_push_devices_diagnostic_attempts', ['count' => $livraison->attempts]) }}
                                            </span>
                                        </div>
                                    @empty
                                        <p class="mt-2 italic text-gray-500 dark:text-gray-400">
                                            {{ __('admin.all_push_devices_diagnostic_empty') }}
                                        </p>
                                    @endforelse
                                </details>
                            </div>

                            {{-- Confirmation explicite par <details> — pas de confirm() natif,
                                 qui bloquerait la page et ne serait pas testable. --}}
                            <details data-push-devices-remove="{{ $appareil->id }}" class="shrink-0 text-right">
                                <summary class="cursor-pointer text-xs font-semibold text-red-700 dark:text-red-300">
                                    {{ __('admin.push_devices_remove') }}
                                </summary>
                                <div class="mt-2 max-w-xs text-left text-[11px] leading-snug text-gray-600 dark:text-gray-400">
                                    <p>{{ __('admin.all_push_devices_remove_confirm') }}</p>

                                    {{-- Protection UX contre la suppression accidentelle de « Cet appareil ». --}}
                                    <p x-show="appareilCourantId === '{{ $appareil->id }}'" x-cloak
                                       data-push-devices-this-device-warning
                                       class="mt-2 rounded border border-amber-300 bg-amber-50 p-2 font-medium text-amber-800 dark:border-amber-700 dark:bg-amber-900/30 dark:text-amber-200">
                                        {{ __('admin.push_devices_remove_this_device_warning') }}
                                    </p>
                                </div>
                                <button type="submit" form="retirer-{{ $appareil->id }}"
                                        data-push-devices-remove-confirm
                                        class="mt-2 inline-flex min-h-11 items-center rounded-lg border border-red-500 px-3 text-xs font-semibold text-red-700 dark:text-red-300">
                                    {{ __('admin.push_devices_remove') }}
                                </button>
                            </details>
                        </div>
                    </div>
                @endforeach
            </div>

            @foreach($appareils as $appareil)
                <form id="retirer-{{ $appareil->id }}" method="POST"
                      action="{{ route('admin.outils.my-push-devices.destroy', ['subscription' => $appareil->id]) }}"
                      class="hidden">
                    @csrf
                    @method('DELETE')
                </form>
            @endforeach

            <div class="mt-6">
                {{ $pagination->links() }}
            </div>
        @endif
    </div>
</x-admin-layout>
