<x-app-layout>
    <x-slot name="title">{{ __('points.invite_peers_title') }}</x-slot>

    <x-page-container x-data="referralModal()">
        <div class="mb-6 hidden sm:block">
            <h1 class="mb-1 text-3xl font-bold text-gray-900 dark:text-gray-100">{{ __('points.invite_peers_title') }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('points.invite_peers_intro') }}</p>
        </div>

        <x-user-dashboard-nav class="mb-8" />

        @if($referralLink)
            <div id="invitations" class="mb-8 rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800">
                <h2 class="mb-2 font-semibold text-gray-900 dark:text-gray-100">{{ __('points.invitations') }}</h2>
                <p class="mb-3 text-sm text-gray-500 dark:text-gray-400">{{ __('points.invitation_help') }}</p>

                <div class="mb-4 flex flex-wrap gap-x-6 gap-y-1 text-sm text-gray-600 dark:text-gray-400">
                    <div><span class="font-semibold text-gray-900 dark:text-gray-100">{{ $sentReferralsCount }}</span> <span>{{ __('points.invitation_count') }}</span></div>
                    <div><span class="font-semibold text-gray-900 dark:text-gray-100">{{ $activatedReferralsCount }}</span> <span>{{ __('points.activation_count') }}</span></div>
                    <div><span class="font-semibold text-gray-900 dark:text-gray-100">{{ $referralPointsEarned }}</span> <span>{{ __('points.points_earned') }}</span></div>
                </div>

                <div class="flex flex-col gap-2 sm:flex-row" x-data="{ copied: false, link: @js($referralLink) }">
                    <div class="flex flex-1 gap-2">
                        <input type="text" readonly value="{{ $referralLink }}" data-referral-link-invitations class="min-w-0 flex-1 select-all rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 text-sm text-gray-700 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-300">
                        <button type="button" @click="
                            const input = $root.querySelector('[data-referral-link-invitations]');
                            if (navigator.clipboard && window.isSecureContext) {
                                navigator.clipboard.writeText(link);
                            } else if (input) {
                                input.select();
                                document.execCommand('copy');
                            }
                            copied = true;
                            setTimeout(() => copied = false, 2000);
                        " class="whitespace-nowrap rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-indigo-700">
                            <span x-show="!copied">{{ __('points.copy') }}</span>
                            <span x-show="copied">{{ __('points.copied') }}</span>
                        </button>
                    </div>
                    <div class="flex gap-2">
                        <a href="https://wa.me/?text={{ urlencode(__('points.whatsapp_message') . "\n" . $referralLink) }}" target="_blank" rel="noopener noreferrer" class="flex-1 whitespace-nowrap rounded-lg bg-gray-100 px-4 py-2 text-center text-sm font-medium text-gray-700 transition hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600 sm:flex-none">WhatsApp</a>
                        <button type="button" @click="openModal()" class="flex-1 whitespace-nowrap rounded-lg bg-indigo-600 px-4 py-2 text-center text-sm font-medium text-white transition hover:bg-indigo-700 sm:flex-none">{{ __('points.email') }}</button>
                    </div>
                </div>
            </div>
        @endif

        @if(session('success'))
            <div class="mb-6 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700 dark:border-green-800 dark:bg-green-900/20 dark:text-green-300">{{ session('success') }}</div>
        @endif
        @if(session('error'))
            <div class="mb-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-300">{{ session('error') }}</div>
        @endif

        <div class="grid gap-6 lg:grid-cols-2">
            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-700">
                    <h2 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('points.sent_invitations_history') }}</h2>
                </div>
                <div class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse($sentInvitations as $invitation)
                        <div class="flex items-start justify-between gap-3 px-5 py-4">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-gray-900 dark:text-gray-100">{{ __('points.sent_to', ['email' => $invitation->to_email]) }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ $invitation->created_at->format('d/m/Y H:i') }}</p>
                            </div>
                            <span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium {{ $invitation->status === 'sent' ? 'bg-green-100 text-green-700 dark:bg-green-900/50 dark:text-green-300' : 'bg-red-100 text-red-700 dark:bg-red-900/50 dark:text-red-300' }}">
                                {{ $invitation->status === 'sent' ? __('points.status_sent') : __('points.status_failed') }}
                            </span>
                        </div>
                    @empty
                        <div class="px-5 py-10 text-center text-sm text-gray-400">{{ __('points.no_sent_invitations') }}</div>
                    @endforelse
                </div>
                <div class="px-5 py-3">{{ $sentInvitations->links() }}</div>
            </div>

            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800">
                <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-700">
                    <h2 class="font-semibold text-gray-900 dark:text-gray-100">{{ __('points.joined_invitations_history') }}</h2>
                </div>
                <div class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse($joinedInvitations as $referral)
                        <div class="flex items-start justify-between gap-3 px-5 py-4">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-gray-900 dark:text-gray-100">{{ __('points.joined_member', ['name' => $referral->referred?->fullName ?? $referral->referred?->email ?? '...']) }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ $referral->created_at->format('d/m/Y H:i') }}</p>
                            </div>
                            <span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium {{ $referral->status === 'activated' ? 'bg-green-100 text-green-700 dark:bg-green-900/50 dark:text-green-300' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' }}">
                                {{ $referral->status === 'activated' ? __('points.status_activated') : __('points.status_pending') }}
                            </span>
                        </div>
                    @empty
                        <div class="px-5 py-10 text-center text-sm text-gray-400">{{ __('points.no_joined_invitations') }}</div>
                    @endforelse
                </div>
            </div>
        </div>

        @if($referralLink)
            <div x-show="modalOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center">
                <div class="fixed inset-0 bg-black/50" @click="modalOpen = false"></div>
                <div class="relative mx-4 max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-xl bg-white p-6 shadow-xl dark:bg-gray-800">
                    <h3 class="mb-4 text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('points.send_by_email') }}</h3>
                    <form method="POST" action="{{ $orgSlug ? route('organization.points.invitation.send', ['organization' => $orgSlug]) : route('points.invitation.send') }}" @submit="sending = true">
                        @csrf
                        <div class="space-y-4">
                            <div>
                                <label for="recipient_email" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('points.recipient_email') }}</label>
                                <input type="email" id="recipient_email" name="recipient_email" x-model="email" required class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                                @error('recipient_email')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label for="recipient_name" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('points.recipient_name') }}</label>
                                <input type="text" id="recipient_name" name="recipient_name" x-model="name" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100">
                            </div>
                            <div>
                                <label for="message" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('points.invitation_message') }}</label>
                                <textarea id="message" name="message" x-model="message" rows="4" maxlength="2000" placeholder="{{ __('points.invitation_message_placeholder') }}" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"></textarea>
                                @error('message')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
                            </div>
                            <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-600 dark:bg-gray-700/50">
                                <h4 class="mb-2 text-sm font-semibold text-gray-700 dark:text-gray-300">{{ __('points.preview') }}</h4>
                                <div class="space-y-1 text-sm text-gray-600 dark:text-gray-400">
                                    <p><strong>{{ __('points.to') }}:</strong> <span x-text="name || email || '...'"></span></p>
                                    <p><strong>{{ __('points.subject') }}:</strong> {{ __('points.email_default_subject') }}</p>
                                    <hr class="my-2 border-gray-200 dark:border-gray-600">
                                    <div x-show="!message" class="whitespace-pre-wrap text-gray-500 dark:text-gray-300">{{ __('points.invitation_message_placeholder') }}</div>
                                    <template x-if="message"><p x-text="message" class="whitespace-pre-wrap"></p></template>
                                    <hr class="my-2 border-gray-200 dark:border-gray-600">
                                    <p class="break-all text-xs text-gray-400">{{ $referralLink }}</p>
                                </div>
                            </div>
                        </div>
                        <div class="mt-6 flex justify-end gap-3">
                            <button type="button" @click="modalOpen = false; sending = false" class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600">{{ __('points.cancel') }}</button>
                            <button type="submit" :disabled="sending" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-indigo-700 disabled:opacity-50"><span x-show="!sending">{{ __('points.send') }}</span><span x-show="sending">...</span></button>
                        </div>
                    </form>
                </div>
            </div>
        @endif

        @push('scripts')
            <script>
                document.addEventListener('alpine:init', () => {
                    Alpine.data('referralModal', () => ({
                        modalOpen: false,
                        sending: false,
                        email: '',
                        name: '',
                        message: '',
                        openModal() {
                            this.modalOpen = true;
                            this.sending = false;
                            this.email = '';
                            this.name = '';
                            this.message = '';
                        }
                    }));
                });
            </script>
        @endpush
    </x-page-container>
</x-app-layout>
