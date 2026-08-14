{{--
    Shared list of Loop e-mail invitations, used by the post-creation screen, the
    Members Card and the Organization tracking page so the three never drift.

    The token is never rendered — not as text, not as an attribute, not as a
    copyable link. It only ever travels by e-mail.
--}}
@props(['invitations', 'showLoop' => false])

@if($invitations->isNotEmpty())
    <div {{ $attributes }}>
        <ul class="divide-y divide-gray-100 dark:divide-gray-700">
            @foreach($invitations as $invitation)
                <li class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2.5">
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium text-gray-800 dark:text-gray-200">
                            {{ $invitation->recipient_name ?: $invitation->recipient_email }}
                        </p>
                        <p class="truncate text-xs text-gray-500 dark:text-gray-400">
                            {{ $invitation->recipient_email }}
                            @if($showLoop && $invitation->loop)
                                · {{ $invitation->loop->name }}
                            @endif
                        </p>
                    </div>

                    <x-loops.invitation-status :invitation="$invitation" />

                    @if($invitation->isPending())
                        <form method="POST" action="{{ route('loop-invitations.revoke', $invitation) }}">
                            @csrf
                            <button type="submit"
                                    class="rounded-lg px-2 py-1 text-xs font-medium text-gray-500 transition hover:bg-red-50 hover:text-red-600 dark:text-gray-400 dark:hover:bg-red-900/20 dark:hover:text-red-400">
                                {{ __('loops.invitations_action_revoke') }}
                            </button>
                        </form>
                    @endif
                </li>
            @endforeach
        </ul>
    </div>
@endif
