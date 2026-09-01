<x-admin-layout :title="__('admin.scenario_packs_title')">
    <div class="max-w-4xl mx-auto space-y-6">

        <div>
            <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                {{ __('admin.scenario_packs_title') }}
            </h2>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                {{ __('admin.scenario_packs_intro') }}
            </p>
        </div>

        @if (empty($packIds))
            <div class="bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 px-4 py-3 rounded-lg text-sm" data-scenario-packs-empty>
                {{ __('admin.scenario_packs_none') }} (<code>config('scenario_packs.definitions')</code>)
            </div>
        @else
            {{-- Un SEUL choix : le pack. La cible n'est pas une decision de
                 l'utilisateur, elle appartient au pack. --}}
            <form method="GET" action="{{ route('admin.scenario-packs') }}" class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4 flex flex-wrap items-end gap-4" data-scenario-packs-selector>
                <div>
                    <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1" for="scenario-pack-select">{{ __('admin.scenario_packs_pack_label') }}</label>
                    <select id="scenario-pack-select" name="pack" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 text-sm">
                        @foreach ($packIds as $packId)
                            <option value="{{ $packId }}" @selected($packId === $selectedPackId)>{{ $packId }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="px-4 py-2 rounded-lg bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 text-sm font-medium">
                    {{ __('admin.scenario_packs_show') }}
                </button>
            </form>

            @if ($pack && $target)
                @php
                    $organizationName = $target['organization']?->name ?? $target['slug'];
                    $stateLabel = __('admin.scenario_packs_state_'.$target['state']);
                @endphp

                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4 space-y-3" data-scenario-pack-preview data-scenario-pack-state="{{ $target['state'] }}">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $pack->packName() }}</h3>
                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ $pack->purpose() }}</p>

                    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-1 text-sm pt-2 border-t border-gray-100 dark:border-gray-700">
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('admin.scenario_packs_pack_id') }}</dt>
                        <dd class="text-gray-900 dark:text-gray-100"><code>{{ $pack->packId() }}</code></dd>

                        <dt class="text-gray-500 dark:text-gray-400">{{ __('admin.scenario_packs_declared_version') }}</dt>
                        <dd class="text-gray-900 dark:text-gray-100"><code>{{ $pack->packVersion() }}</code></dd>

                        <dt class="text-gray-500 dark:text-gray-400">{{ __('admin.scenario_packs_target_label') }}</dt>
                        <dd class="text-gray-900 dark:text-gray-100" data-scenario-pack-target-slug="{{ $target['slug'] }}">
                            @if ($target['slug'] === null)
                                {{ __('admin.scenario_packs_target_unknown') }}
                            @elseif ($target['exists'])
                                {{ $target['organization']->name }} <code class="text-xs text-gray-500 dark:text-gray-400">{{ $target['slug'] }}</code>
                            @else
                                {{-- Organisation absente : elle n'a pas encore de nom,
                                     son slug est tout ce qui existe d'elle. --}}
                                <code>{{ $target['slug'] }}</code>
                            @endif
                        </dd>

                        <dt class="text-gray-500 dark:text-gray-400">{{ __('admin.scenario_packs_state_label') }}</dt>
                        <dd class="text-gray-900 dark:text-gray-100" data-scenario-pack-state-label>{{ $stateLabel }}</dd>
                    </dl>

                    @if ($target['state'] === 'missing')
                        <p class="text-sm rounded-lg px-3 py-2 bg-amber-50 dark:bg-amber-900/20 text-amber-800 dark:text-amber-200" data-scenario-pack-missing-hint="{{ $target['can_provision'] ? 'provisionable' : 'legacy' }}">
                            {{ $target['can_provision']
                                ? __('admin.scenario_packs_missing_provisionable')
                                : __('admin.scenario_packs_missing_legacy') }}
                        </p>
                    @endif
                </div>

                @if ($target['status'] && $target['status']['loaded'])
                    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 p-4 space-y-3" data-scenario-pack-status="loaded">
                        <dl class="grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                            <dt class="text-gray-500 dark:text-gray-400">{{ __('admin.scenario_packs_loaded_version') }}</dt>
                            <dd class="text-gray-900 dark:text-gray-100" data-scenario-pack-loaded-version>{{ $target['status']['pack_version'] }}</dd>
                            <dt class="text-gray-500 dark:text-gray-400">{{ __('admin.scenario_packs_entities') }}</dt>
                            <dd class="text-gray-900 dark:text-gray-100" data-scenario-pack-entity-total>{{ $target['status']['total'] }}</dd>
                            <dt class="text-gray-500 dark:text-gray-400">{{ __('admin.scenario_packs_loaded_at') }}</dt>
                            <dd class="text-gray-900 dark:text-gray-100">{{ $target['status']['loaded_at'] }}</dd>
                            <dt class="text-gray-500 dark:text-gray-400">{{ __('admin.scenario_packs_reset_at') }}</dt>
                            <dd class="text-gray-900 dark:text-gray-100">{{ $target['status']['reset_at'] ?? __('admin.scenario_packs_never') }}</dd>
                        </dl>
                        @if (!empty($target['status']['counts']))
                            <ul class="text-xs text-gray-500 dark:text-gray-400 list-disc list-inside">
                                @foreach ($target['status']['counts'] as $type => $count)
                                    <li data-scenario-pack-count-type="{{ $type }}">{{ $type }} : {{ $count }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @endif

                <div class="flex flex-wrap gap-3">
                    {{-- Sans organisation, il n'y a rien a ouvrir : le bouton
                         disparait plutot que de mener a une page absente. --}}
                    @if ($target['exists'])
                        <a href="{{ route('organization.home', ['organization' => $target['organization']->slug]) }}"
                           class="px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200 text-sm font-medium"
                           data-scenario-pack-action="open-organization">
                            {{ __('admin.scenario_packs_open_organization') }}
                        </a>
                    @endif

                    @if ($target['exists'])
                        <form method="POST" action="{{ route('admin.scenario-packs.load') }}">
                            @csrf
                            <input type="hidden" name="pack" value="{{ $pack->packId() }}">
                            <button type="submit" class="px-4 py-2 rounded-lg bg-emerald-600 text-white text-sm font-medium" data-scenario-pack-action="load">
                                {{ __('admin.scenario_packs_load') }}
                            </button>
                        </form>

                        <form method="POST" action="{{ route('admin.scenario-packs.reset') }}" onsubmit="return confirm(@js(__('admin.scenario_packs_confirm_reset', ['pack' => $pack->packName(), 'organization' => $organizationName])))">
                            @csrf
                            <input type="hidden" name="pack" value="{{ $pack->packId() }}">
                            <button type="submit" class="px-4 py-2 rounded-lg bg-amber-500 text-white text-sm font-medium" data-scenario-pack-action="reset">
                                {{ __('admin.scenario_packs_reset') }}
                            </button>
                        </form>

                        {{-- L'avertissement dit la VERITE du retrait : si cette
                             Organization a ete creee par ce chargement, elle
                             disparaitra avec lui, et avec elle tout ce qu'un
                             humain y aurait ajoute depuis. --}}
                        <form method="POST" action="{{ route('admin.scenario-packs.delete') }}" onsubmit="return confirm(@js($target['created_by_pack']
                                ? __('admin.scenario_packs_confirm_delete_org_removed', ['organization' => $organizationName])
                                : __('admin.scenario_packs_confirm_delete_org_kept', ['pack' => $pack->packName(), 'organization' => $organizationName])))">
                            @csrf
                            <input type="hidden" name="pack" value="{{ $pack->packId() }}">
                            <button type="submit" class="px-4 py-2 rounded-lg bg-red-600 text-white text-sm font-medium"
                                    data-scenario-pack-action="delete"
                                    data-scenario-pack-delete-scope="{{ $target['created_by_pack'] ? 'organization-removed' : 'organization-kept' }}">
                                {{ __('admin.scenario_packs_delete') }}
                            </button>
                        </form>
                    @endif
                </div>
            @endif
        @endif
    </div>
</x-admin-layout>
