{{--
    La Card Membres.

    Ce que la Card montre, c'est la liste des personnes — rien d'autre. Ajouter
    quelqu'un et inviter par courriel sont des gestes ponctuels : ils se
    deroulent dans une fenetre, et la Card retrouve toute sa hauteur pour ce
    qu'on vient y lire.

    L'etat des fenetres est tenu par le serveur. C'est ce qui garantit qu'a la
    fermeture le trombinoscope est re-rendu dans la meme reponse, au lieu de
    laisser a l'ecran la liste d'avant l'ajout.
--}}
@php
    // Alias obligatoire : Blade injecte son propre $loop dans @foreach et, les
    // variables PHP n'etant pas a portee de bloc, il reste ecrase ensuite. La
    // propriete du composant serait lue comme l'objet d'iteration.
    $currentLoop = $loop;

    $tile = 'rounded-2xl border border-[var(--bp-border)] bg-[var(--bp-panel)]';
    $quiet = 'inline-flex items-center gap-1.5 rounded-xl border border-[var(--bp-border)] bg-[var(--bp-panel)] px-2.5 py-1.5 text-xs font-semibold text-[var(--bp-muted)] transition hover:text-[var(--bp-text)]';
    $segBase = 'inline-flex items-center gap-1.5 rounded-xl px-3 py-1.5 text-xs font-semibold transition';
    $segOn = 'bg-[var(--bp-primary)] text-white shadow-sm';
    $segOff = 'text-[var(--bp-muted)] hover:bg-[var(--bp-surface)] hover:text-[var(--bp-text)]';
    $addedIds = collect($justAdded)->pluck('id')->all();

    // Seules les invitations par courriel sont suivies : rejoindre depuis
    // l'Organization est immediat, il n'y a rien a accepter.
    $emailInvitations = $invitations->where('invitation_type', \App\Models\LoopInvitation::TYPE_EXTERNAL);
    $pendingEmail = $emailInvitations->where('status', \App\Models\LoopInvitation::STATUS_PENDING)->count();
@endphp

<div class="space-y-3">

    {{-- ── Segments, invitations et lien ──────────────────────────────── --}}
    <div class="flex flex-wrap items-center gap-2">
        <div class="flex items-center gap-1 rounded-2xl border border-[var(--bp-border)] bg-[var(--bp-panel)] p-1">
            <button type="button" wire:click="selectSegment('all')"
                    class="{{ $segBase }} {{ $segment === 'all' ? $segOn : $segOff }}">
                {{ __('loops.members_segment_all') }}
            </button>
            <button type="button" wire:click="selectSegment('members')"
                    class="{{ $segBase }} {{ $segment === 'members' ? $segOn : $segOff }}">
                {{ __('loops.governance_members') }}
                <span class="tabular-nums opacity-80">{{ $segmentCounts['members'] }}</span>
            </button>
            <button type="button" wire:click="selectSegment('facilitators')"
                    class="{{ $segBase }} {{ $segment === 'facilitators' ? $segOn : $segOff }}">
                {{ __('loops.governance_facilitators') }}
                <span class="tabular-nums opacity-80">{{ $segmentCounts['facilitators'] }}</span>
            </button>
        </div>

        <div class="ms-auto flex items-center gap-2">
            @if($manageable)
                <button type="button" wire:click="openInvitations" class="{{ $quiet }}">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75"/></svg>
                    {{ __('loops.members_tab_invitations') }}
                    @if($pendingEmail > 0)
                        <span class="inline-flex h-4 min-w-4 items-center justify-center rounded-full bg-amber-500 px-1 text-[10px] font-bold tabular-nums text-white">{{ $pendingEmail }}</span>
                    @endif
                </button>
            @endif

            {{-- Partager la Boucle sans passer par la barre d'adresse. --}}
            <div x-data="{ copied: false }">
                <button type="button"
                        x-on:click="navigator.clipboard.writeText(@js($shareUrl)).then(() => { copied = true; setTimeout(() => copied = false, 2000) })"
                        class="{{ $quiet }}">
                    <svg x-show="! copied" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244"/></svg>
                    <svg x-show="copied" x-cloak class="h-3.5 w-3.5 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                    <span x-text="copied ? @js(__('loops.members_link_copied')) : @js(__('loops.members_copy_link'))">{{ __('loops.members_copy_link') }}</span>
                </button>
            </div>
        </div>
    </div>

    {{-- ── Une demande a rejoindre appelle une decision : elle reste sous
         les yeux, elle ne se range pas dans une fenetre. ──────────────── --}}
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

    {{-- ── Ce qui vient de se passer ──────────────────────────────────── --}}
    @if($justAdded !== [])
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

    @if($noticeMessage)
        <p class="rounded-2xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-800 dark:border-emerald-800/60 dark:bg-emerald-900/20 dark:text-emerald-300" role="status">{{ $noticeMessage }}</p>
    @endif

    @if($errorMessage)
        <p class="rounded-2xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-800/60 dark:bg-amber-900/20 dark:text-amber-300" role="alert">{{ $errorMessage }}</p>
    @endif

    {{-- ── Le trombinoscope ───────────────────────────────────────────── --}}
    <div class="{{ $tile }} p-3">
        @if($manageable)
            <button type="button" wire:click="openAdd"
                    class="mb-2 inline-flex w-full items-center justify-center gap-2 rounded-xl border border-dashed border-[var(--bp-primary)]/45 bg-[var(--bp-primary)]/5 px-4 py-2 text-xs font-semibold text-[var(--bp-primary-deep)] transition hover:bg-[var(--bp-primary)]/10 dark:text-white">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                {{ __('loops.members_add_title') }}
            </button>
        @endif

        @if($shownMembers->isEmpty())
            <p class="py-4 text-center text-sm text-[var(--bp-muted)]">
                {{ $members->isEmpty() ? __('loops.cards.members.empty_title') : __('loops.members_segment_empty') }}
            </p>
        @else
            <x-loops.governance-roster
                :members="$shownMembers"
                :role-route="fn($m) => route('loops.members.role', $m)"
                :remove-route="null"
                :can-manage-owners="$governance['owners']"
                :can-manage-facilitators="$governance['facilitators']"
                :can-remove="false"
                :creator-id="$currentLoop->created_by"
                :current-user-id="auth()->id()"
                :highlight-ids="$addedIds"
                :sections="false" />
        @endif
    </div>

    {{-- Ajouter quelqu'un : en fenetre dans le panneau, a plat sur l'ecran qui
         suit la creation d'une Boucle. --}}
    @if($flat && $manageable)
        <div class="{{ $tile }} p-4">
            <p class="mb-3 text-sm font-semibold text-[var(--bp-text)]">{{ __('loops.members_add_title') }}</p>
            @include('livewire.partials.members-add')
        </div>
    @elseif($showAddModal)
        <x-loops.modal :title="__('loops.members_add_title')" close="closeAdd">
            @include('livewire.partials.members-add')
        </x-loops.modal>
    @endif

    @if($flat && $manageable)
        <div class="{{ $tile }} p-4">
            <p class="mb-3 text-sm font-semibold text-[var(--bp-text)]">{{ __('loops.members_tab_invitations') }}</p>
            @include('livewire.partials.members-invitations')
        </div>
    @elseif($showInvitationsModal)
        <x-loops.modal :title="__('loops.members_tab_invitations')" close="closeInvitations">
            @include('livewire.partials.members-invitations')
        </x-loops.modal>
    @endif
</div>
