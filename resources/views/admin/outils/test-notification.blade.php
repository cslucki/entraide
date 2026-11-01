{{--
    TASK-1740 — diagnostic du Web Push, choix du destinataire et de l'appareil,
    puis envoi depuis l'administration.

    L'écran SÉPARE les causes possibles d'un envoi silencieux : configuration
    absente, sortie réseau refusée par `ExternalEgressGuard`, aucun appareil
    éligible. Elles produisent toutes le même silence ; sans cette séparation,
    la recette se passerait à deviner laquelle s'applique.

    Ce qu'il ne montre JAMAIS : l'endpoint complet et les clés. Un endpoint est
    un jeton de réveil — qui le détient avec les clés peut déposer une
    notification sur l'appareil. On n'en rend que le fournisseur et une
    empreinte courte, suffisante pour distinguer deux appareils.
--}}
<x-admin-layout :title="__('admin.push_test_title_page')">
    <div class="mb-6">
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('admin.push_test_intro') }}</p>
        <p class="mt-2 text-sm font-medium text-indigo-700 dark:text-indigo-300">{{ __('admin.push_test_scope') }}</p>
    </div>

    @if(session('push_test_resultat'))
        <div data-push-test-resultat
             class="mb-4 rounded-lg border border-indigo-200 bg-indigo-50 p-3 text-sm text-indigo-800 dark:border-indigo-800 dark:bg-indigo-900/20 dark:text-indigo-200">
            <p>{{ session('push_test_resultat') }}</p>

            @foreach((array) session('push_test_http', []) as $ligne)
                <p class="mt-1 font-mono text-xs">{{ $ligne }}</p>
            @endforeach

            @foreach((array) session('push_test_detail', []) as $motif)
                <p class="mt-1 break-words text-xs">{{ $motif }}</p>
            @endforeach

            {{-- La distinction que MASTER a demandé de ne pas effacer. --}}
            <p class="mt-2 text-xs italic">{{ __('admin.push_test_accepted_is_not_displayed') }}</p>
        </div>
    @endif

    {{-- Diagnostic de l'environnement, poste par poste. --}}
    <div class="mb-6 overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700">
        <table class="w-full text-sm">
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                @php
                    $etatTexte = fn (bool $ok) => $ok ? __('admin.push_test_state_on') : __('admin.push_test_state_off');
                    $postes = [
                        ['WEB_PUSH_ENABLED', $pushActif, $etatTexte($pushActif)],
                        ['VAPID_PUBLIC_KEY', $clePubliquePosee, $etatTexte($clePubliquePosee)],
                        // La valeur n'est JAMAIS imprimée : on dit seulement
                        // qu'elle est posée.
                        ['VAPID_PRIVATE_KEY', $clePriveePosee, $etatTexte($clePriveePosee)],
                        ['VAPID_SUBJECT', $sujet !== '', $sujet !== '' ? $sujet : '—'],
                        ['Base de données', true, $base],
                        [
                            'Sorties HTTP',
                            ! $sortiesBloquees || $pushAutorise,
                            ! $sortiesBloquees
                                ? __('admin.push_test_egress_ok')
                                : ($pushAutorise ? __('admin.push_test_egress_push_only') : __('admin.push_test_egress_blocked')),
                        ],
                    ];
                @endphp
                @foreach($postes as [$libelle, $ok, $valeur])
                    <tr data-push-diag="{{ \Illuminate\Support\Str::slug($libelle) }}">
                        <th scope="row" class="w-48 bg-gray-50 px-3 py-2 text-left font-medium text-gray-700 dark:bg-gray-800 dark:text-gray-300">{{ $libelle }}</th>
                        <td class="px-3 py-2">
                            <span class="mr-2 inline-block h-2.5 w-2.5 rounded-full {{ $ok ? 'bg-emerald-500' : 'bg-amber-500' }}"></span>
                            <span class="text-gray-700 dark:text-gray-300">{{ $valeur }}</span>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- 1. Choisir le destinataire. --}}
    <h2 class="mb-2 text-base font-semibold text-gray-900 dark:text-gray-100">{{ __('admin.push_test_step_user') }}</h2>

    <form method="GET" class="mb-3 flex flex-wrap gap-2">
        <input type="search" name="q" value="{{ $recherche }}"
               placeholder="{{ __('admin.push_test_search') }}"
               class="min-h-11 flex-1 rounded-lg border border-gray-300 px-3 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
        <button type="submit" class="inline-flex min-h-11 items-center rounded-lg border border-gray-300 px-4 text-sm font-semibold dark:border-gray-600 dark:text-gray-200">
            {{ __('admin.push_test_search_submit') }}
        </button>
    </form>

    @if($destinataires->isEmpty())
        <p data-push-no-recipient class="mb-6 rounded-lg bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-900/20 dark:text-amber-200">
            {{ __('admin.push_test_no_recipient') }}
        </p>
    @else
        <ul class="mb-6 flex flex-wrap gap-2">
            @foreach($destinataires as $candidat)
                <li>
                    <a href="{{ route('admin.outils.test-notification', ['user' => $candidat->id, 'q' => $recherche]) }}"
                       data-push-recipient="{{ $candidat->email }}"
                       class="inline-flex min-h-11 items-center rounded-lg border px-3 text-sm transition
                              {{ $choisi?->id === $candidat->id
                                 ? 'border-indigo-500 bg-indigo-50 font-semibold text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-200'
                                 : 'border-gray-300 text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-300' }}">
                        {{ $candidat->email }}
                    </a>
                </li>
            @endforeach
        </ul>
    @endif

    {{-- 2. Choisir l'appareil, et composer le message. --}}
    @if($choisi)
        <h2 class="mb-2 text-base font-semibold text-gray-900 dark:text-gray-100">
            {{ __('admin.push_test_step_device', ['email' => $choisi->email]) }}
        </h2>

        @if($appareils->isEmpty())
            <p data-push-no-device class="mb-6 rounded-lg bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-900/20 dark:text-amber-200">
                {{ __('admin.push_test_no_eligible_device') }}
            </p>
        @else
            <form method="POST" action="{{ route('admin.outils.test-notification.send') }}" class="space-y-4">
                @csrf
                <input type="hidden" name="user" value="{{ $choisi->id }}">

                <fieldset class="space-y-2">
                    @foreach($appareils as $appareil)
                        <label data-push-device-row
                               class="flex cursor-pointer items-center gap-3 rounded-lg border border-gray-200 p-3 text-sm has-[:checked]:border-indigo-500 has-[:checked]:bg-indigo-50 dark:border-gray-700 dark:has-[:checked]:bg-indigo-900/20">
                            <input type="radio" name="subscription" value="{{ $appareil->id }}" required
                                   @checked($loop->first)
                                   class="text-indigo-600 focus:ring-indigo-500">
                            <span class="text-gray-800 dark:text-gray-200">
                                <strong>{{ $appareil->libelle ?? __('admin.push_test_device_unknown') }}</strong>
                                · {{ $appareil->fournisseur }}
                                · <span class="font-mono text-xs">#{{ $appareil->empreinte }}</span>
                                · {{ $appareil->vu?->diffForHumans() }}
                                · <span class="text-xs">{{ $appareil->origine }}</span>
                            </span>
                        </label>
                    @endforeach
                </fieldset>

                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block">
                        <span class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('admin.push_test_field_title') }}</span>
                        <input type="text" name="title" maxlength="80" required
                               value="{{ old('title', __('admin.push_test_title')) }}"
                               class="min-h-11 w-full rounded-lg border border-gray-300 px-3 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('admin.push_test_field_path') }}</span>
                        <input type="text" name="path" maxlength="300" placeholder="/notifications"
                               value="{{ old('path') }}"
                               class="min-h-11 w-full rounded-lg border border-gray-300 px-3 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                    </label>
                </div>

                <label class="block">
                    <span class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('admin.push_test_field_body') }}</span>
                    <input type="text" name="body" maxlength="160" required
                           value="{{ old('body', __('admin.push_test_body')) }}"
                           class="min-h-11 w-full rounded-lg border border-gray-300 px-3 text-sm dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                </label>

                <button type="submit" data-push-test-send
                        class="inline-flex min-h-11 items-center gap-2 rounded-lg bg-indigo-600 px-5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700">
                    {{ __('admin.push_test_send') }}
                </button>
            </form>
        @endif
    @endif
</x-admin-layout>
