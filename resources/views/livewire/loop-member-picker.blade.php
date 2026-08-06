{{--
    Deux listes qui se repondent : qui est dans la Boucle, et qui de
    l'Organization peut y entrer. Ajouter fait passer quelqu'un de la seconde a
    la premiere sans quitter la page — c'est tout l'interet.
--}}
<div class="space-y-3">

    @if($flash)
        <p class="rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-700 dark:border-emerald-800/60 dark:bg-emerald-900/20 dark:text-emerald-300"
           wire:key="flash">
            {{ $flash }}
        </p>
    @endif

    {{-- ── Les membres de la Boucle ───────────────────────────────────── --}}
    @if($showMembers)
    <div class="rounded-2xl border border-[var(--bp-border)] bg-[var(--bp-panel)] p-4">
        <p class="flex items-center gap-2 text-sm font-semibold text-[var(--bp-text)]">
            {{ __('loops.members_present_title') }}
            <span class="rounded-full bg-[var(--bp-primary)]/12 px-2 py-0.5 text-[11px] font-bold tabular-nums text-[var(--bp-primary-deep)] dark:bg-[var(--bp-primary)]/25 dark:text-white">
                {{ $members->count() }}
            </span>
        </p>

        @if($members->isEmpty())
            <p class="mt-2 text-xs text-[var(--bp-muted)]">{{ __('loops.members_present_empty') }}</p>
        @else
            <ul class="mt-3 flex flex-wrap gap-2">
                @foreach($members as $member)
                    <li wire:key="member-{{ $member->id }}"
                        class="inline-flex items-center gap-2 rounded-full border border-[var(--bp-border)] bg-[var(--bp-surface)] py-1 pl-1 pr-3">
                        <x-user-avatar :user="$member->user" size="xs" />
                        <span class="max-w-[12rem] truncate text-xs font-medium text-[var(--bp-text)]">
                            {{ $member->user?->publicDisplayName() ?? '—' }}
                        </span>
                        @php
                            // Le role canonique passe par le registre : les
                            // alias hérités ne doivent pas fuir dans un badge.
                            $canonical = app(\App\Support\Loops\LoopRoleRegistry::class)->canonical($member->role);
                        @endphp
                        @if($canonical !== \App\Support\Loops\LoopRoleRegistry::MEMBER)
                            <span class="rounded-full bg-[var(--bp-primary)]/12 px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wide text-[var(--bp-primary-deep)] dark:bg-[var(--bp-primary)]/25 dark:text-white">
                                {{ $canonical === \App\Support\Loops\LoopRoleRegistry::OWNER
                                    ? __('loops.members_role_owner')
                                    : __('loops.members_role_facilitator') }}
                            </span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
    @endif

    {{-- ── Ajouter depuis l'Organization ──────────────────────────────── --}}
    @if($manageable)
        <div class="rounded-2xl border border-[var(--bp-border)] bg-[var(--bp-panel)] p-4">
            <button type="button" wire:click="toggleOpen"
                    class="flex w-full items-center justify-between gap-2 text-left">
                <span class="text-sm font-semibold text-[var(--bp-text)]">{{ __('loops.members_add_from_org_title') }}</span>
                <svg class="h-4 w-4 shrink-0 text-[var(--bp-muted)] transition {{ $open ? 'rotate-180' : '' }}"
                     fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/>
                </svg>
            </button>

            @if($open)
                <p class="mt-1 text-xs text-[var(--bp-muted)]">{{ __('loops.members_add_from_org_help') }}</p>

                @if($candidates->isEmpty() && trim($search) === '')
                    <p class="mt-3 text-xs text-[var(--bp-muted)]">{{ __('loops.invite_no_candidate') }}</p>
                @else
                    <input type="search" wire:model.live.debounce.200ms="search"
                           placeholder="{{ __('loops.invite_search_placeholder') }}"
                           class="mt-3 w-full rounded-xl border-[var(--bp-border)] bg-[var(--bp-surface)] px-3 py-2 text-sm text-[var(--bp-text)] focus:border-[var(--bp-primary)] focus:ring-[var(--bp-primary)]">

                    @if($candidates->isEmpty())
                        <p class="mt-3 text-xs text-[var(--bp-muted)]">{{ __('loops.invite_search_no_result') }}</p>
                    @else
                        <div class="mt-3 max-h-64 space-y-1 overflow-y-auto rounded-xl border border-[var(--bp-border)] p-2">
                            @foreach($candidates as $candidate)
                                <label wire:key="candidate-{{ $candidate->id }}"
                                       class="flex cursor-pointer items-center gap-3 rounded-lg px-2 py-1.5 transition hover:bg-[var(--bp-surface)] has-[:checked]:bg-[var(--bp-primary)]/10">
                                    <input type="checkbox" wire:model.live="selected" value="{{ $candidate->id }}"
                                           class="rounded border-[var(--bp-border)] text-[var(--bp-primary)] focus:ring-[var(--bp-primary)]">
                                    <x-user-avatar :user="$candidate" size="sm" />
                                    <span class="min-w-0 flex-1 truncate text-sm text-[var(--bp-text)]">{{ $candidate->publicDisplayName() }}</span>
                                </label>
                            @endforeach
                        </div>
                    @endif

                    <button type="button" wire:click="add" wire:loading.attr="disabled"
                            @disabled($selected === [])
                            class="mt-3 inline-flex w-full items-center justify-center gap-2 rounded-xl bg-[var(--bp-primary)] px-4 py-2.5 text-sm font-semibold text-white transition hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-40 sm:w-auto">
                        {{ __('loops.invite_add_submit') }}@if($selected !== []) ({{ count($selected) }}) @endif
                    </button>
                @endif
            @endif
        </div>
    @endif
</div>
