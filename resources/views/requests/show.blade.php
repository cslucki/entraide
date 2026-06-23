<x-page :title="$request->title" width="7xl">
    @php
        $_reqOrgSlug = request()->route('organization');
        $_reqExplorerHref = $_reqOrgSlug && Route::has('organization.explorer') ? route('organization.explorer', ['organization' => $_reqOrgSlug]) : route('explorer');
        $_reqProfileHref = $_reqOrgSlug && Route::has('organization.profile.show') ? route('organization.profile.show', ['organization' => $_reqOrgSlug, 'user' => $request->user]) : route('profile.show', $request->user);
        $_reqReportAction = $_reqOrgSlug && Route::has('organization.reports.request') ? route('organization.reports.request', ['organization' => $_reqOrgSlug, 'serviceRequest' => $request]) : route('reports.request', $request);
        $_reqTxStoreAction = $_reqOrgSlug && Route::has('organization.transactions.store') ? route('organization.transactions.store', ['organization' => $_reqOrgSlug]) : route('transactions.store');
    @endphp
        <div class="mb-6">
            <a href="{{ $_reqExplorerHref }}" class="text-sm text-gray-500 hover:text-indigo-600">← Retour à l'explorateur</a>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="p-6">
                <div class="flex items-start justify-between mb-4">
                    <div class="flex-1">
                        <span class="px-2 py-0.5 rounded-full text-xs font-medium text-white" style="background-color:{{ $request->category->color }}">
                            {{ $request->category->displayName('transactions') }}
                        </span>
                        <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100 mt-2">{{ $request->title }}</h1>
                    </div>
                    <div class="text-right ml-4">
                        <p class="text-2xl font-bold text-green-600 dark:text-green-400">
                            {{ $request->budget_min }}{{ $request->budget_max ? '–'.$request->budget_max : '+' }}
                        </p>
                        <p class="text-xs text-gray-500">points</p>
                    </div>
                </div>

                <div class="flex items-center gap-3 mb-6 pb-6 border-b border-gray-100 dark:border-gray-700">
                    <img src="{{ $request->user->avatar_url }}" class="w-10 h-10 rounded-full" alt="">
                    <div>
                        <a href="{{ $_reqProfileHref }}" class="font-medium text-gray-900 dark:text-gray-100 hover:text-indigo-600">{{ $request->user->name }}</a>
                        <div class="flex items-center gap-3 text-xs text-gray-500">
                            <span>{{ match($request->delivery_mode) { 'remote' => '🌐 À distance', 'onsite' => '📍 Sur site', 'both' => '🌐📍 Distance ou sur site' } }}</span>
                            @if($request->deadline)
                            <span>⏰ Avant le {{ $request->deadline->format('d/m/Y') }}</span>
                            @endif
                        </div>
                    </div>
                </div>

                <div class="mb-6">
                    <p class="text-gray-700 dark:text-gray-300 whitespace-pre-wrap">{{ $request->description }}</p>
                </div>

                @if($request->attachments->isNotEmpty())
                <div class="mb-6 border-t border-gray-100 dark:border-gray-700 pt-5">
                    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3">Pièces jointes</h3>
                    <div class="flex flex-wrap gap-3">
                        @foreach($request->attachments as $att)
                            @if($att->isImage())
                            <a href="{{ $att->url }}" target="_blank" rel="noopener noreferrer"
                               class="block w-24 h-24 rounded-lg overflow-hidden border border-gray-200 dark:border-gray-600 hover:opacity-80 transition">
                                <img src="{{ $att->url }}" alt="{{ $att->original_name }}" class="w-full h-full object-cover">
                            </a>
                            @else
                            <a href="{{ $att->url }}" target="_blank" rel="noopener noreferrer"
                               class="flex items-center gap-2 px-3 py-2 border border-gray-200 dark:border-gray-600 rounded-lg hover:border-indigo-400 transition text-sm text-gray-700 dark:text-gray-300">
                                @if($att->iconClass() === 'pdf')
                                    <span class="text-red-500 text-lg">📄</span>
                                @elseif($att->iconClass() === 'word')
                                    <span class="text-blue-500 text-lg">📝</span>
                                @elseif($att->iconClass() === 'excel')
                                    <span class="text-green-500 text-lg">📊</span>
                                @endif
                                <span class="max-w-[160px] truncate">{{ $att->original_name }}</span>
                            </a>
                            @endif
                        @endforeach
                    </div>
                </div>
                @endif

                @auth
                @if(auth()->id() !== $request->user_id)
                <!-- Signalement -->
                <div class="mb-4" x-data="{ open: false }">
                    <button @click="open = !open" class="text-xs text-gray-400 hover:text-red-500 transition">Signaler cette demande</button>
                    <div x-show="open" x-cloak class="mt-2 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-4">
                        <form method="POST" action="{{ $_reqReportAction }}">
                            @csrf
                            <select name="reason" required class="w-full mb-2 px-3 py-2 border border-red-200 dark:border-red-700 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
                                <option value="">Motif du signalement...</option>
                                <option value="Contenu inapproprié">Contenu inapproprié</option>
                                <option value="Arnaque ou fraude">Arnaque ou fraude</option>
                                <option value="Spam">Spam</option>
                                <option value="Autre">Autre</option>
                            </select>
                            <textarea name="details" rows="2" placeholder="Détails (optionnel)..."
                                class="w-full px-3 py-2 border border-red-200 dark:border-red-700 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm mb-2 resize-none"></textarea>
                            <button type="submit" class="px-3 py-1.5 bg-red-600 text-white text-xs rounded-lg hover:bg-red-700">Envoyer le signalement</button>
                        </form>
                    </div>
                </div>

                @if($request->status === 'open')
                <div class="border-t border-gray-100 dark:border-gray-700 pt-6">
                    <form method="POST" action="{{ $_reqTxStoreAction }}" class="flex items-center gap-4">
                        @csrf
                        <input type="hidden" name="request_id" value="{{ $request->id }}">
                        <input type="number" name="points_proposed" value="{{ $request->budget_min }}" min="1"
                            class="w-32 px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm">
                        <button type="submit" class="px-6 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 font-medium">
                            Proposer mon aide
                        </button>
                        <span class="text-xs text-gray-500">Votre solde : {{ auth()->user()->points_balance }} pts</span>
                    </form>
                    @if($errors->any())
                    <p class="text-red-500 text-sm mt-2">{{ $errors->first() }}</p>
                    @endif
                </div>
                @endif
                @endif
                @endauth
            </div>
        </div>
    </div>
</x-page>
