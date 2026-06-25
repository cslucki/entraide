<x-admin-layout title="Suppression utilisateur">
    <div class="max-w-3xl mx-auto">
        <div class="flex items-center gap-3 mb-6">
            <a href="{{ route('admin.users.edit', $user) }}" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            </a>
            <div class="flex items-center gap-3">
                <img src="{{ $user->avatar_url }}" class="w-10 h-10 rounded-full" alt="">
                <div>
                    <h1 class="text-xl font-bold text-gray-900 dark:text-white leading-tight">{{ $user->name }}</h1>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $user->email }} · {{ $user->organization?->name ?? '—' }}</p>
                </div>
            </div>
        </div>

        @if($errors->any())
        <div class="mb-5 p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-700 rounded-xl text-sm text-red-700 dark:text-red-400">
            <ul class="list-disc ml-4 space-y-1">
                @foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach
            </ul>
        </div>
        @endif

        @if(isset($counts['preview_only']) && $counts['preview_only'])
        <div class="mb-5 p-4 bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-700 rounded-xl text-sm text-yellow-700 dark:text-yellow-400">
            ⚠️ <strong>Dry-run uniquement.</strong> Aucune donnée n'a été supprimée. Ceci est un aperçu des lignes qui seraient impactées.
        </div>
        @endif

        <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 shadow-sm p-6 space-y-5">
            <h2 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Aperçu des données impactées</h2>

            <div class="space-y-4">
                <div>
                    <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Données liées (transférables)</h3>
                    <table class="w-full text-sm">
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @foreach($counts['own'] as $key => $count)
                            <tr>
                                <td class="py-1.5 text-gray-600 dark:text-gray-400">{{ __("admin.user_data_{$key}") }}</td>
                                <td class="py-1.5 text-right font-mono {{ $count > 0 ? 'text-red-600 dark:text-red-400 font-semibold' : 'text-gray-400' }}">{{ $count }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if(count($counts['part']) > 0)
                <div>
                    <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Participations</h3>
                    <table class="w-full text-sm">
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @foreach($counts['part'] as $key => $count)
                            <tr>
                                <td class="py-1.5 text-gray-600 dark:text-gray-400">{{ __("admin.user_data_{$key}") }}</td>
                                <td class="py-1.5 text-right font-mono {{ $count > 0 ? 'text-yellow-600 dark:text-yellow-400' : 'text-gray-400' }}">{{ $count }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @endif

                @if(count($counts['audit']) > 0)
                <div>
                    <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Traces (anonymisables)</h3>
                    <table class="w-full text-sm">
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @foreach($counts['audit'] as $key => $count)
                            <tr>
                                <td class="py-1.5 text-gray-600 dark:text-gray-400">{{ __("admin.user_data_{$key}") }}</td>
                                <td class="py-1.5 text-right font-mono {{ $count > 0 ? 'text-yellow-600 dark:text-yellow-400' : 'text-gray-400' }}">{{ $count }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @endif

                @if(isset($counts['transfer']))
                <div class="pt-2">
                    <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">✅ Sera transféré à {{ \App\Models\User::find(request('transfer_to'))?->name ?? '—' }}</h3>
                    <table class="w-full text-sm">
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @foreach($counts['transfer'] as $key => $count)
                            <tr>
                                <td class="py-1.5 text-gray-600 dark:text-gray-400">{{ __("admin.user_data_{$key}") }}</td>
                                <td class="py-1.5 text-right font-mono text-indigo-600">{{ $count }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @endif
            </div>

            <div class="pt-4 border-t border-gray-200 dark:border-gray-700">
                <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                    Total : {{ collect($counts['own'])->sum() + collect($counts['part'])->sum() + collect($counts['audit'])->sum() }} lignes impactées
                </p>
            </div>
        </div>

        @if(! isset($counts['preview_only']))
        <form method="POST" action="{{ route('admin.users.delete', $user) }}" class="mt-6 space-y-5">
            @csrf

            <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 shadow-sm p-6 space-y-4">
                <h2 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Mode : suppression</h2>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Transférer les données vers (optionnel)</label>
                    <select name="transfer_to" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
                        <option value="">— Supprimer les données (pas de transfert) —</option>
                        @foreach($sameOrgUsers as $target)
                        <option value="{{ $target->id }}">{{ $target->name }} ({{ $target->email }})</option>
                        @endforeach
                    </select>
                    <p class="text-xs text-gray-400 mt-1">Les données transférables seront attribuées à cet utilisateur. Les traces seront anonymisées.</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        Tapez <strong>{{ $user->name }}</strong> pour confirmer
                    </label>
                    <input type="text" name="confirmation" required autocomplete="off" placeholder="Tapez le nom exact de l'utilisateur"
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-red-500">
                    <p class="text-xs text-red-500 mt-1">Action irréversible. Vérifiez l'aperçu avant de confirmer.</p>
                </div>

                <button type="submit" class="w-full px-6 py-3 bg-red-600 hover:bg-red-700 text-white text-sm font-bold rounded-lg shadow-sm transition disabled:opacity-50">
                    @if(collect($counts['own'])->sum() + collect($counts['part'])->sum() + collect($counts['audit'])->sum() > 0)
                    Supprimer {{ $user->name }} ({{ collect($counts['own'])->sum() + collect($counts['part'])->sum() + collect($counts['audit'])->sum() }} lignes)
                    @else
                    Supprimer {{ $user->name }}
                    @endif
                </button>
            </div>
        </form>
        @else
        <div class="mt-6 text-center">
            <p class="text-sm text-gray-400">Ceci est un aperçu dry-run. Aucune donnée n'a été modifiée.</p>
            <a href="{{ route('admin.users.edit', $user) }}" class="mt-2 inline-block text-sm text-indigo-600 dark:text-indigo-400 hover:underline">
                Retour à l'édition de l'utilisateur
            </a>
        </div>
        @endif
    </div>
</x-admin-layout>
