{{--
    TASK-1744 (demande Cyril, 02/11) — l'inspecteur des notifications émises.

    Il répond à « qu'est-ce qui est parti, vers qui, et pourquoi ça n'est pas
    arrivé ». Le cockpit T1380 répond à une autre question — « le système
    fonctionne-t-il » — et il COMPTE sans nommer personne. Les deux coexistent
    volontairement : voir le docblock du contrôleur.

    Ce que cet écran montre et que le cockpit refuse de montrer : le
    destinataire, et le `diagnostic` de chaque livraison. C'est exactement ce
    qui manquait pendant trois recettes Push, alors que la réponse était en
    base depuis le début.

    Ce qu'il ne montre pas : aucun contenu de message, aucun titre de Push,
    aucun endpoint, aucune clé. On lit des ÉTATS, pas des conversations.
--}}
<x-admin-layout :title="__('admin.notif_inspector_title')">
    <div class="mb-6">
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('admin.notif_inspector_intro') }}</p>
        <p class="mt-2 text-sm font-medium text-indigo-700 dark:text-indigo-300">{{ __('admin.notif_inspector_scope') }}</p>
    </div>

    {{-- TASK-1744 — la panne du 03/11, rendue visible.

         Un évènement réel passe par la FILE ; le rejeu, lui, est synchrone.
         Sans worker, le job dort, la livraison reste `pending`, et tout le
         reste a l'air normal : préférence cochée, appareil abonné,
         notification affichée dans l'application. Rien ne disait que
         personne ne ramassait.

         Une livraison `pending` n'est donc pas un état neutre ici. Ce
         bandeau tranche entre « ça va partir » et « ça ne partira pas ». --}}
    @if(($enAttente ?? 0) > 0)
        <div data-file-en-attente="{{ $enAttente }}"
             class="mb-4 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-900/20 dark:text-amber-200">
            <p class="font-semibold">{{ trans_choice('admin.notif_inspector_queue_waiting', $enAttente, ['count' => $enAttente]) }}</p>
            <p class="mt-1 text-xs">{{ __('admin.notif_inspector_queue_hint') }}</p>
            <p class="mt-1 font-mono text-[11px]">php artisan queue:work --queue=notifications-web-push,notifications-email</p>
        </div>
    @endif

    @if(session('inspecteur_resultat'))
        <div data-inspecteur-resultat
             class="mb-4 rounded-lg border border-indigo-200 bg-indigo-50 p-3 text-sm text-indigo-800 dark:border-indigo-800 dark:bg-indigo-900/20 dark:text-indigo-200">
            {{ session('inspecteur_resultat') }}
        </div>
    @endif

    <form method="GET" class="mb-5 flex flex-wrap items-end gap-3">
        <label class="flex flex-col gap-1 text-xs font-semibold uppercase tracking-wide text-gray-500">
            {{ __('admin.notif_inspector_filter_recipient') }}
            <input type="search" name="q" value="{{ $filtres['q'] }}"
                   placeholder="exemple@bouclepro.test"
                   class="min-h-11 w-64 rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800">
        </label>

        <label class="flex flex-col gap-1 text-xs font-semibold uppercase tracking-wide text-gray-500">
            {{ __('admin.notif_inspector_filter_key') }}
            <select name="cle" class="min-h-11 rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800">
                <option value="">{{ __('admin.notif_inspector_filter_all') }}</option>
                @foreach($cles as $uneCle)
                    <option value="{{ $uneCle }}" @selected($filtres['cle'] === $uneCle)>{{ $uneCle }}</option>
                @endforeach
            </select>
        </label>

        <label class="flex flex-col gap-1 text-xs font-semibold uppercase tracking-wide text-gray-500">
            {{ __('admin.notif_inspector_filter_channel') }}
            <select name="canal" class="min-h-11 rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-800">
                <option value="">{{ __('admin.notif_inspector_filter_all') }}</option>
                @foreach(['in_app', 'email', 'web_push'] as $unCanal)
                    <option value="{{ $unCanal }}" @selected($filtres['canal'] === $unCanal)>{{ $unCanal }}</option>
                @endforeach
            </select>
        </label>

        <button type="submit"
                class="inline-flex min-h-11 items-center rounded-lg bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700">
            {{ __('admin.notif_inspector_filter_submit') }}
        </button>
    </form>

    {{-- Le tableau défile dans SON cadre : sur un écran étroit, c'est lui qui
         glisse, jamais la page entière. --}}
    <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
        <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:bg-gray-800">
                <tr>
                    <th class="px-3 py-2">{{ __('admin.notif_inspector_col_when') }}</th>
                    <th class="px-3 py-2">{{ __('admin.notif_inspector_col_key') }}</th>
                    <th class="px-3 py-2">{{ __('admin.notif_inspector_col_recipient') }}</th>
                    <th class="px-3 py-2">{{ __('admin.notif_inspector_col_deliveries') }}</th>
                    <th class="px-3 py-2">{{ __('admin.notif_inspector_col_action') }}</th>
                </tr>
            </thead>

            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                @forelse($notifications as $notification)
                    @php
                        $destinataire = $destinataires[$notification->recipient_id] ?? null;
                        $lignes = $livraisons[$notification->id] ?? collect();
                        $compteRecette = $destinataire !== null && $rejouables->has($destinataire->id);
                        $nbAppareils = $destinataire === null ? 0 : ($appareils[(string) $destinataire->id] ?? 0);
                        $rejouable = $compteRecette && $nbAppareils > 0;
                    @endphp

                    <tr data-notification="{{ $notification->id }}">
                        <td class="whitespace-nowrap px-3 py-2 font-mono text-xs text-gray-500">
                            {{ $notification->created_at?->format('d/m H:i:s') }}
                        </td>

                        <td class="px-3 py-2 font-mono text-xs">{{ $notification->notification_key }}</td>

                        <td class="px-3 py-2 text-xs">
                            {{ $destinataire?->email ?? '—' }}

                            {{-- Le nombre d'appareils envoyables, affiché même
                                 à zéro : c'est à zéro qu'il explique tout. --}}
                            <span data-appareils="{{ $nbAppareils }}"
                                  class="mt-1 block font-mono text-[11px] {{ $nbAppareils > 0 ? 'text-gray-500' : 'text-amber-700 dark:text-amber-300' }}">
                                {{ trans_choice('admin.notif_inspector_devices', $nbAppareils, ['count' => $nbAppareils]) }}
                            </span>

                            {{-- TASK-1823 — le pont vers « Appareils Push ».
                                 Ce compteur dit COMBIEN d'appareils sont
                                 envoyables ; l'autre écran dit LESQUELS et
                                 dans quel état. Le lien n'apparaît que pour
                                 qui peut ouvrir cette console — la garde
                                 `superadmin-push-all-devices` refuserait
                                 sinon un lien proposé.

                                 « Rechercher » et non « ouvrir » : `q` est une
                                 recherche partielle sur l'email. --}}
                            @can('superadmin-push-all-devices')
                                @if($destinataire?->email)
                                    <a href="{{ route('admin.outils.my-push-devices', ['q' => $destinataire->email]) }}"
                                       data-lien-appareils-push
                                       class="mt-1 inline-flex items-center text-[11px] font-semibold text-indigo-700 underline dark:text-indigo-300">
                                        {{ __('admin.push_diag_link_devices') }}
                                    </a>
                                @endif
                            @endcan
                        </td>

                        <td class="px-3 py-2">
                            {{-- `in_app` n'a JAMAIS de ligne de livraison : il est
                                 livré par l'existence même de la notification. Le
                                 dire plutôt que de laisser une case vide, qui se
                                 lirait comme un échec. --}}
                            <span class="mr-2 inline-flex items-center rounded bg-gray-100 px-1.5 py-0.5 font-mono text-[11px] text-gray-600 dark:bg-gray-700 dark:text-gray-300">
                                in_app · {{ __('admin.notif_inspector_in_app_implicit') }}
                            </span>

                            @foreach($lignes as $ligne)
                                @php
                                    $couleur = match ($ligne->status) {
                                        'sent' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200',
                                        'failed' => 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200',
                                        'pending', 'sending' => 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
                                        default => 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300',
                                    };
                                @endphp

                                <span data-livraison="{{ $ligne->channel }}:{{ $ligne->status }}"
                                      class="mr-2 inline-flex items-center rounded px-1.5 py-0.5 font-mono text-[11px] {{ $couleur }}">
                                    {{ $ligne->channel }} · {{ $ligne->status }}@if($ligne->diagnostic) · {{ $ligne->diagnostic }}@endif
                                </span>
                            @endforeach
                        </td>

                        <td class="whitespace-nowrap px-3 py-2">
                            @if($rejouable)
                                <form method="POST" action="{{ route('admin.outils.notifications.replay', $notification->id) }}">
                                    @csrf
                                    <button type="submit" data-rejouer
                                            class="inline-flex min-h-11 items-center rounded-lg border border-indigo-500 px-3 text-xs font-semibold text-indigo-700 hover:bg-indigo-50 dark:text-indigo-300 dark:hover:bg-indigo-900/30">
                                        {{ __('admin.notif_inspector_replay') }}
                                    </button>
                                </form>
                            @else
                                {{-- Pas de bouton grisé : un bouton qu'on ne peut
                                     pas actionner invite quand même à essayer. On
                                     dit pourquoi il n'y en a pas — et les deux
                                     raisons ne se confondent pas. --}}
                                <span data-non-rejouable class="text-[11px] italic text-amber-700 dark:text-amber-300">
                                    {{ $compteRecette
                                        ? __('admin.notif_inspector_no_device')
                                        : __('admin.notif_inspector_real_account') }}
                                </span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-3 py-6 text-center text-sm text-gray-500">
                            {{ __('admin.notif_inspector_empty') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $notifications->links() }}
    </div>
</x-admin-layout>
