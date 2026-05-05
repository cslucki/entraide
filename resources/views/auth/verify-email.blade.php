<x-guest-layout>
    <div class="px-8 pt-8 pb-2 border-b border-gray-100 dark:border-gray-700">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Vérifiez votre e-mail</h1>
    </div>

    <div class="px-8 py-6">
        <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
            Merci de votre inscription ! Avant de commencer, veuillez vérifier votre adresse e-mail en cliquant sur le lien que nous venons de vous envoyer.
        </p>
        <p class="text-sm text-gray-600 dark:text-gray-400">
            Si vous n'avez pas reçu l'e-mail, nous pouvons vous en envoyer un nouveau.
        </p>

        @if (session('status') == 'verification-link-sent')
        <div class="mt-4 p-3 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-700 rounded-lg text-sm text-green-700 dark:text-green-400">
            Un nouveau lien de vérification a été envoyé à votre adresse e-mail.
        </div>
        @endif

        <div class="mt-6 flex items-center justify-between gap-4">
            <form method="POST" action="{{ route('verification.send') }}">
                @csrf
                <button type="submit"
                        class="py-2 px-4 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg shadow-sm transition">
                    Renvoyer l'e-mail de vérification
                </button>
            </form>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="text-sm text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 underline transition">
                    Se déconnecter
                </button>
            </form>
        </div>
    </div>
</x-guest-layout>
