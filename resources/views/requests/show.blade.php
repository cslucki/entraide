<x-app-layout>
    <div class="max-w-4xl mx-auto px-4 py-8">
        <div class="mb-6">
            <a href="{{ route('explorer') }}" class="text-sm text-gray-500 hover:text-indigo-600">← Retour à l'explorateur</a>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="p-6">
                <div class="flex items-start justify-between mb-4">
                    <div class="flex-1">
                        <span class="px-2 py-0.5 rounded-full text-xs font-medium text-white" style="background-color:{{ $request->category->color }}">
                            {{ $request->category->name }}
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
                        <a href="{{ route('profile.show', $request->user) }}" class="font-medium text-gray-900 dark:text-gray-100 hover:text-indigo-600">{{ $request->user->name }}</a>
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

                @auth
                @if(auth()->id() !== $request->user_id && $request->status === 'open')
                <div class="border-t border-gray-100 dark:border-gray-700 pt-6">
                    <form method="POST" action="{{ route('transactions.store') }}" class="flex items-center gap-4">
                        @csrf
                        <input type="hidden" name="request_id" value="{{ $request->id }}">
                        <input type="number" name="points_proposed" value="{{ $request->budget_min }}" min="1"
                            class="w-32 px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm">
                        <button type="submit" class="px-6 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 font-medium">
                            Proposer mon aide
                        </button>
                        <span class="text-xs text-gray-500">Votre solde : {{ auth()->user()->points_balance }} pts</span>
                    </form>
                </div>
                @endif
                @endauth
            </div>
        </div>
    </div>
</x-app-layout>
