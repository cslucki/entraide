<x-admin-layout title="Inspector IA — Tester une requête">
    <div class="max-w-5xl mx-auto space-y-6">

        <div class="flex items-start justify-between gap-4">
            <div>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Inspector IA — tester une requête</h2>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                    Produit un <strong>vrai</strong> tour IA par le chemin produit (mêmes gardes, même clé d'Organization, même ledger), sans publier dans la Boucle, puis ouvre sa trace.
                </p>
            </div>
        </div>

        @include('admin.ai-turns._onglets', ['actif' => 'tester'])

        <div class="rounded-lg border border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-900/30 px-4 py-3 text-sm text-amber-900 dark:text-amber-200" data-inspector-test-warning>
            <strong>Ce test peut appeler des fournisseurs IA et générer un coût.</strong>
            Il est facturé à l'Organization comme un tour du membre choisi (garde économique, ledger) — aucune gratuité Inspector.
            Rien n'est publié dans la Boucle : aucun message de test n'apparaît aux membres.
        </div>

        @if (session('inspector_test_error'))
            <p class="text-sm text-red-600 dark:text-red-400" data-inspector-test-error>{{ session('inspector_test_error') }}</p>
        @endif

        {{-- Étape 1-3 : sélections dépendantes, rendues côté serveur (GET, sans effet). --}}
        <form method="get" action="{{ route('admin.ai-turns.test') }}" class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-4 space-y-4" data-inspector-test-context>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <label class="text-sm">
                    <span class="block text-xs uppercase text-gray-500 dark:text-gray-400 mb-1">Organization</span>
                    <select name="organization" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm" data-inspector-test-organization>
                        <option value="">— choisir —</option>
                        @foreach ($organizations as $o)
                            <option value="{{ $o->id }}" @selected($organization?->id === $o->id)>{{ $o->slug }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="text-sm">
                    <span class="block text-xs uppercase text-gray-500 dark:text-gray-400 mb-1">Utilisateur <span class="normal-case text-gray-400">(de cette Organization)</span></span>
                    <select name="user" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm" data-inspector-test-user @disabled($organization === null)>
                        <option value="">{{ $organization === null ? 'choisir une Organization d\'abord' : '— choisir —' }}</option>
                        @foreach ($users as $u)
                            <option value="{{ $u->id }}" @selected($user?->id === $u->id)>{{ $u->email }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="text-sm">
                    <span class="block text-xs uppercase text-gray-500 dark:text-gray-400 mb-1">Boucle <span class="normal-case text-gray-400">(membre actif)</span></span>
                    <select name="loop" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm" data-inspector-test-loop @disabled($user === null)>
                        <option value="">{{ $user === null ? 'choisir un utilisateur d\'abord' : ($loops->isEmpty() ? 'aucune Boucle accessible à cet utilisateur' : '— choisir —') }}</option>
                        @foreach ($loops as $l)
                            <option value="{{ $l->id }}" @selected($loopChoisie?->id === $l->id)>{{ $l->name }}</option>
                        @endforeach
                    </select>
                </label>
            </div>
            <input type="hidden" name="mode" value="{{ $mode }}">
            <div class="flex justify-end">
                <button type="submit" class="px-4 py-2 bg-gray-700 text-white text-sm font-medium rounded-lg hover:bg-gray-800 transition">Actualiser les choix</button>
            </div>
        </form>

        {{-- Étape 4 : la requête. POST = la seule action qui exécute. --}}
        <form method="post" action="{{ route('admin.ai-turns.test.run') }}" class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-4 space-y-4" data-inspector-test-run>
            @csrf
            <input type="hidden" name="organization" value="{{ $organization?->id }}">
            <input type="hidden" name="user" value="{{ $user?->id }}">
            <input type="hidden" name="loop" value="{{ $loopChoisie?->id }}">

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <fieldset class="text-sm">
                    <legend class="block text-xs uppercase text-gray-500 dark:text-gray-400 mb-1">Mode</legend>
                    @foreach ($modes as $m)
                        <label class="flex items-center gap-2 py-0.5">
                            <input type="radio" name="mode" value="{{ $m }}" @checked($mode === $m) class="text-indigo-600">
                            <code class="text-xs">{{ $m }}</code>
                            <span class="text-gray-500 dark:text-gray-400 text-xs">{{ ['dossiers' => 'RAG documentaire', 'ia_dossiers' => 'hybride', 'ia' => 'réponse directe (exige un déclencheur du fil)'][$m] }}</span>
                        </label>
                    @endforeach
                </fieldset>
                <label class="text-sm md:col-span-2">
                    <span class="block text-xs uppercase text-gray-500 dark:text-gray-400 mb-1">Déclencheur <span class="normal-case text-gray-400">(mode ia seulement — un message humain du fil, désigné par sa date et son identifiant, jamais créé par le test)</span></span>
                    <select name="trigger" class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm" data-inspector-test-trigger @disabled($loopChoisie === null)>
                        <option value="">{{ $loopChoisie === null ? 'choisir une Boucle d\'abord' : ($triggers->isEmpty() ? 'aucun message humain dans cette Boucle' : '— aucun (modes documentaires) —') }}</option>
                        @foreach ($triggers as $t)
                            <option value="{{ $t->id }}" @selected($trigger?->id === $t->id)>{{ $t->created_at?->format('d/m H:i') }} — {{ \Illuminate\Support\Str::substr((string) $t->id, 0, 8) }}… — auteur {{ \Illuminate\Support\Str::substr((string) $t->sender_id, 0, 8) }}…{{ $t->already_answered ? ' — déjà répondu (sera refusé)' : '' }}</option>
                        @endforeach
                    </select>
                </label>
            </div>

            <label class="block text-sm">
                <span class="block text-xs uppercase text-gray-500 dark:text-gray-400 mb-1">Question</span>
                <textarea name="question" rows="3" maxlength="5000" minlength="3" required class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm" data-inspector-test-question placeholder="La question telle qu'un membre la poserait dans la Boucle">{{ $question }}</textarea>
            </label>

            <div class="flex items-center justify-between gap-4">
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    Publication dans ChatLoop : <strong>désactivée</strong> (le tour est écrit, aucune bulle ne l'est). Après exécution, redirection vers la trace du tour — y compris s'il a été refusé.
                </p>
                <button type="submit" @disabled($loopChoisie === null) class="px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition disabled:opacity-50 disabled:cursor-not-allowed" data-inspector-test-submit>Exécuter et inspecter</button>
            </div>
        </form>
    </div>
</x-admin-layout>
