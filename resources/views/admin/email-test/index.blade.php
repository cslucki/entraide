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

        {{-- TASK-1376 — l'etat REEL du transport.
             « smtp » ne dit pas si l'on parle a un serveur de capture local ou a
             un vrai relais qui enverra pour de bon. C'est pourtant la seule
             question qu'on se pose avant de cliquer.

             Aucun identifiant n'est affiche, meme masque : un masque revele
             qu'un secret existe et sa longueur, et il se retire par accident au
             refactor suivant. --}}
        @php
            $externe = $transport['badge'] === 'SMTP EXTERNE ACTIF';
        @endphp
        <div data-mail-diagnostics
             class="mb-5 rounded-2xl border p-4 {{ $externe ? 'border-red-300 bg-red-50 dark:border-red-700 dark:bg-red-900/20' : 'border-emerald-300 bg-emerald-50 dark:border-emerald-700 dark:bg-emerald-900/20' }}">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <span data-mail-badge="{{ $transport['badge'] }}"
                      class="rounded-full px-3 py-1 text-xs font-bold {{ $externe ? 'bg-red-600 text-white' : 'bg-emerald-600 text-white' }}">
                    {{ $transport['badge'] }}
                </span>

                @if($mailhogUrl)
                    {{-- L'adresse est affichee : la sonde passe par la boucle locale, mais ce
                         lien s'ouvre depuis le navigateur, qui n'est pas toujours sur la meme
                         machine (WSL). Un compteur qui s'affiche a cote d'un lien mort n'est
                         diagnosticable que si l'on voit ou pointe le lien. --}}
                    <a href="{{ $mailhogUrl }}" target="_blank" rel="noopener"
                       data-mailhog-link title="{{ $mailhogUrl }}"
                       class="text-sm font-semibold text-emerald-700 underline dark:text-emerald-300">
                        Ouvrir MailHog
                        <span class="font-normal opacity-70">{{ parse_url($mailhogUrl, PHP_URL_HOST) }}</span>
                        @if($mailhogCount !== null)
                            <span data-mailhog-count="{{ $mailhogCount }}">({{ $mailhogCount }} message{{ $mailhogCount > 1 ? 's' : '' }})</span>
                        @else
                            <span data-mailhog-unreachable>(injoignable)</span>
                        @endif
                    </a>
                @endif
            </div>

            <dl class="mt-3 grid grid-cols-1 gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
                <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">Environnement</dt><dd data-mail-env class="font-semibold">{{ $transport['environment'] }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">Driver</dt><dd data-mail-mailer class="font-semibold">{{ $transport['mailer'] }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">Hôte</dt><dd data-mail-host class="font-semibold">{{ $transport['host'] ?? '—' }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">Port</dt><dd data-mail-port class="font-semibold">{{ $transport['port'] ?? '—' }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">Chiffrement</dt><dd data-mail-scheme class="font-semibold">{{ $transport['scheme'] ?? 'aucun' }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">Authentification</dt><dd data-mail-auth="{{ $transport['authenticated'] ? 'oui' : 'non' }}" class="font-semibold">{{ $transport['authenticated'] ? 'configurée' : 'aucune' }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">Expéditeur</dt><dd data-mail-from class="font-semibold">{{ $transport['from_address'] ?? '—' }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">Nom</dt><dd class="font-semibold">{{ $transport['from_name'] ?? '—' }}</dd></div>
            </dl>

            @if($externe)
                <p class="mt-3 text-sm font-semibold text-red-700 dark:text-red-300">
                    Un relais SMTP externe est actif : un email de test partira réellement.
                </p>
            @endif
        </div>

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
            <p>Pour envoyer de vrais emails, configurez <code class="bg-amber-100 dark:bg-amber-900/40 px-1 rounded">MAIL_MAILER=smtp</code> avec un transport SMTP dans votre <code>.env</code>.</p>
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

        {{-- TASK-1376 — ce bloc recopiait une recette `.env` de production.

             Il publiait le host SMTP de production et nommait les variables
             d'identifiant, de mot de passe et de cle d'API sur une page
             consultable. Meme vides, ces noms disent quelle porte pousser ; et
             le host disait laquelle.

             Une page de diagnostic montre l'etat EFFECTIF du runtime — mesure
             juste au-dessus — et rien d'autre. La configuration se fait dans
             l'environnement du serveur, pas en la relisant ici. --}}
        <div class="mt-8 bg-gray-50 dark:bg-gray-800/50 border border-gray-200 dark:border-gray-700 rounded-2xl p-6">
            <h2 class="text-base font-semibold text-gray-800 dark:text-gray-200 mb-2">Configuration du transport</h2>
            <p class="text-sm text-gray-600 dark:text-gray-400">
                Le transport email est configuré par l'environnement du serveur.
                Les identifiants et les secrets ne sont jamais affichés ici.
            </p>
            <ul class="mt-3 space-y-1 text-sm text-gray-600 dark:text-gray-400">
                <li><strong class="text-gray-900 dark:text-white">En local</strong> — MailHog, qui capture les emails sans rien envoyer.</li>
                <li><strong class="text-gray-900 dark:text-white">En production</strong> — un transport externe, défini par l'environnement.</li>
            </ul>
            <p class="mt-3 text-xs text-gray-400 dark:text-gray-500">
                L'encadré ci-dessus indique lequel des deux est réellement actif en ce moment.
            </p>
        </div>
    </div>
</x-admin-layout>
