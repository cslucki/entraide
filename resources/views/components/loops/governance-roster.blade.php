{{--
    Loop governance: owners, facilitators and members, with named actions.

    Shared by the global admin, the Organization admin and the Members card, so
    the three cannot drift on what an action is called or when it is offered.

    Actions are named after what they do — "Make owner", "Back to member" — not
    a technical role field auto-submitted on change. Availability comes from the
    caller's resolved permissions, never from reading a role label in Blade.
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

    $grouped = $members->groupBy(fn ($m) => $roles->canonical($m->role));
    $ownerCount = $grouped->get(\App\Support\Loops\LoopRoleRegistry::OWNER, collect())->count();

    $sections = [
        \App\Support\Loops\LoopRoleRegistry::OWNER => __('loops.governance_owners'),
        \App\Support\Loops\LoopRoleRegistry::FACILITATOR => __('loops.governance_facilitators'),
        \App\Support\Loops\LoopRoleRegistry::MEMBER => __('loops.governance_members'),
    ];
@endphp

<div {{ $attributes->merge(['class' => 'space-y-5']) }}>
    @foreach($sections as $role => $heading)
        @php $bucket = $grouped->get($role, collect()); @endphp

        <section>
            <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                {{ $heading }}
                <span class="font-normal text-gray-400">({{ $bucket->count() }})</span>
            </h3>

            @if($bucket->isEmpty())
                <p class="text-xs text-gray-400">—</p>
            @else
                <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach($bucket as $member)
                        @php
                            $isLastOwner = $role === \App\Support\Loops\LoopRoleRegistry::OWNER && $ownerCount === 1;
                            $isSelf = $currentUserId !== null && $member->user_id === $currentUserId;
                        @endphp
                        <li class="flex flex-wrap items-center gap-x-3 gap-y-2 py-2.5">
                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-violet-100 text-xs font-bold text-violet-600 dark:bg-violet-500/20 dark:text-violet-300">
                                {{ mb_strtoupper(mb_substr($member->user?->publicDisplayName() ?? '?', 0, 1)) }}
                            </span>

                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-medium text-gray-800 dark:text-gray-100">
                                    {{ $member->user?->publicDisplayName() ?? '—' }}
                                </span>
                                <span class="flex flex-wrap items-center gap-x-2 text-[11px] text-gray-400">
                                    @if($creatorId && $member->user_id === $creatorId)
                                        <span>{{ __('loops.governance_creator') }}</span>
                                    @endif
                                    @if($roles->isLegacyAlias($member->role))
                                        <span class="rounded bg-gray-100 px-1 dark:bg-gray-700">{{ $member->role }}</span>
                                    @endif
                                    @if($isLastOwner)
                                        <span class="text-amber-600 dark:text-amber-400">{{ __('loops.governance_last_owner_short') }}</span>
                                    @endif
                                </span>
                            </span>

                            {{-- Actions, named. Nothing is offered that the
                                 invariant would refuse anyway. --}}
                            <span class="flex flex-wrap items-center gap-1.5">
                                @if($role !== \App\Support\Loops\LoopRoleRegistry::OWNER && $canManageOwners)
                                    <x-loops.governance-action
                                        :action="$roleRoute($member)"
                                        role="owner"
                                        :label="__('loops.governance_promote_owner')" />
                                @endif

                                @if($role === \App\Support\Loops\LoopRoleRegistry::MEMBER && $canManageFacilitators)
                                    <x-loops.governance-action
                                        :action="$roleRoute($member)"
                                        role="facilitator"
                                        :label="__('loops.governance_promote_facilitator')" />
                                @endif

                                @if($role === \App\Support\Loops\LoopRoleRegistry::OWNER && $canManageOwners && ! $isLastOwner)
                                    <x-loops.governance-action
                                        :action="$roleRoute($member)"
                                        role="facilitator"
                                        :label="__('loops.governance_demote_facilitator')"
                                        :confirm="$isSelf ? __('loops.governance_self_demote_confirm') : null" />
                                    <x-loops.governance-action
                                        :action="$roleRoute($member)"
                                        role="member"
                                        :label="__('loops.governance_demote_member')"
                                        :confirm="$isSelf ? __('loops.governance_self_demote_confirm') : null" />
                                @endif

                                @if($role === \App\Support\Loops\LoopRoleRegistry::FACILITATOR && $canManageFacilitators)
                                    <x-loops.governance-action
                                        :action="$roleRoute($member)"
                                        role="member"
                                        :label="__('loops.governance_demote_member')" />
                                @endif

                                @if($canRemove && $removeRoute && ! $isLastOwner)
                                    <form method="POST" action="{{ $removeRoute($member) }}"
                                          onsubmit="return confirm('{{ __('loops.governance_remove') }} ?')">
                                        @csrf @method('DELETE')
                                        <button type="submit"
                                                class="min-h-[44px] rounded-lg px-2 text-xs font-medium text-gray-500 transition hover:bg-red-50 hover:text-red-600 dark:text-gray-400 dark:hover:bg-red-900/20 dark:hover:text-red-400">
                                            {{ __('loops.governance_remove') }}
                                        </button>
                                    </form>
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endforeach

    {{-- Said once for the whole roster, not repeated on every row. --}}
    @if($ownerCount === 1)
        <p class="rounded-xl bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-900/20 dark:text-amber-300">
            {{ __('loops.governance_last_owner') }}
        </p>
    @endif
</div>
