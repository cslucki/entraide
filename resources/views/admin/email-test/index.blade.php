<x-admin-layout title="Test d'envoi d'email">
    <div class="max-w-2xl mx-auto">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white mb-1">Test d'envoi d'email</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mb-6">
            Driver actuel :
            <span class="font-semibold {{ $mailer === 'log' ? 'text-amber-600 dark:text-amber-400' : 'text-green-600 dark:text-green-400' }}">
                {{ $mailer }}
            </span>
            @if($mailer === 'log')
            — les emails sont écrits dans <code class="bg-gray-100 dark:bg-gray-700 px-1 rounded text-xs">storage/logs/laravel.log</code> (pas envoyés réellement)
            @else
            — expéditeur : <code class="bg-gray-100 dark:bg-gray-700 px-1 rounded text-xs">{{ $fromAddress }}</code>
            @endif
        </p>

        @if(session('success'))
        <div class="mb-5 p-4 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-700 rounded-xl text-sm text-green-700 dark:text-green-400 flex items-start gap-3">
            <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
            <span>{{ session('success') }}</span>
        </div>
        @endif

        @if(session('error'))
        <div class="mb-5 p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-700 rounded-xl text-sm text-red-700 dark:text-red-400 flex items-start gap-3">
            <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            <span>{{ session('error') }}</span>
        </div>
        @endif

        @if($mailer === 'log')
        <div class="mb-5 p-4 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700 rounded-xl text-sm text-amber-700 dark:text-amber-300">
            <p class="font-semibold mb-1">Mode log actif — aucun email ne sera envoyé</p>
            <p>Pour envoyer de vrais emails, configurez <code class="bg-amber-100 dark:bg-amber-900/40 px-1 rounded">MAIL_MAILER=resend</code> et <code class="bg-amber-100 dark:bg-amber-900/40 px-1 rounded">RESEND_KEY=re_xxx</code> dans votre <code>.env</code>.</p>
        </div>
        @endif

        <form method="POST" action="{{ route('admin.email-test.send') }}" class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 shadow-sm p-6 space-y-5">
            @csrf

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                    Destinataire <span class="text-red-500">*</span>
                </label>
                <input type="email" name="to" value="{{ old('to') }}"
                       placeholder="votreadresse@exemple.com"
                       required
                       class="w-full px-3 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                @error('to')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                    Sujet <span class="text-red-500">*</span>
                </label>
                <input type="text" name="subject" value="{{ old('subject', '[BouclePro] Email de test') }}"
                       required maxlength="200"
                       class="w-full px-3 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent">
                @error('subject')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                    Contenu <span class="text-red-500">*</span>
                </label>
                <textarea name="body" rows="6" required maxlength="2000"
                          class="w-full px-3 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent">{{ old('body', "Bonjour,\n\nCeci est un email de test envoyé depuis l'administration BouclePro.\n\nCordialement,\nL'équipe BouclePro") }}</textarea>
                @error('body')<p class="mt-1 text-xs text-red-500">{{ $message }}</p>@enderror
            </div>

            <div class="flex items-center gap-3 pt-2">
                <button type="submit"
                        class="px-6 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg shadow-sm transition focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                    Envoyer l'email de test
                </button>
                <span class="text-xs text-gray-400 dark:text-gray-500">Expéditeur : {{ $fromAddress }}</span>
            </div>
        </form>

        <!-- Instructions Resend -->
        <div class="mt-8 bg-gray-50 dark:bg-gray-800/50 border border-gray-200 dark:border-gray-700 rounded-2xl p-6">
            <h2 class="text-base font-semibold text-gray-800 dark:text-gray-200 mb-3">Configuration Resend (prod)</h2>
            <ol class="space-y-3 text-sm text-gray-600 dark:text-gray-400 list-decimal ml-4">
                <li>Créez un compte sur <strong class="text-gray-900 dark:text-white">resend.com</strong> (free tier : 3 000 emails/mois)</li>
                <li>Allez dans <strong>API Keys</strong> → créez une clé → copiez-la (<code class="bg-gray-200 dark:bg-gray-700 px-1 rounded text-xs">re_xxxxxxxxxxxx</code>)</li>
                <li>Allez dans <strong>Domains</strong> → ajoutez <code class="bg-gray-200 dark:bg-gray-700 px-1 rounded text-xs">bouclepro.com</code> → ajoutez les enregistrements DNS indiqués</li>
                <li>
                    Ajoutez dans votre <code class="bg-gray-200 dark:bg-gray-700 px-1 rounded text-xs">.env</code> :
                    <pre class="mt-2 bg-gray-900 text-green-400 text-xs rounded-lg p-3 overflow-x-auto">MAIL_MAILER=resend
RESEND_KEY=re_xxxxxxxxxxxx
MAIL_FROM_ADDRESS=noreply@bouclepro.com
MAIL_FROM_NAME="BouclePro"</pre>
                </li>
                <li>Sur <strong>Laravel Cloud</strong> : ajoutez ces 4 variables dans l'interface "Environment Variables" de votre projet</li>
            </ol>
            <p class="mt-3 text-xs text-gray-400 dark:text-gray-500">En local, gardez <code>MAIL_MAILER=log</code> pour ne pas consommer votre quota.</p>
        </div>
    </div>
</x-admin-layout>
