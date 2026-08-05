{{--
    Card « Membres » du workspace.

    Etait ecrite en clair dans la chaine `@if ($card['key'] === ...)` de
    loops/show.blade.php. Le registre des Cards ne peut etre l'unique
    declaration que si le rendu se lit lui aussi depuis lui : cette Card
    designe donc ce partiel comme les trois autres designent un composant
    Livewire.

    Le balisage n'a pas change — seulement l'endroit ou il vit.
--}}
<div class="space-y-4">
    <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-800/60">
        <p class="text-[11px] font-semibold uppercase tracking-wide text-violet-600 dark:text-violet-300">{{ __($card['label_key']) }}</p>
        <p class="mt-1 text-sm leading-6 text-gray-600 dark:text-gray-300">{{ __('loops.members_count', ['count' => $loopMembers->count()]) }}</p>
    </div>

    @if(($canManageJoinRequests ?? false) && $pendingJoinRequests->isNotEmpty())
        <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-800/60 dark:bg-amber-900/10">
            <p class="mb-3 flex items-center gap-2 text-[11px] font-semibold uppercase tracking-wide text-amber-700 dark:text-amber-300">
                {{ __('loops.join_requests_title') }}
                <span class="inline-flex h-4 min-w-4 items-center justify-center rounded-full bg-amber-500 px-1 text-[10px] font-bold text-white">{{ $pendingJoinRequests->count() }}</span>
            </p>
            <ul class="space-y-2">
                @foreach($pendingJoinRequests as $joinRequest)
                    @php $rUser = $joinRequest->user; @endphp
                    <li class="rounded-xl border border-amber-200 bg-white p-3 dark:border-amber-800/50 dark:bg-gray-900">
                        <div class="flex items-center gap-2">
                            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-amber-100 text-xs font-bold text-amber-700 dark:bg-amber-500/20 dark:text-amber-300">{{ mb_strtoupper(mb_substr($rUser?->publicDisplayName() ?? '?', 0, 1)) }}</span>
                            <span class="min-w-0 flex-1 truncate text-sm font-semibold text-gray-800 dark:text-gray-100">{{ $rUser?->publicDisplayName() ?? '—' }}</span>
                            <span class="shrink-0 text-[11px] text-gray-400 dark:text-gray-500">{{ $joinRequest->created_at->diffForHumans() }}</span>
                        </div>
                        <p class="mt-1.5 text-xs leading-5 text-gray-600 dark:text-gray-300">{{ $joinRequest->message ?: __('loops.join_requests_no_message') }}</p>
                        <div class="mt-2.5 flex gap-2">
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

    @if($loopMembers->isEmpty())
        <div class="rounded-2xl border border-dashed border-gray-300 bg-white p-5 text-center text-sm text-gray-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-400">
            {{ __($card['empty_title_key']) }}
        </div>
    @else
        {{-- Governance roster: owners, facilitators, members,
             with the same named actions as both admin screens.
             Availability comes from the resolved permissions. --}}
        <x-loops.governance-roster
            :members="$loopMembers"
            :role-route="fn($m) => route('loops.members.role', $m)"
            :remove-route="null"
            :can-manage-owners="$governance['owners'] ?? false"
            :can-manage-facilitators="$governance['facilitators'] ?? false"
            :can-remove="false"
            :creator-id="$currentLoop->created_by"
            :current-user-id="auth()->id()" />
    @endif

    <div class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
        <p class="flex items-center gap-2 text-sm font-semibold text-gray-800 dark:text-gray-100">
            <svg class="h-4 w-4 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75"/></svg>
            {{ __('loops.members_invite_title') }}
        </p>
        {{-- Targeted at THIS Loop (TASK-1077): the generic referral form that used
             to live here mailed a signup link that joined no Loop at all. --}}
        <form method="POST" action="{{ $loopInvitationAction }}" class="mt-3 space-y-2">
            @csrf
            <input type="email" name="email" required placeholder="{{ __('loops.members_invite_email_placeholder') }}"
                   class="w-full rounded-xl border-gray-300 bg-white text-sm text-gray-900 focus:border-violet-500 focus:ring-violet-500 dark:border-gray-600 dark:bg-gray-950 dark:text-gray-100">
            <input type="text" name="name" maxlength="255" placeholder="{{ __('loops.members_invite_name_placeholder') }}"
                   class="w-full rounded-xl border-gray-300 bg-white text-sm text-gray-900 focus:border-violet-500 focus:ring-violet-500 dark:border-gray-600 dark:bg-gray-950 dark:text-gray-100">
            <button type="submit" class="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-violet-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-violet-700">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5"/></svg>
                {{ __('loops.members_invite_submit') }}
            </button>
        </form>

        <x-loops.invitation-list :invitations="$loopInvitations ?? collect()" class="mt-4 border-t border-gray-100 pt-3 dark:border-gray-700" />
    </div>
</div>
