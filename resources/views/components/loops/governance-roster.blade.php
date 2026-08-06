{{--
    Loop governance: owners, facilitators and members, with named actions.

    Shared by the global admin, the Organization admin and the Members card, so
    the three cannot drift on what an action is called or when it is offered.

    Actions are named after what they do — "Make owner", "Back to member" — not
    a technical role field auto-submitted on change. Availability comes from the
    caller's resolved permissions, never from reading a role label in Blade.

    Densite : une personne tient sur une ligne. Les trois listes separees, leurs
    titres et leur tiret quand elles etaient vides coutaient une demi-hauteur
    d'ecran ; le role se lit maintenant sur la ligne, et les actions — rarement
    utilisees, jamais urgentes — attendent dans un menu au lieu d'etaler deux
    boutons larges en face de chaque nom.
--}}
@props([
    'members',            // Collection<LoopMember>, active
    'roleRoute',          // fn(LoopMember): string
    'removeRoute',        // fn(LoopMember): string|null
    'canManageOwners' => false,
    'canManageFacilitators' => false,
    'canRemove' => false,
    'creatorId' => null,
    'currentUserId' => null,
])

@php
    $roles = app(\App\Support\Loops\LoopRoleRegistry::class);

    $OWNER = \App\Support\Loops\LoopRoleRegistry::OWNER;
    $FACILITATOR = \App\Support\Loops\LoopRoleRegistry::FACILITATOR;
    $MEMBER = \App\Support\Loops\LoopRoleRegistry::MEMBER;

    $grouped = $members->groupBy(fn ($m) => $roles->canonical($m->role));
    $ownerCount = $grouped->get($OWNER, collect())->count();

    // Un seul flux, gouvernance d'abord : on lit qui decide avant qui participe.
    $rank = [$OWNER => 0, $FACILITATOR => 1, $MEMBER => 2];
    $ordered = $members
        ->sortBy(fn ($m) => [$rank[$roles->canonical($m->role)] ?? 3, mb_strtolower($m->user?->publicDisplayName() ?? '')])
        ->values();

    $chips = [
        $OWNER => ['label' => __('loops.members_role_owner'), 'class' => 'bg-violet-100 text-violet-700 dark:bg-violet-500/20 dark:text-violet-200'],
        $FACILITATOR => ['label' => __('loops.members_role_facilitator'), 'class' => 'bg-sky-100 text-sky-700 dark:bg-sky-500/20 dark:text-sky-200'],
    ];
@endphp

<div {{ $attributes->merge(['class' => 'space-y-3']) }}>

    @if($ordered->isEmpty())
        <p class="text-xs text-gray-400">—</p>
    @else
        <ul class="divide-y divide-gray-100 dark:divide-gray-800">
            @foreach($ordered as $member)
                @php
                    $role = $roles->canonical($member->role);
                    $isLastOwner = $role === $OWNER && $ownerCount === 1;
                    $isSelf = $currentUserId !== null && $member->user_id === $currentUserId;

                    // Ce qui serait propose : si rien ne l'est, pas de menu.
                    $canPromoteOwner = $role !== $OWNER && $canManageOwners;
                    $canPromoteFacilitator = $role === $MEMBER && $canManageFacilitators;
                    $canDemoteOwner = $role === $OWNER && $canManageOwners && ! $isLastOwner;
                    $canDemoteFacilitator = $role === $FACILITATOR && $canManageFacilitators;
                    $canDrop = $canRemove && $removeRoute && ! $isLastOwner;
                    $hasActions = $canPromoteOwner || $canPromoteFacilitator || $canDemoteOwner || $canDemoteFacilitator || $canDrop;
                @endphp

                <li class="flex items-center gap-2.5 py-1.5">
                    <x-user-avatar :user="$member->user" size="sm" />

                    <span class="min-w-0 flex-1">
                        <span class="flex items-center gap-1.5">
                            <span class="truncate text-sm font-medium text-gray-800 dark:text-gray-100">
                                {{ $member->user?->publicDisplayName() ?? '—' }}
                            </span>
                            @isset($chips[$role])
                                <span class="shrink-0 rounded-full px-1.5 py-px text-[9px] font-bold uppercase tracking-wide {{ $chips[$role]['class'] }}">
                                    {{ $chips[$role]['label'] }}
                                </span>
                            @endisset
                        </span>

                        @php
                            $notes = [];
                            if ($creatorId && $member->user_id === $creatorId) {
                                $notes[] = __('loops.governance_creator');
                            }
                            if ($roles->isLegacyAlias($member->role)) {
                                $notes[] = $member->role;
                            }
                            if ($isLastOwner) {
                                $notes[] = __('loops.governance_last_owner_short');
                            }
                        @endphp
                        @if($notes !== [])
                            <span class="block truncate text-[10px] leading-tight text-gray-400">{{ implode(' · ', $notes) }}</span>
                        @endif
                    </span>

                    @if($hasActions)
                        {{-- Les actions de gouvernance se prennent rarement et
                             jamais dans l'urgence : elles n'ont pas a occuper
                             une ligne en face de chaque nom. --}}
                        <div x-data="{ open: false }" class="relative shrink-0"
                             x-on:keydown.escape.window="open = false">
                            <button type="button"
                                    x-on:click="open = ! open"
                                    x-bind:aria-expanded="open ? 'true' : 'false'"
                                    class="flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 focus-visible:ring-2 focus-visible:ring-indigo-500 dark:hover:bg-gray-800 dark:hover:text-gray-200"
                                    aria-label="{{ __('loops.governance_actions_for', ['name' => $member->user?->publicDisplayName() ?? '—']) }}">
                                <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                                    <path d="M10 6a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Zm0 5.5a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Zm0 5.5a1.5 1.5 0 1 1 0-3 1.5 1.5 0 0 1 0 3Z"/>
                                </svg>
                            </button>

                            <div x-show="open" x-cloak
                                 x-on:click.outside="open = false"
                                 x-transition.opacity.duration.120ms
                                 class="absolute right-0 top-9 z-30 w-56 overflow-hidden rounded-xl border border-gray-200 bg-white p-1 shadow-lg dark:border-gray-700 dark:bg-gray-900">

                                @if($canPromoteOwner)
                                    <x-loops.governance-action :action="$roleRoute($member)" role="owner"
                                        :label="__('loops.governance_promote_owner')" />
                                @endif

                                @if($canPromoteFacilitator)
                                    <x-loops.governance-action :action="$roleRoute($member)" role="facilitator"
                                        :label="__('loops.governance_promote_facilitator')" />
                                @endif

                                @if($canDemoteOwner)
                                    <x-loops.governance-action :action="$roleRoute($member)" role="facilitator"
                                        :label="__('loops.governance_demote_facilitator')"
                                        :confirm="$isSelf ? __('loops.governance_self_demote_confirm') : null" />
                                    <x-loops.governance-action :action="$roleRoute($member)" role="member"
                                        :label="__('loops.governance_demote_member')"
                                        :confirm="$isSelf ? __('loops.governance_self_demote_confirm') : null" />
                                @endif

                                @if($canDemoteFacilitator)
                                    <x-loops.governance-action :action="$roleRoute($member)" role="member"
                                        :label="__('loops.governance_demote_member')" />
                                @endif

                                @if($canDrop)
                                    <form method="POST" action="{{ $removeRoute($member) }}"
                                          onsubmit="return confirm('{{ __('loops.governance_remove') }} ?')"
                                          class="mt-1 border-t border-gray-100 pt-1 dark:border-gray-800">
                                        @csrf @method('DELETE')
                                        <button type="submit"
                                                class="w-full rounded-lg px-2.5 py-2 text-left text-xs font-medium text-red-600 transition hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/20">
                                            {{ __('loops.governance_remove') }}
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif

    {{-- Said once for the whole roster, not repeated on every row. --}}
    @if($ownerCount === 1)
        <p class="rounded-xl bg-amber-50 px-3 py-2 text-[11px] leading-snug text-amber-800 dark:bg-amber-900/20 dark:text-amber-300">
            {{ __('loops.governance_last_owner') }}
        </p>
    @endif
</div>
