{{--
    Shared permission matrix, used by both the global and the Organization
    screens. They differ only in which layer they write and how much they may
    change — building the cells twice would let the two drift.

    A cell has three states, never a bare checkbox: unchecked would be ambiguous
    between "inherited and denied" and "explicitly denied", which are different
    things with different consequences when the inherited value later changes.

    ONE set of inputs, reflowed by CSS. There used to be two — a desktop table
    and a mobile stack — and because radios are grouped by `name`, the two
    copies of every cell formed a single group: the browser kept only the last
    one checked, which was the hidden mobile copy. A saved setting therefore
    showed no selected segment at all on desktop. A grid that becomes a stack
    below `lg` gives the same two layouts with a single input per cell.
--}}
@props([
    'modules',
    'roles',
    'scope' => 'global',      // 'global' | 'organization'
    'inheritLabel' => null,   // what "inherited" means on this screen
])

@php
    // One colour per role, defined here and passed down, so the header, the
    // legend and every configured cell always agree. Written out in full
    // because Tailwind scans this file and would never generate a concatenated
    // class name.
    //
    // Three identical-looking columns made a setting defined for an Animateur
    // indistinguishable at a glance from one defined for a member. The colour
    // now says *which role* a setting belongs to; allowed and denied keep
    // emerald and rose inside the control, and every colour is doubled by a
    // word.
    $rolePalettes = [
        'owner' => [
            'ring' => 'bg-violet-50/70 ring-1 ring-violet-400 dark:bg-violet-900/25 dark:ring-violet-500',
            'badge' => 'bg-violet-100 text-violet-800 dark:bg-violet-900/50 dark:text-violet-200',
            'header' => 'text-violet-700 dark:text-violet-300',
            'dot' => 'bg-violet-500',
        ],
        'facilitator' => [
            'ring' => 'bg-sky-50/70 ring-1 ring-sky-400 dark:bg-sky-900/25 dark:ring-sky-500',
            'badge' => 'bg-sky-100 text-sky-800 dark:bg-sky-900/50 dark:text-sky-200',
            'header' => 'text-sky-700 dark:text-sky-300',
            'dot' => 'bg-sky-500',
        ],
        'member' => [
            'ring' => 'bg-amber-50/70 ring-1 ring-amber-400 dark:bg-amber-900/25 dark:ring-amber-500',
            'badge' => 'bg-amber-100 text-amber-900 dark:bg-amber-900/50 dark:text-amber-200',
            'header' => 'text-amber-700 dark:text-amber-300',
            'dot' => 'bg-amber-500',
        ],
    ];
    $paletteFor = fn (string $role) => $rolePalettes[$role] ?? $rolePalettes['member'];

    // Label column, then one column per role — the single place the geometry is
    // declared, so header and rows can never fall out of step.
    $gridClass = 'lg:grid lg:grid-cols-[minmax(0,1fr)_repeat(3,minmax(0,14rem))] lg:gap-4';

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

    {{-- The colour code, stated once, so a coloured cell is never a riddle. --}}
    <div class="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-2xl border border-gray-200 bg-white px-4 py-3 text-xs dark:border-gray-700 dark:bg-gray-800">
        <span class="font-semibold text-gray-500 dark:text-gray-400">{{ __('loops.permissions_legend_title') }}</span>
        @foreach($roles as $role)
            <span class="inline-flex items-center gap-1.5 {{ $paletteFor($role)['header'] }}">
                <span class="h-2.5 w-2.5 rounded-full {{ $paletteFor($role)['dot'] }}" aria-hidden="true"></span>
                <span class="font-medium">{{ __('loops.members_role_'.$role) }}</span>
            </span>
        @endforeach
        <span class="text-gray-400 dark:text-gray-500">{{ __('loops.permissions_legend_hint') }}</span>
    </div>

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

            {{-- Column header. Below `lg` every cell carries its own role label,
                 so this row is not merely hidden there — it is redundant. --}}
            <div class="hidden border-y border-gray-100 px-4 py-2 text-xs uppercase tracking-wide {{ $gridClass }} dark:border-gray-700">
                <span class="font-medium text-gray-400">{{ __('loops.permissions_column_permission') }}</span>
                @foreach($roles as $role)
                    <span class="inline-flex items-center gap-1.5 font-semibold {{ $paletteFor($role)['header'] }}">
                        <span class="h-2 w-2 rounded-full {{ $paletteFor($role)['dot'] }}" aria-hidden="true"></span>
                        {{ __('loops.members_role_'.$role) }}
                    </span>
                @endforeach
            </div>

            <div class="divide-y divide-gray-100 dark:divide-gray-700">
                @foreach($permissions as $permission)
                    <div class="px-4 py-3 {{ $gridClass }}">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-gray-800 dark:text-gray-100">
                                {{ $permission['label'] }}
                                @if($permission['locked'])
                                    <span class="ml-1 rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-gray-500 dark:bg-gray-700 dark:text-gray-300">
                                        {{ __('loops.permissions_locked') }}
                                    </span>
                                @endif
                            </p>
                            <p id="desc-{{ Str::slug($permission['key']) }}" class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                {{ $permission['description'] }}
                            </p>
                        </div>

                        {{-- `lg:contents` lifts the three role blocks into the
                             grid; below `lg` they stack under the label. --}}
                        <div class="mt-3 space-y-2 lg:mt-0 lg:contents">
                            @foreach($roles as $role)
                                @php $cell = $permission['cells'][$role]; @endphp
                                <div class="flex flex-wrap items-center justify-between gap-2 lg:block">
                                    <span class="inline-flex items-center gap-1.5 text-xs font-semibold lg:hidden {{ $paletteFor($role)['header'] }}">
                                        <span class="h-2 w-2 rounded-full {{ $paletteFor($role)['dot'] }}" aria-hidden="true"></span>
                                        {{ __('loops.members_role_'.$role) }}
                                    </span>
                                    <x-loops.permission-cell
                                        :permission="$permission"
                                        :role="$role"
                                        :cell="$cell"
                                        :palette="$paletteFor($role)"
                                        :state-labels="$stateLabels"
                                        :scope="$scope" />
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </details>
    @endforeach
</div>
