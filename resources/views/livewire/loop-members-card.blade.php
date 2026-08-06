{{--
    La Card Membres.

    Deux onglets : les personnes, et ce qui a ete envoye. Les chiffres sont
    remontes dans la barre d'onglets — ils s'y lisent d'un coup d'oeil sans
    couter la rangee de tuiles qu'ils occupaient.

    L'ajout depuis l'Organization est pose en premier et deplie d'entree : c'est
    le geste courant. Un seul champ le sert ; s'il recoit une adresse qui ne
    designe personne d'ici, il propose l'invitation plutot que de renvoyer un
    resultat vide.
--}}
@php
    // Alias obligatoire : Blade injecte son propre $loop dans @foreach et, les
    // variables PHP n'etant pas a portee de bloc, il reste ecrase ensuite. La
    // propriete du composant serait lue comme l'objet d'iteration.
    $currentLoop = $loop;

    $roleRegistry = app(\App\Support\Loops\LoopRoleRegistry::class);
    $byRole = $members->groupBy(fn ($m) => $roleRegistry->canonical($m->role));
    $ownersCount = $byRole->get(\App\Support\Loops\LoopRoleRegistry::OWNER, collect())->count();
    $facilitatorsCount = $byRole->get(\App\Support\Loops\LoopRoleRegistry::FACILITATOR, collect())->count();
    $pendingCount = $joinRequests->count()
        + $invitations->where('status', \App\Models\LoopInvitation::STATUS_PENDING)->count();

    $tile = 'rounded-2xl border border-[var(--bp-border)] bg-[var(--bp-panel)]';
    $tabBase = 'inline-flex items-center gap-1.5 rounded-xl px-3 py-1.5 text-xs font-semibold transition';
    $tabOn = 'bg-[var(--bp-primary)] text-white shadow-sm';
    $tabOff = 'text-[var(--bp-muted)] hover:bg-[var(--bp-surface)] hover:text-[var(--bp-text)]';
    $chevron = 'h-4 w-4 shrink-0 text-[var(--bp-muted)] transition';
    $addedIds = collect($justAdded)->pluck('id')->all();
@endphp

<div class="space-y-3">

    {{-- ── Onglets, compteurs et lien ─────────────────────────────────── --}}
    <div class="flex flex-wrap items-center gap-2">
        <div class="flex items-center gap-1 rounded-2xl border border-[var(--bp-border)] bg-[var(--bp-panel)] p-1">
            <button type="button" wire:click="selectTab('members')"
                    class="{{ $tabBase }} {{ $tab === 'members' ? $tabOn : $tabOff }}">
                {{ __('loops.governance_members') }}
                <span class="tabular-nums opacity-80">{{ $members->count() }}</span>
            </button>
            <button type="button" wire:click="selectTab('invitations')"
                    class="{{ $tabBase }} {{ $tab === 'invitations' ? $tabOn : $tabOff }}">
                {{ __('loops.members_tab_invitations') }}
                @if($pendingCount > 0)
                    <span class="inline-flex h-4 min-w-4 items-center justify-center rounded-full bg-amber-500 px-1 text-[10px] font-bold tabular-nums text-white">{{ $pendingCount }}</span>
                @endif
            </button>
        </div>

        <p class="hidden text-[11px] text-[var(--bp-muted)] sm:block">
            {{ trans_choice('loops.members_owners_count', $ownersCount, ['count' => $ownersCount]) }}
            ·
            {{ trans_choice('loops.members_facilitators_count', $facilitatorsCount, ['count' => $facilitatorsCount]) }}
        </p>

        {{-- Partager la Boucle sans passer par la barre d'adresse. --}}
        <div class="ms-auto" x-data="{ copied: false }">
            <button type="button"
                    x-on:click="navigator.clipboard.writeText(@js($shareUrl)).then(() => { copied = true; setTimeout(() => copied = false, 2000) })"
                    class="inline-flex items-center gap-1.5 rounded-xl border border-[var(--bp-border)] bg-[var(--bp-panel)] px-2.5 py-1.5 text-xs font-semibold text-[var(--bp-muted)] transition hover:text-[var(--bp-text)]">
                <svg x-show="! copied" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244"/></svg>
                <svg x-show="copied" x-cloak class="h-3.5 w-3.5 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                <span x-text="copied ? @js(__('loops.members_link_copied')) : @js(__('loops.members_copy_link'))">{{ __('loops.members_copy_link') }}</span>
            </button>
        </div>
    </div>

    @if($tab === 'members')

        {{-- ── Ajouter depuis l'Organization ──────────────────────────── --}}
        @if($manageable)
            <div class="{{ $tile }} overflow-hidden">
                <button type="button" wire:click="togglePicker"
                        class="flex w-full items-center justify-between gap-2 px-4 py-3 text-left transition hover:bg-[var(--bp-surface)]">
                    <span class="flex items-center gap-2 text-sm font-semibold text-[var(--bp-text)]">
                        <svg class="h-4 w-4 text-[var(--bp-primary)]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7.5v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0ZM4 19.235v-.11a6.375 6.375 0 0 1 12.75 0v.109A12.318 12.318 0 0 1 10.374 21c-2.331 0-4.512-.645-6.374-1.766Z"/></svg>
                        {{ __('loops.members_add_from_org_title') }}
                    </span>
                    <svg class="{{ $chevron }} {{ $openPicker ? 'rotate-180' : '' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/>
                    </svg>
                </button>

                @if($openPicker)
                    <div class="border-t border-[var(--bp-border)] p-4">
                        {{-- Le champ unique : un nom, ou une adresse. --}}
                        <input type="search" wire:model.live.debounce.250ms="search"
                               placeholder="{{ __('loops.members_search_placeholder') }}"
                               class="w-full rounded-xl border-[var(--bp-border)] bg-[var(--bp-surface)] px-3 py-2 text-sm text-[var(--bp-text)] focus:border-[var(--bp-primary)] focus:ring-[var(--bp-primary)]">

                        @if($offerEmailInvite)
                            {{-- Personne d'ici ne porte cette adresse : on bascule
                                 sur l'autre geste au lieu d'un resultat vide. --}}
                            <button type="button" wire:click="inviteTyped"
                                    class="mt-3 flex w-full items-center gap-2.5 rounded-xl border border-dashed border-[var(--bp-primary)]/50 bg-[var(--bp-primary)]/5 px-3 py-2.5 text-left transition hover:bg-[var(--bp-primary)]/10">
                                <svg class="h-4 w-4 shrink-0 text-[var(--bp-primary)]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75"/></svg>
                                <span class="min-w-0 flex-1 text-xs text-[var(--bp-text)]">
                                    {{ __('loops.members_invite_typed', ['email' => trim($search)]) }}
                                </span>
                            </button>
                        @elseif($candidates->isEmpty())
                            <p class="mt-3 text-xs text-[var(--bp-muted)]">
                                {{ trim($search) === '' ? __('loops.invite_no_candidate') : __('loops.invite_search_no_result') }}
                            </p>
                        @else
                            <div class="mt-3 max-h-56 space-y-0.5 overflow-y-auto">
                                @foreach($candidates as $candidate)
                                    <label wire:key="candidate-{{ $candidate->id }}"
                                           class="flex cursor-pointer items-center gap-2.5 rounded-xl px-2 py-1.5 transition hover:bg-[var(--bp-surface)] has-[:checked]:bg-[var(--bp-primary)]/10">
                                        <input type="checkbox" wire:model.live="selected" value="{{ $candidate->id }}"
                                               class="rounded border-[var(--bp-border)] text-[var(--bp-primary)] focus:ring-[var(--bp-primary)]">
                                        <x-user-avatar :user="$candidate" size="sm" />
                                        <span class="min-w-0 flex-1 truncate text-sm text-[var(--bp-text)]">{{ $candidate->publicDisplayName() }}</span>
                                    </label>
                                @endforeach
                            </div>

                            <button type="button" wire:click="add" wire:loading.attr="disabled"
                                    @disabled($selected === [])
                                    class="mt-3 inline-flex w-full items-center justify-center gap-2 rounded-xl bg-[var(--bp-primary)] px-4 py-2.5 text-sm font-semibold text-white transition hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-40">
                                <span wire:loading.remove wire:target="add">
                                    {{ __('loops.invite_add_submit') }}@if($selected !== []) ({{ count($selected) }})@endif
                                </span>
                                <span wire:loading wire:target="add">{{ __('loops.members_adding') }}</span>
                            </button>
                        @endif
                    </div>
                @endif
            </div>
        @endif

        {{-- ── Inviter par courriel ───────────────────────────────────── --}}
        @if($emailInvitable)
            <div class="{{ $tile }} overflow-hidden">
                <button type="button" wire:click="toggleEmail"
                        class="flex w-full items-center justify-between gap-2 px-4 py-3 text-left transition hover:bg-[var(--bp-surface)]">
                    <span class="flex items-center gap-2 text-sm font-semibold text-[var(--bp-text)]">
                        <svg class="h-4 w-4 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75"/></svg>
                        {{ __('loops.members_invite_title') }}
                    </span>
                    <svg class="{{ $chevron }} {{ $openEmail ? 'rotate-180' : '' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/>
                    </svg>
                </button>

                @if($openEmail)
                    <div class="space-y-2 border-t border-[var(--bp-border)] p-4">
                        <p class="text-xs text-[var(--bp-muted)]">{{ __('loops.members_invite_help') }}</p>

                        <input type="email" wire:model="inviteEmail" placeholder="{{ __('loops.members_invite_email_placeholder') }}"
                               class="w-full rounded-xl border-[var(--bp-border)] bg-[var(--bp-surface)] text-sm text-[var(--bp-text)] focus:border-[var(--bp-primary)] focus:ring-[var(--bp-primary)]">
                        @error('inviteEmail') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror

                        <input type="text" wire:model="inviteName" maxlength="255" placeholder="{{ __('loops.members_invite_name_placeholder') }}"
                               class="w-full rounded-xl border-[var(--bp-border)] bg-[var(--bp-surface)] text-sm text-[var(--bp-text)] focus:border-[var(--bp-primary)] focus:ring-[var(--bp-primary)]">
                        @error('inviteName') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror

                        <button type="button" wire:click="sendInvitation" wire:loading.attr="disabled"
                                class="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-[var(--bp-primary)] px-4 py-2.5 text-sm font-semibold text-white transition hover:opacity-90 disabled:opacity-50">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5"/></svg>
                            <span wire:loading.remove wire:target="sendInvitation">{{ __('loops.members_invite_submit') }}</span>
                            <span wire:loading wire:target="sendInvitation">{{ __('loops.members_sending') }}</span>
                        </button>
                    </div>
                @endif
            </div>
        @endif

        {{-- ── Ce qui vient de se passer ──────────────────────────────── --}}
        @if($justAdded !== [])
            {{-- Nommement, et avec les visages : « 1 personne ajoutee »
                 obligeait a aller verifier dans la liste laquelle. --}}
            <div class="flex items-center gap-2.5 rounded-2xl border border-emerald-200 bg-emerald-50 p-3 dark:border-emerald-800/60 dark:bg-emerald-900/20"
                 role="status" wire:key="just-added-{{ $justAdded[0]['id'] }}-{{ count($justAdded) }}">
                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-emerald-500 text-white">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                </span>
                <p class="min-w-0 flex-1 text-xs font-semibold leading-5 text-emerald-800 dark:text-emerald-300">
                    {{ trans_choice('loops.members_added_named', count($justAdded), [
                        'names' => collect($justAdded)->pluck('name')->join(', ', ' '.__('loops.members_added_and').' '),
                    ]) }}
                </p>
            </div>
        @endif

        @if($errorMessage)
            <p class="rounded-2xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-800/60 dark:bg-amber-900/20 dark:text-amber-300" role="alert">{{ $errorMessage }}</p>
        @endif

        {{-- ── Le trombinoscope ───────────────────────────────────────── --}}
        <div class="{{ $tile }} p-3">
            @if($members->isEmpty())
                <p class="py-4 text-center text-sm text-[var(--bp-muted)]">{{ __('loops.cards.members.empty_title') }}</p>
            @else
                <x-loops.governance-roster
                    :members="$members"
                    :role-route="fn($m) => route('loops.members.role', $m)"
                    :remove-route="null"
                    :can-manage-owners="$governance['owners']"
                    :can-manage-facilitators="$governance['facilitators']"
                    :can-remove="false"
                    :creator-id="$currentLoop->created_by"
                    :current-user-id="auth()->id()"
                    :highlight-ids="$addedIds" />
            @endif
        </div>

    @else

        {{-- ── Onglet « Invitations » ─────────────────────────────────── --}}
        @if($noticeMessage)
            <p class="rounded-2xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-800 dark:border-emerald-800/60 dark:bg-emerald-900/20 dark:text-emerald-300" role="status">{{ $noticeMessage }}</p>
        @endif

        {{-- Une demande a rejoindre appelle une decision : elle passe devant. --}}
        @if($joinRequests->isNotEmpty())
            <div class="rounded-2xl border border-amber-200 bg-amber-50 p-3 dark:border-amber-800/60 dark:bg-amber-900/15">
                <p class="mb-2 flex items-center gap-2 text-[11px] font-semibold uppercase tracking-wide text-amber-700 dark:text-amber-300">
                    {{ __('loops.join_requests_title') }}
                    <span class="inline-flex h-4 min-w-4 items-center justify-center rounded-full bg-amber-500 px-1 text-[10px] font-bold text-white">{{ $joinRequests->count() }}</span>
                </p>
                <ul class="space-y-2">
                    @foreach($joinRequests as $joinRequest)
                        <li wire:key="request-{{ $joinRequest->id }}" class="rounded-xl border border-amber-200 bg-white p-2.5 dark:border-amber-800/50 dark:bg-gray-900">
                            <div class="flex items-center gap-2">
                                <x-user-avatar :user="$joinRequest->user" size="sm" />
                                <span class="min-w-0 flex-1 truncate text-sm font-semibold text-gray-800 dark:text-gray-100">{{ $joinRequest->user?->publicDisplayName() ?? '—' }}</span>
                                <span class="shrink-0 text-[10px] text-gray-400 dark:text-gray-500">{{ $joinRequest->created_at->diffForHumans() }}</span>
                            </div>
                            @if($joinRequest->message)
                                <p class="mt-1.5 text-xs leading-5 text-gray-600 dark:text-gray-300">{{ $joinRequest->message }}</p>
                            @endif
                            <div class="mt-2 flex gap-2">
                                <form method="POST" action="{{ route('loop-join-requests.accept', $joinRequest) }}" class="flex-1">
                                    @csrf
                                    <button type="submit" class="w-full rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-emerald-700">
                                        {{ __('loops.join_requests_accept') }}
                                    </button>
                                </form>
                                <form method="POST" action="{{ route('loop-join-requests.reject', $joinRequest) }}" class="flex-1">
                                    @csrf
                                    <button type="submit" class="w-full rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-semibold text-gray-600 transition hover:bg-gray-100 dark:border-gray-600 dark:text-gray-300 dark:hover:bg-gray-800">
                                        {{ __('loops.join_requests_reject') }}
                                    </button>
                                </form>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="{{ $tile }} p-4">
            <p class="text-sm font-semibold text-[var(--bp-text)]">{{ __('loops.members_tab_invitations') }}</p>

            @if($invitations->isEmpty())
                {{-- Un onglet vide doit dire pourquoi, et ou aller ensuite. --}}
                <p class="mt-2 text-xs leading-5 text-[var(--bp-muted)]">
                    {{ __('loops.members_invitations_empty') }}
                </p>
                @if($emailInvitable)
                    <button type="button" wire:click="selectTab('members')"
                            class="mt-3 inline-flex items-center gap-1.5 rounded-xl border border-[var(--bp-border)] px-3 py-1.5 text-xs font-semibold text-[var(--bp-text)] transition hover:border-[var(--bp-primary)]">
                        {{ __('loops.members_invitations_empty_cta') }}
                    </button>
                @endif
            @else
                <x-loops.invitation-list :invitations="$invitations" class="mt-3" />
            @endif
        </div>

    @endif
</div>
