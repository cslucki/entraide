{{--
    Shared permission matrix, used by both the global and the Organization
    screens. They differ only in which layer they write and how much they may
    change — building the cells twice would let the two drift.

    A cell has three states, never a bare checkbox: unchecked would be ambiguous
    between "inherited and denied" and "explicitly denied", which are different
    things with different consequences when the inherited value later changes.

    Desktop shows a table; below `lg` each permission becomes a compact block,
    because a three-column matrix scrolled sideways on a phone is unusable.
--}}
@props([
    'modules',
    'roles',
    'scope' => 'global',      // 'global' | 'organization'
    'inheritLabel' => null,   // what "inherited" means on this screen
])

@php
    $stateLabels = [
        'inherited' => $inheritLabel ?? __('loops.permissions_state_inherited'),
        'allowed' => __('loops.permissions_state_allowed'),
        'denied' => __('loops.permissions_state_denied'),
    ];
    $moduleLabels = [
        'loops' => __('loops.permissions_module_loops'),
        'members' => __('loops.permissions_module_members'),
        'manifesto' => __('loops.permissions_module_manifesto'),
        'roadmap' => __('loops.permissions_module_roadmap'),
        'chatloop' => __('loops.permissions_module_chatloop'),
        'invitations' => __('loops.permissions_module_invitations'),
    ];
@endphp

<div class="space-y-4">
    @foreach($modules as $module => $permissions)
        {{-- Collapsible so 24 permissions never land as one crushing wall. --}}
        <details open class="rounded-2xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
            @php
                // How many cells in this module carry an explicit setting, so a
                // configured module is identifiable without opening it.
                $configured = collect($permissions)
                    ->flatMap(fn ($p) => array_values($p['cells']))
                    ->filter(fn ($c) => $c['state'] !== 'inherited')
                    ->count();
            @endphp
            <summary class="cursor-pointer select-none px-4 py-3 text-sm font-semibold text-gray-800 marker:text-gray-400 dark:text-gray-100">
                {{ $moduleLabels[$module] ?? $module }}
                <span class="ml-1 text-xs font-normal text-gray-400">({{ count($permissions) }})</span>
                @if($configured)
                    <span class="ml-2 rounded-full bg-indigo-100 px-2 py-0.5 text-[11px] font-semibold text-indigo-700 dark:bg-indigo-900/50 dark:text-indigo-300">
                        {{ trans_choice('loops.permissions_configured_count', $configured, ['count' => $configured]) }}
                    </span>
                @endif
            </summary>

            {{-- Desktop --}}
            <div class="hidden lg:block">
                <table class="w-full text-left text-sm">
                    <thead class="border-y border-gray-100 text-xs uppercase tracking-wide text-gray-400 dark:border-gray-700">
                        <tr>
                            <th scope="col" class="px-4 py-2 font-medium">{{ __('loops.permissions_column_permission') }}</th>
                            @foreach($roles as $role)
                                <th scope="col" class="px-4 py-2 font-medium">{{ __('loops.members_role_'.$role) }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach($permissions as $permission)
                            <tr>
                                <th scope="row" class="px-4 py-3 align-top font-normal">
                                    <span class="block font-medium text-gray-800 dark:text-gray-100">
                                        {{ $permission['label'] }}
                                        @if($permission['locked'])
                                            <span class="ml-1 rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-gray-500 dark:bg-gray-700 dark:text-gray-300">
                                                {{ __('loops.permissions_locked') }}
                                            </span>
                                        @endif
                                    </span>
                                    <span id="desc-{{ Str::slug($permission['key']) }}" class="mt-0.5 block text-xs text-gray-500 dark:text-gray-400">
                                        {{ $permission['description'] }}
                                    </span>
                                </th>

                                @foreach($roles as $role)
                                    @php $cell = $permission['cells'][$role]; @endphp
                                    <td class="px-4 py-3 align-top">
                                        <x-loops.permission-cell
                                            :permission="$permission"
                                            :role="$role"
                                            :cell="$cell"
                                            :state-labels="$stateLabels"
                                            :scope="$scope" />
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Mobile: one block per permission, no horizontal scrolling. --}}
            <div class="divide-y divide-gray-100 lg:hidden dark:divide-gray-700">
                @foreach($permissions as $permission)
                    <div class="px-4 py-3">
                        <p class="text-sm font-medium text-gray-800 dark:text-gray-100">
                            {{ $permission['label'] }}
                            @if($permission['locked'])
                                <span class="ml-1 rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-gray-500 dark:bg-gray-700 dark:text-gray-300">
                                    {{ __('loops.permissions_locked') }}
                                </span>
                            @endif
                        </p>
                        <p id="desc-m-{{ Str::slug($permission['key']) }}" class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                            {{ $permission['description'] }}
                        </p>

                        <div class="mt-3 space-y-2">
                            @foreach($roles as $role)
                                @php $cell = $permission['cells'][$role]; @endphp
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <span class="text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('loops.members_role_'.$role) }}</span>
                                    <x-loops.permission-cell
                                        :permission="$permission"
                                        :role="$role"
                                        :cell="$cell"
                                        :state-labels="$stateLabels"
                                        :scope="$scope"
                                        mobile />
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </details>
    @endforeach
</div>
