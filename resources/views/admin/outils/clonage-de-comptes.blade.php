<x-admin-layout title="Clonage de comptes">
    {{-- TASK-1882 (demande Cyril, 22/11) — copier un compte et ses billets de blog
         vers une autre organisation. Le compte d'origine n'est pas modifie. --}}
    @php
        $champ = 'w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100';
        $carte = 'rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800';
    @endphp
    <div class="max-w-4xl space-y-6" data-admin-account-clone>
        <p class="text-sm text-gray-600 dark:text-gray-300">
            Copie le compte (identité, mot de passe, photo, présentation, points) et ses billets de blog
            (couverture, images de l'article, Boucle) dans une autre organisation. Rien d'autre n'est copié,
            et le compte d'origine reste tel quel.
        </p>

        <form method="GET" action="{{ route('admin.outils.clonage-de-comptes') }}" class="{{ $carte }} grid gap-4 sm:grid-cols-3" data-admin-account-clone-select>
            <label class="block text-sm">
                <span class="mb-1 block font-medium text-gray-700 dark:text-gray-200">Organisation d'origine</span>
                <select name="origine" class="{{ $champ }}" onchange="this.form.compte.value=''; this.form.submit()">
                    @foreach($organizations as $org)
                        <option value="{{ $org->id }}" @selected($origine?->id === $org->id)>{{ $org->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block text-sm">
                <span class="mb-1 block font-medium text-gray-700 dark:text-gray-200">Compte à cloner</span>
                <select name="compte" class="{{ $champ }}" onchange="this.form.submit()">
                    <option value="">— Choisir —</option>
                    @foreach($comptes as $c)
                        <option value="{{ $c->id }}" @selected($source?->id === $c->id)>{{ trim($c->first_name.' '.$c->name) }} · {{ $c->email }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block text-sm">
                <span class="mb-1 block font-medium text-gray-700 dark:text-gray-200">Organisation cible</span>
                <select name="cible" class="{{ $champ }}" onchange="this.form.submit()">
                    <option value="">— Choisir —</option>
                    @foreach($organizations as $org)
                        @continue($origine?->id === $org->id)
                        <option value="{{ $org->id }}" @selected($cible?->id === $org->id)>{{ $org->name }}</option>
                    @endforeach
                </select>
            </label>
            <noscript><button type="submit" class="rounded-lg border px-3 py-1.5 text-sm">Afficher</button></noscript>
        </form>

        @if($source && $cible)
            <form method="POST" action="{{ route('admin.outils.clonage-de-comptes.store') }}" class="space-y-6" data-admin-account-clone-form>
                @csrf
                <input type="hidden" name="origine" value="{{ $origine->id }}">
                <input type="hidden" name="compte" value="{{ $source->id }}">
                <input type="hidden" name="cible" value="{{ $cible->id }}">

                <div class="{{ $carte }} flex items-center gap-4">
                    <img src="{{ $source->avatar_url }}" alt="" class="h-14 w-14 rounded-full object-cover">
                    <div class="min-w-0 text-sm">
                        <div class="font-semibold text-gray-900 dark:text-gray-100">{{ trim($source->first_name.' '.$source->name) }}</div>
                        <div class="text-gray-600 dark:text-gray-300">{{ $source->email }} · {{ $source->points_balance }} points</div>
                        <div class="text-gray-500 dark:text-gray-400">{{ $origine->name }} → <strong>{{ $cible->name }}</strong></div>
                    </div>
                </div>

                @if($emailPris)
                    <p class="rounded-lg bg-red-50 p-4 text-sm text-red-700 dark:bg-red-950/40 dark:text-red-300" data-admin-account-clone-email-taken>
                        Un compte {{ $source->email }} existe déjà dans {{ $cible->name }} : le clonage est impossible.
                    </p>
                @endif

                <div class="{{ $carte }}">
                    <h2 class="mb-3 text-sm font-semibold text-gray-900 dark:text-gray-100">Billets de blog ({{ $billets->count() }})</h2>

                    @forelse($billets as $billet)
                        <div class="grid gap-3 border-t border-gray-100 py-3 first:border-t-0 sm:grid-cols-3 dark:border-gray-700" data-admin-account-clone-post="{{ $billet->id }}">
                            <div class="text-sm">
                                <div class="font-medium text-gray-900 dark:text-gray-100">{{ $billet->title }}</div>
                                <div class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ $billet->status }}@if($billet->category) · {{ $billet->category->name_b2c }}@endif
                                    @if($billet->loops->isNotEmpty()) · Boucle : {{ $billet->loops->pluck('name')->join(', ') }}@endif
                                </div>
                            </div>
                            <label class="block text-xs">
                                <span class="mb-1 block text-gray-600 dark:text-gray-300">Catégorie dans {{ $cible->name }}</span>
                                <select name="billets[{{ $billet->id }}][category_id]" class="{{ $champ }}">
                                    <option value="">Aucune</option>
                                    @foreach($categories as $categorie)
                                        <option value="{{ $categorie->id }}">{{ $categorie->name_b2c }}</option>
                                    @endforeach
                                </select>
                            </label>
                            @if($billet->loops->isNotEmpty())
                                <label class="block text-xs">
                                    <span class="mb-1 block text-gray-600 dark:text-gray-300">Boucle dans {{ $cible->name }}</span>
                                    <select name="billets[{{ $billet->id }}][loop_id]" class="{{ $champ }}">
                                        <option value="">Aucune</option>
                                        @foreach($boucles as $boucle)
                                            <option value="{{ $boucle->id }}">{{ $boucle->name }}</option>
                                        @endforeach
                                    </select>
                                </label>
                            @else
                                <p class="self-center text-xs text-gray-400">Pas de Boucle sur le billet d'origine.</p>
                            @endif
                        </div>
                    @empty
                        <p class="text-sm text-gray-500 dark:text-gray-400">Ce compte n'a pas de billet de blog.</p>
                    @endforelse
                </div>

                <div class="flex justify-end">
                    <button type="submit" @disabled($emailPris)
                            class="rounded-lg bg-indigo-600 px-5 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50"
                            data-admin-account-clone-submit>
                        Cloner le compte dans {{ $cible->name }}
                    </button>
                </div>
            </form>
        @endif
    </div>
</x-admin-layout>
