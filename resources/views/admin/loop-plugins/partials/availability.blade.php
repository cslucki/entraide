{{-- TASK-1879 — la disponibilite d'UN plugin, Organization par Organization.
     Partagee par tous les ecrans de plugin. Un formulaire par Organization :
     le geste ne peut porter que sur celle-ci. Attendus : $plugin, $organizations. --}}
                    <div class="mt-5">
                        <div class="flex items-baseline justify-between">
                            <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-400">
                                {{ __('loops.plugins_admin_organizations') }}
                            </h3>
                            <span class="text-xs text-gray-400" data-plugin-count>
                                {{ __('loops.plugins_admin_enabled_count', [
                                    'count' => $plugin['enabled_count'],
                                    'total' => $organizations->count(),
                                ]) }}
                            </span>
                        </div>

                        @if($organizations->isEmpty())
                            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">{{ __('loops.plugins_admin_no_organizations') }}</p>
                        @else
                            <ul class="mt-3 divide-y divide-gray-100 dark:divide-gray-700">
                                @foreach($organizations as $organization)
                                    @php
                                        // TASK-1611 : un bloc, jamais deux directives PHP en
                                        // ligne — Blade recopierait alors en PHP tout ce qui se
                                        // trouve entre les deux. Et la FORME de la directive ne
                                        // s'ecrit pas non plus dans ce commentaire : le
                                        // compilateur la reconnaitrait ici aussi.
                                        $actif = $plugin['availability'][$organization->id] ?? false;
                                        $decision = $plugin['decisions'][$organization->id] ?? null;
                                    @endphp
                                    <li class="flex flex-wrap items-center justify-between gap-3 py-3"
                                        data-organization="{{ $organization->id }}">
                                        <div class="min-w-0">
                                            <p class="truncate text-sm font-medium text-gray-900 dark:text-gray-100">{{ $organization->name }}</p>
                                            <p class="truncate font-mono text-xs text-gray-400">{{ $organization->slug }}</p>
                                            @if($decision?->updated_at)
                                                <p class="mt-0.5 text-xs text-gray-400">
                                                    {{ $decision->updatedBy
                                                        ? __('loops.plugins_admin_last_decision', [
                                                            'date' => $decision->updated_at->format('d/m/Y H:i'),
                                                            'author' => $decision->updatedBy->name,
                                                          ])
                                                        : __('loops.plugins_admin_last_decision_anonymous', [
                                                            'date' => $decision->updated_at->format('d/m/Y H:i'),
                                                          ]) }}
                                                </p>
                                            @endif
                                        </div>

                                        <div class="flex shrink-0 items-center gap-3">
                                            <span data-availability="{{ $actif ? 'on' : 'off' }}"
                                                  class="rounded-full px-2.5 py-1 text-xs font-medium
                                                         {{ $actif
                                                            ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300'
                                                            : 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400' }}">
                                                {{ $actif ? __('loops.plugins_admin_available') : __('loops.plugins_admin_unavailable') }}
                                            </span>

                                            {{-- Un formulaire par Organization : le geste ne peut
                                                 porter que sur celle-ci. --}}
                                            <form method="POST" action="{{ route('admin.loop-plugins.update', $plugin['key']) }}">
                                                @csrf
                                                @method('PUT')
                                                <input type="hidden" name="organization_id" value="{{ $organization->id }}">
                                                <input type="hidden" name="available" value="{{ $actif ? 0 : 1 }}">
                                                <button type="submit"
                                                        data-action="{{ $actif ? 'disable' : 'enable' }}"
                                                        class="min-h-[44px] rounded-xl px-4 text-sm font-medium
                                                               {{ $actif
                                                                  ? 'border border-gray-300 text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700'
                                                                  : 'bg-indigo-600 text-white hover:bg-indigo-700' }}">
                                                    {{ $actif ? __('loops.plugins_admin_disable') : __('loops.plugins_admin_enable') }}
                                                </button>
                                            </form>
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
