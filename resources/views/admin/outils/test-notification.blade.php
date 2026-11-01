{{--
    TASK-1740 — diagnostic du Web Push et envoi d'une notification de test.

    L'écran SÉPARE les trois causes possibles d'un envoi silencieux :
    configuration absente, aucun appareil, ou sortie réseau refusée par
    `ExternalEgressGuard`. Les trois produisent le même silence ; sans cette
    séparation, la recette se passerait à deviner laquelle s'applique.
--}}
<x-admin-layout :title="__('admin.push_test_title_page')">
    <div class="mb-6">
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('admin.push_test_intro') }}</p>
        <p class="mt-2 text-sm font-medium text-indigo-700 dark:text-indigo-300">{{ __('admin.push_test_only_self') }}</p>
    </div>

    @if(session('push_test_resultat'))
        <div data-push-test-resultat
             class="mb-4 rounded-lg border border-indigo-200 bg-indigo-50 p-3 text-sm text-indigo-800 dark:border-indigo-800 dark:bg-indigo-900/20 dark:text-indigo-200">
            {{ session('push_test_resultat') }}
            @if(session('push_test_detail'))
                <ul class="mt-2 list-inside list-disc text-xs">
                    @foreach(session('push_test_detail') as $motif)
                        <li class="break-words">{{ $motif }}</li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif

    {{-- Le diagnostic, poste par poste. --}}
    <div class="mb-6 overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700">
        <table class="w-full text-sm">
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                @php
                    $ligne = function (string $libelle, bool $ok, string $valeur) {
                        return [$libelle, $ok, $valeur];
                    };
                    $postes = [
                        $ligne('WEB_PUSH_ENABLED', $pushActif, $pushActif ? __('admin.push_test_state_on') : __('admin.push_test_state_off')),
                        $ligne('VAPID_PUBLIC_KEY', $clePubliquePosee, $clePubliquePosee ? __('admin.push_test_state_on') : __('admin.push_test_state_off')),
                        // La valeur n'est JAMAIS affichée : on dit seulement
                        // qu'elle est posée. Une clef privée ne s'imprime pas.
                        $ligne('VAPID_PRIVATE_KEY', $clePriveePosee, $clePriveePosee ? __('admin.push_test_state_on') : __('admin.push_test_state_off')),
                        $ligne('VAPID_SUBJECT', $sujet !== '', $sujet !== '' ? $sujet : '—'),
                        $ligne('Base de données', true, $base),
                        $ligne(
                            'Sorties HTTP',
                            ! $sortiesBloquees || $pushAutorise,
                            ! $sortiesBloquees
                                ? __('admin.push_test_egress_ok')
                                : ($pushAutorise ? __('admin.push_test_egress_push_only') : __('admin.push_test_egress_blocked')),
                        ),
                        $ligne('Mes appareils', $mesAppareils->isNotEmpty(), (string) $mesAppareils->count()),
                    ];
                @endphp
                @foreach($postes as [$libelle, $ok, $valeur])
                    <tr data-push-diag="{{ \Illuminate\Support\Str::slug($libelle) }}">
                        <th scope="row" class="w-48 bg-gray-50 px-3 py-2 text-left font-medium text-gray-700 dark:bg-gray-800 dark:text-gray-300">
                            {{ $libelle }}
                        </th>
                        <td class="px-3 py-2">
                            <span class="mr-2 inline-block h-2.5 w-2.5 rounded-full {{ $ok ? 'bg-emerald-500' : 'bg-amber-500' }}"></span>
                            <span class="text-gray-700 dark:text-gray-300">{{ $valeur }}</span>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if($mesAppareils->isNotEmpty())
        <ul class="mb-6 space-y-1 text-xs text-gray-500 dark:text-gray-400">
            @foreach($mesAppareils as $appareil)
                <li data-push-device-row>
                    {{ $appareil->device_label ?? '—' }}
                    · {{ parse_url($appareil->endpoint, PHP_URL_HOST) }}
                    · {{ $appareil->last_seen_at?->diffForHumans() }}
                </li>
            @endforeach
        </ul>
    @endif

    <form method="POST" action="{{ route('admin.outils.test-notification.send') }}">
        @csrf
        <button type="submit"
                data-push-test-send
                @disabled($mesAppareils->isEmpty())
                class="inline-flex min-h-11 items-center gap-2 rounded-lg bg-indigo-600 px-5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 disabled:opacity-50">
            {{ __('admin.push_test_send') }}
        </button>
    </form>
</x-admin-layout>
