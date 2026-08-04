{{--
    Dedicated edit page for one Loop, Organization-scoped.

    Everything that acts on a Loop lives here — identity, type, cards, members —
    rather than being crammed into the rows of the listing table, where the
    forms fought each other for space and nothing was readable.
--}}
<x-app-layout>
    @php
        // Aliased: Blade injects its own $loop inside every @foreach, including
        // those of the layout, and PHP has no block scope — the model would be
        // clobbered. Same convention as loops/show.blade.php.
        $currentLoop = $loop;
    @endphp
    <div class="mx-auto max-w-4xl px-4 py-8">

        <div class="mb-6">
            <a href="{{ route('organization.admin.loops', ['organization' => $organization->slug]) }}"
               class="inline-flex items-center gap-1 text-sm text-gray-500 transition hover:text-indigo-600 dark:text-gray-400">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18"/></svg>
                {{ __('navigation.org_admin_loops') }}
            </a>
            <h1 class="mt-2 text-2xl font-bold text-gray-900 dark:text-gray-100">{{ $currentLoop->name }}</h1>
            <p class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                <span>{{ $currentLoop->active_members_count }} membre{{ $currentLoop->active_members_count !== 1 ? 's' : '' }}</span>
                <span>· {{ $currentLoop->invitations_count }} {{ mb_strtolower(__('loops.invitations_sent_count')) }}@if($currentLoop->pending_invitations_count) ({{ $currentLoop->pending_invitations_count }} en attente)@endif</span>
                <span>· {{ $currentLoop->visibility }}</span>
                <a href="{{ $currentLoop->workspaceUrl() }}" class="font-medium text-indigo-600 hover:underline dark:text-indigo-400">· Ouvrir le workspace</a>
            </p>
        </div>

        @if(session('success'))
            <div class="mb-4 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700 dark:border-green-800 dark:bg-green-900/20 dark:text-green-300">
                {{ session('success') }}
            </div>
        @endif
        @if(session('error'))
            <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/20 dark:text-red-300">
                {{ session('error') }}
            </div>
        @endif

        {{-- Identité et type --}}
        <section class="mb-6 rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800 sm:p-6">
            <h2 class="mb-4 text-sm font-semibold text-gray-800 dark:text-gray-100">Identité de la Boucle</h2>

            <form method="POST" action="{{ route('organization.admin.loops.update', ['organization' => $organization->slug, 'loop' => $currentLoop->id]) }}" class="space-y-4">
                @csrf @method('PUT')

                <label class="block">
                    <span class="text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('navigation.org_admin_table_name') }}</span>
                    <input type="text" name="name" value="{{ old('name', $currentLoop->name) }}" required maxlength="255"
                           class="mt-1 w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                    <x-input-error :messages="$errors->get('name')" class="mt-1" />
                </label>

                <label class="block">
                    <span class="text-xs font-medium text-gray-600 dark:text-gray-300">Description</span>
                    <textarea name="description" rows="3" maxlength="5000"
                              class="mt-1 w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">{{ old('description', $currentLoop->description) }}</textarea>
                    <x-input-error :messages="$errors->get('description')" class="mt-1" />
                </label>

                <fieldset>
                    <legend class="text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('loops.type_label') }}</legend>
                    <p class="mb-2 mt-1 text-xs text-gray-400">
                        Le type définit la composition initiale des Cards. Changer de type <strong>ajoute</strong> les Cards manquantes et n'en supprime jamais aucune.
                    </p>
                    <div class="grid gap-2 sm:grid-cols-2">
                        @foreach($loopTypes as $key => $definition)
                            <label class="flex cursor-pointer items-start gap-2 rounded-xl border border-gray-200 p-3 transition hover:border-indigo-300 has-[:checked]:border-indigo-500 has-[:checked]:bg-indigo-50/50 dark:border-gray-700 dark:has-[:checked]:bg-indigo-900/20">
                                <input type="radio" name="type" value="{{ $key }}" @checked(old('type', $currentType) === $key)
                                       class="mt-0.5 text-indigo-600 focus:ring-indigo-500">
                                <span class="min-w-0">
                                    <span class="block text-sm font-medium text-gray-800 dark:text-gray-100">{{ __($definition['label_key']) }}</span>
                                    <span class="block text-xs text-gray-500 dark:text-gray-400">{{ __($definition['description_key']) }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                    <x-input-error :messages="$errors->get('type')" class="mt-1" />
                </fieldset>

                <button type="submit"
                        class="inline-flex items-center justify-center rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-indigo-700">
                    Enregistrer
                </button>
            </form>
        </section>

        {{-- Cards liées --}}
        <section class="mb-6 rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800 sm:p-6">
            <h2 class="mb-1 text-sm font-semibold text-gray-800 dark:text-gray-100">{{ __('loops.cards_linked') }}</h2>
            <p class="mb-3 text-xs text-gray-400">
                Les Cards marquées « socle » viennent du type. Les autres ont été ajoutées pour cette Boucle et sont conservées quel que soit le type.
            </p>
            @forelse($currentLoop->cards->where('enabled', true) as $card)
                <span class="mr-2 inline-flex items-center gap-1.5 rounded-full border border-gray-200 bg-gray-50 px-2.5 py-1 text-xs font-medium text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200">
                    {{ $card->label() }}
                    @if(in_array($card->card_key, $presetCards, true))
                        <span class="rounded bg-indigo-100 px-1 text-[10px] font-semibold text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300">socle</span>
                    @endif
                </span>
            @empty
                <p class="text-xs text-gray-400">Aucune Card. Enregistrez le type pour appliquer son socle.</p>
            @endforelse
        </section>

        {{-- Membres --}}
        <section class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800 sm:p-6">
            <h2 class="mb-3 text-sm font-semibold text-gray-800 dark:text-gray-100">Membres actifs</h2>

            <x-loops.governance-roster
                class="mb-4"
                :members="$currentLoop->activeMembers"
                :role-route="fn($m) => route('organization.admin.loops.members.role', [$organization, $currentLoop, $m])"
                :remove-route="fn($m) => route('organization.admin.loops.members.remove', [$organization, $currentLoop, $m])"
                :can-manage-owners="true"
                :can-manage-facilitators="true"
                :can-remove="true"
                :creator-id="$currentLoop->created_by"
                :current-user-id="auth()->id()" />

            @if($candidates->isNotEmpty())
                {{-- A searchable picker rather than a bare select: an Organization
                     holds ~200 people, and scrolling a native dropdown to find one
                     was the least usable part of the old table. --}}
                <form method="POST" action="{{ route('organization.admin.loops.members.add', [$organization, $currentLoop]) }}"
                      x-data="{ q: '', picked: '' }" class="border-t border-gray-100 pt-4 dark:border-gray-700">
                    @csrf
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-300">Ajouter un membre</label>
                    <input type="search" x-model="q" placeholder="Rechercher un nom ou un email..."
                           class="mt-1 w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">

                    <div class="mt-2 max-h-56 space-y-1 overflow-y-auto rounded-xl border border-gray-200 p-2 dark:border-gray-700">
                        @foreach($candidates as $candidate)
                            <label x-show="q === '' || {{ \Illuminate\Support\Js::from(mb_strtolower($candidate->publicDisplayName().' '.$candidate->email)) }}.includes(q.toLowerCase())"
                                   class="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1.5 hover:bg-gray-50 has-[:checked]:bg-indigo-50 dark:hover:bg-gray-700/50 dark:has-[:checked]:bg-indigo-900/20">
                                <input type="radio" name="user_id" value="{{ $candidate->id }}" x-model="picked" class="text-indigo-600 focus:ring-indigo-500">
                                <span class="min-w-0 flex-1 truncate text-sm text-gray-700 dark:text-gray-200">{{ $candidate->publicDisplayName() }}</span>
                            </label>
                        @endforeach
                    </div>

                    <label class="mt-3 block text-xs font-medium text-gray-600 dark:text-gray-300">{{ __('loops.governance_add_as') }}</label>
                    <select name="role"
                            class="mt-1 min-h-[44px] w-full rounded-xl border border-gray-300 bg-white px-3 text-sm text-gray-900 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 sm:w-auto">
                        <option value="member">{{ __('loops.members_role_member') }}</option>
                        <option value="facilitator">{{ __('loops.members_role_facilitator') }}</option>
                        <option value="owner">{{ __('loops.members_role_owner') }}</option>
                    </select>

                    <button type="submit" x-bind:disabled="picked === ''"
                            class="mt-3 inline-flex items-center justify-center rounded-xl bg-gray-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-gray-700 disabled:cursor-not-allowed disabled:opacity-40 dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-white">
                        Ajouter à la Boucle
                    </button>
                </form>
            @else
                <p class="border-t border-gray-100 pt-4 text-xs text-gray-400 dark:border-gray-700">
                    Tous les membres de l'Organization font déjà partie de cette Boucle.
                </p>
            @endif
        </section>

    </div>
</x-app-layout>
