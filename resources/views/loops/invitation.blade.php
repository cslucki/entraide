{{--
    Public landing page for a Loop invitation. Read-only by construction: every
    action below is a POST, because accepting an invitation mutates state and
    must never ride on a GET navigation.
--}}
<x-guest-layout>
    <div class="mx-auto w-full max-w-xl px-4 py-10">
        @if(session('error'))
            <div class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-300">
                {{ session('error') }}
            </div>
        @endif

        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
            @if($loop)
                <x-loops.cover :loop="$loop" ratio="21 / 9" rounded="" />
            @endif

            <div class="p-6 sm:p-8">
                <p class="text-xs font-semibold uppercase tracking-wide text-indigo-600 dark:text-indigo-400">
                    {{ $organization?->name }}
                </p>

                <h1 class="mt-1 text-2xl font-bold text-gray-900 dark:text-gray-100">
                    {{ $loop?->name ?? __('loops.invitation_unknown_loop') }}
                </h1>

                @if(filled($loop?->tagline))
                    <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ $loop->tagline }}</p>
                @endif

                <x-loops.domain-badges :loop="$loop" class="mt-3" />

                <dl class="mt-5 space-y-2 border-t border-gray-100 pt-5 text-sm dark:border-gray-700">
                    <div class="flex items-baseline justify-between gap-4">
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('loops.invitation_invited_by') }}</dt>
                        <dd class="font-medium text-gray-800 dark:text-gray-200">{{ $sender?->publicDisplayName() ?? '—' }}</dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-4">
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('loops.invitation_sent_to') }}</dt>
                        <dd class="font-medium text-gray-800 dark:text-gray-200">{{ $invitation->recipient_email }}</dd>
                    </div>
                    @if($invitation->expires_at)
                        <div class="flex items-baseline justify-between gap-4">
                            <dt class="text-gray-500 dark:text-gray-400">{{ __('loops.invitation_expires_on') }}</dt>
                            <dd class="font-medium text-gray-800 dark:text-gray-200">{{ $invitation->expires_at->isoFormat('LL') }}</dd>
                        </div>
                    @endif
                </dl>

                @if(filled($invitation->message))
                    <blockquote class="mt-5 rounded-xl border-l-4 border-indigo-400 bg-indigo-50/60 px-4 py-3 text-sm text-gray-700 dark:bg-indigo-900/20 dark:text-gray-200">
                        {{ $invitation->message }}
                    </blockquote>
                @endif

                <div class="mt-6">
                    @if($isRevoked)
                        <p class="rounded-xl bg-gray-50 px-4 py-3 text-sm text-gray-600 dark:bg-gray-900/40 dark:text-gray-300">
                            {{ __('loops.invitation_revoked_notice') }}
                        </p>
                    @elseif($isExpired)
                        <p class="rounded-xl bg-gray-50 px-4 py-3 text-sm text-gray-600 dark:bg-gray-900/40 dark:text-gray-300">
                            {{ __('loops.invitation_expired') }}
                        </p>
                    @elseif($isAccepted && ! (auth()->check() && $invitation->accepted_by_user_id === auth()->id()))
                        <p class="rounded-xl bg-gray-50 px-4 py-3 text-sm text-gray-600 dark:bg-gray-900/40 dark:text-gray-300">
                            {{ __('loops.invitation_taken') }}
                        </p>
                    @elseif(auth()->check())
                        @if($emailMatches)
                            <form method="POST" action="{{ route('loop-invitations.accept', $invitation->token) }}">
                                @csrf
                                <button type="submit"
                                        class="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-indigo-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-indigo-700">
                                    {{ __('loops.invitation_accept_cta') }}
                                </button>
                            </form>
                        @else
                            {{-- Signed in as somebody else: a token is bound to one address. --}}
                            <p class="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:bg-amber-900/20 dark:text-amber-300">
                                {{ __('loops.invitation_email_mismatch') }}
                            </p>
                        @endif
                    @else
                        <div class="flex flex-col gap-3 sm:flex-row">
                            {{-- POST, not a link: this parks the token in the session. --}}
                            <form method="POST" action="{{ route('loop-invitations.prepare', $invitation->token) }}" class="flex-1">
                                @csrf
                                <input type="hidden" name="intent" value="login">
                                <button type="submit"
                                        class="inline-flex w-full items-center justify-center rounded-xl border border-gray-300 px-5 py-3 text-sm font-semibold text-gray-700 transition hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                                    {{ __('loops.invitation_login_cta') }}
                                </button>
                            </form>
                            <form method="POST" action="{{ route('loop-invitations.prepare', $invitation->token) }}" class="flex-1">
                                @csrf
                                <input type="hidden" name="intent" value="register">
                                <button type="submit"
                                        class="inline-flex w-full items-center justify-center rounded-xl bg-indigo-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-indigo-700">
                                    {{ __('loops.invitation_register_cta') }}
                                </button>
                            </form>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-guest-layout>
