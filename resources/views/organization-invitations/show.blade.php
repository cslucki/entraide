{{--
    Public landing page for an Organization invitation (TASK-1659). Read-only
    by construction: the single action is a POST, because accepting mutates
    state — it creates the account — and must never ride on a GET
    navigation.
--}}
<x-guest-layout>
    <div class="mx-auto w-full max-w-xl px-4 py-10">
        @if (session('error'))
            <div class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-300">
                {{ session('error') }}
            </div>
        @endif

        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="p-6 sm:p-8">
                <p class="text-xs font-semibold uppercase tracking-wide text-indigo-600 dark:text-indigo-400">
                    {{ __('organization_invitations.landing_eyebrow') }}
                </p>

                <h1 class="mt-1 text-2xl font-bold text-gray-900 dark:text-gray-100">
                    {{ $organization?->name ?? __('organization_invitations.landing_unknown_organization') }}
                </h1>

                <dl class="mt-5 space-y-2 border-t border-gray-100 pt-5 text-sm dark:border-gray-700">
                    <div class="flex items-baseline justify-between gap-4">
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('organization_invitations.landing_sent_to') }}</dt>
                        <dd class="font-medium text-gray-800 dark:text-gray-200">{{ $invitation->recipient_email }}</dd>
                    </div>
                    @if ($invitation->expires_at)
                        <div class="flex items-baseline justify-between gap-4">
                            <dt class="text-gray-500 dark:text-gray-400">{{ __('organization_invitations.landing_expires_on') }}</dt>
                            <dd class="font-medium text-gray-800 dark:text-gray-200">{{ $invitation->expires_at->isoFormat('LL') }}</dd>
                        </div>
                    @endif
                </dl>

                <div class="mt-6">
                    @if ($isRevoked)
                        <p class="rounded-xl bg-gray-50 px-4 py-3 text-sm text-gray-600 dark:bg-gray-900/40 dark:text-gray-300">
                            {{ __('organization_invitations.landing_revoked') }}
                        </p>
                    @elseif ($isExpired)
                        <p class="rounded-xl bg-gray-50 px-4 py-3 text-sm text-gray-600 dark:bg-gray-900/40 dark:text-gray-300">
                            {{ __('organization_invitations.landing_expired') }}
                        </p>
                    @elseif ($isAccepted)
                        {{--
                            Aucun bouton qui reconnecte ici, et c'est deliberе : un
                            jeton deja consomme ne doit plus ouvrir de session, sinon
                            le lien recu par courriel resterait un mot de passe
                            permanent pour ce compte. On renvoie vers la connexion
                            normale (ou « mot de passe oublie »).
                        --}}
                        <p class="rounded-xl bg-gray-50 px-4 py-3 text-sm text-gray-600 dark:bg-gray-900/40 dark:text-gray-300">
                            {{ __('organization_invitations.landing_already_accepted') }}
                        </p>
                        <a href="{{ $organization && \Illuminate\Support\Facades\Route::has('organization.login')
                                        ? route('organization.login', ['organization' => $organization->slug])
                                        : route('login') }}"
                           class="mt-4 block w-full rounded-xl bg-indigo-600 px-4 py-3 text-center text-sm font-semibold text-white hover:bg-indigo-700">
                            {{ __('organization_invitations.landing_cta_sign_in') }}
                        </a>
                        <p class="mt-3 text-xs text-gray-400 dark:text-gray-500">
                            {{ __('organization_invitations.landing_forgot_password_hint') }}
                        </p>
                    @else
                        <p class="mb-4 text-sm text-gray-600 dark:text-gray-300">
                            {{ __('organization_invitations.landing_body', ['organization' => $organization?->name ?? '']) }}
                        </p>
                        <form method="POST" action="{{ route('organization-invitations.accept', $invitation->token) }}">
                            @csrf
                            <button type="submit"
                                    class="w-full rounded-xl bg-indigo-600 px-4 py-3 text-center text-sm font-semibold text-white hover:bg-indigo-700">
                                {{ __('organization_invitations.landing_cta_join', ['organization' => $organization?->name ?? '']) }}
                            </button>
                        </form>
                        <p class="mt-3 text-xs text-gray-400 dark:text-gray-500">
                            {{ __('organization_invitations.landing_no_password_needed') }}
                        </p>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-guest-layout>
