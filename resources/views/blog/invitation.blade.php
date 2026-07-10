<x-app-layout>
    <div class="min-h-screen bg-gray-50 flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8">
        <div class="max-w-lg w-full">
            <div class="bg-white rounded-xl shadow-md p-8">
                {{-- Badge --}}
                <div class="inline-block px-3 py-1 bg-indigo-50 text-indigo-700 text-xs font-semibold rounded-full mb-4">
                    {{ __('blog-invitation.email_badge') }}
                </div>

                {{-- Expired --}}
                @if($isExpired)
                    <h1 class="text-xl font-bold text-gray-900 mb-4">{{ __('blog-invitation.invite_expired_title') }}</h1>
                    <p class="text-gray-600 mb-6">{{ __('blog-invitation.invite_expired_message') }}</p>
                    <a href="{{ route('home') }}" class="inline-block px-6 py-3 bg-gray-100 text-gray-700 rounded-lg font-semibold hover:bg-gray-200 transition">
                        {{ __('blog-invitation.invite_go_home') }}
                    </a>

                {{-- Already accepted --}}
                @elseif($isAccepted)
                    <h1 class="text-xl font-bold text-gray-900 mb-4">{{ __('blog-invitation.invite_accepted_title') }}</h1>
                    <p class="text-gray-600 mb-6">{{ __('blog-invitation.invite_accepted_message') }}</p>
                    <a href="{{ route('home') }}" class="inline-block px-6 py-3 bg-gray-100 text-gray-700 rounded-lg font-semibold hover:bg-gray-200 transition">
                        {{ __('blog-invitation.invite_go_home') }}
                    </a>

                {{-- Pending --}}
                @else
                    <h1 class="text-xl font-bold text-gray-900 mb-2">
                        {{ __('blog-invitation.invite_title', ['sender' => $sender->fullName ?? '']) }}
                    </h1>
                    <p class="text-gray-600 mb-4">
                        {{ __('blog-invitation.invite_subtitle') }}
                    </p>

                    {{-- Article info --}}
                    <div class="bg-gray-50 rounded-lg p-4 mb-6 border border-gray-100">
                        <div class="text-sm text-gray-500 mb-1">{{ __('blog-invitation.invite_article') }}</div>
                        <div class="text-lg font-semibold text-gray-900">{{ $post->title }}</div>
                        <div class="text-sm text-gray-500 mt-1">
                            {{ __('blog-invitation.invite_status') }} :
                            @if($post->status === 'published')
                                <span class="text-green-600 font-medium">{{ __('blog-invitation.invite_status_published') }}</span>
                            @elseif($post->status === 'pending')
                                <span class="text-yellow-600 font-medium">{{ __('blog-invitation.invite_status_pending') }}</span>
                            @else
                                <span class="text-gray-500 font-medium">{{ __('blog-invitation.invite_status_draft') }}</span>
                            @endif
                        </div>
                    </div>

                    {{-- Personal message --}}
                    @if($invitation->message)
                        <div class="mb-6">
                            <div class="text-sm text-gray-500 mb-1">{{ __('blog-invitation.invite_message_from_sender') }}</div>
                            <div class="bg-indigo-50 rounded-lg p-4 text-gray-700 italic whitespace-pre-wrap">{{ $invitation->message }}</div>
                        </div>
                    @endif

                    {{-- Actions --}}
                    <div class="space-y-3">
                        <form method="POST" action="{{ route('blog.invite.accept', ['token' => $invitation->token]) }}">
                            @csrf
                            <button type="submit" class="w-full px-6 py-3 bg-indigo-600 text-white rounded-lg font-semibold hover:bg-indigo-700 transition text-center">
                                {{ __('blog-invitation.invite_btn_accept') }}
                            </button>
                        </form>

                        <div class="text-center text-sm text-gray-500">
                            {{ __('blog-invitation.invite_not_you') }}
                            <a href="{{ route('login') }}" class="text-indigo-600 hover:text-indigo-800 font-medium">{{ __('blog.login') }}</a>
                        </div>
                    </div>

                    {{-- Expiry notice --}}
                    <div class="mt-6 text-xs text-gray-400 text-center">
                        {{ __('blog-invitation.invite_expires', ['date' => $invitation->expires_at->locale(app()->getLocale())->isoFormat('LL')]) }}
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
