{{--
    TASK-1820 — « Mes appareils Push » : inspecter et retirer individuellement
    les abonnements du compte SuperAdmin CONNECTE, et lui seul.

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
<x-admin-layout :title="__('admin.push_devices_title_page')">
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
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('admin.push_devices_intro') }}</p>
            <p class="mt-2 text-sm font-medium text-indigo-700 dark:text-indigo-300">
                {{ trans_choice('admin.push_devices_total', $total, ['count' => $total]) }}
            </p>
        </div>

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
                {{ __('admin.push_devices_empty') }}
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
                                            {{ __($appareil->vapidLangKey) }}
                                        </dd>
                                    </div>
                                </dl>
                            </div>

                            {{-- Confirmation explicite par <details> — pas de confirm() natif,
                                 qui bloquerait la page et ne serait pas testable. --}}
                            <details data-push-devices-remove="{{ $appareil->id }}" class="shrink-0 text-right">
                                <summary class="cursor-pointer text-xs font-semibold text-red-700 dark:text-red-300">
                                    {{ __('admin.push_devices_remove') }}
                                </summary>
                                <div class="mt-2 max-w-xs text-left text-[11px] leading-snug text-gray-600 dark:text-gray-400">
                                    <p>{{ __('admin.push_devices_remove_confirm') }}</p>

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
        @endif
    </div>
</x-admin-layout>
