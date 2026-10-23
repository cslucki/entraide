{{--
    TASK-1693 bug Q — corriger le corps d'un message depuis la moderation.

    Une PAGE, pas une fenetre : le corps complet doit etre lisible et editable,
    et la liste n'en montre que 100 caracteres tronques. C'est le meme
    arbitrage qu'en TASK-1668, ou la modal de suppression est devenue une page.

    Le contexte — expediteur, date, Boucle ou echange — est affiche en LECTURE
    SEULE. Il ne s'agit pas de decor : l'administrateur doit voir de qui sont
    les mots qu'il modifie, et aucun de ces champs n'est modifiable, parce que
    changer l'auteur d'un propos serait un faux et non une moderation.
--}}
<x-admin-layout :title="__('admin.message_edit_title')">
    <div class="mx-auto max-w-3xl">
        <div class="mb-4">
            <a href="{{ route('admin.messages', ['filter' => $isLoop ? 'chatloop' : 'exchanges']) }}"
               class="text-sm text-indigo-600 hover:underline">&larr; {{ __('admin.message_edit_back') }}</a>
            {{-- TASK-1693 bug R — relire le message DANS SON FIL avant de le
                 corriger : une phrase se juge sur ce a quoi elle repond. Le
                 lien n'est pose que lorsqu'il aboutit. --}}
            @if($showRoute)
            <a href="{{ $showRoute }}" data-message-edit-show-link
               class="ml-3 text-sm text-indigo-600 hover:underline">{{ __('admin.message_show_in_thread') }}</a>
            @endif
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800">
            <h1 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ __('admin.message_edit_title') }}</h1>

            {{-- Ce qu'on ne modifie PAS. --}}
            <dl class="mt-4 grid grid-cols-1 gap-x-6 gap-y-2 rounded-lg bg-gray-50 p-4 text-sm sm:grid-cols-2 dark:bg-gray-900/40"
                data-message-edit-context>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-gray-400">{{ __('admin.message_edit_sender') }}</dt>
                    <dd class="text-gray-800 dark:text-gray-200">
                        @if($message->sender)
                            {{ $message->sender->full_name }}
                            <span class="text-xs text-gray-500">{{ $message->sender->email }}</span>
                        @else
                            <span class="italic text-gray-400">{{ __('admin.message_edit_system_sender') }}</span>
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-gray-400">{{ __('admin.message_edit_sent_at') }}</dt>
                    <dd class="text-gray-800 dark:text-gray-200">{{ $message->created_at?->format('d/m/Y H:i') ?? '—' }}</dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-xs uppercase tracking-wide text-gray-400">{{ __('admin.message_edit_context') }}</dt>
                    <dd class="text-gray-800 dark:text-gray-200">
                        @if($isLoop)
                            <span class="rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-medium text-indigo-800 dark:bg-indigo-900 dark:text-indigo-200">ChatLoop</span>
                            {{ $message->loop->name ?? '—' }}
                        @else
                            <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-900 dark:text-amber-200">{{ __('admin.message_edit_exchange') }}</span>
                            {{ $message->transaction?->buyer?->name ?? '?' }}
                            <span class="text-gray-400">&harr;</span>
                            {{ $message->transaction?->seller?->name ?? '?' }}
                        @endif
                    </dd>
                </div>

                {{-- L'historique des editions, s'il y en a une. --}}
                @if($message->edited_at)
                <div class="sm:col-span-2" data-message-edit-history>
                    <dt class="text-xs uppercase tracking-wide text-gray-400">{{ __('admin.message_edit_last_edit') }}</dt>
                    <dd class="text-gray-800 dark:text-gray-200">
                        {{ $message->edited_at->format('d/m/Y H:i') }}
                        @if($message->editedBy)
                            — {{ $message->editedBy->full_name }}
                        @endif
                    </dd>
                </div>
                @endif
            </dl>

            @if($errors->any())
            <div class="mt-4 rounded-lg border border-red-300 bg-red-50 p-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/25 dark:text-red-300">
                {{ $errors->first('body') }}
            </div>
            @endif

            <form method="POST" action="{{ $updateRoute }}" class="mt-5 space-y-3" data-message-edit-form>
                @csrf @method('PUT')

                <label for="body" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                    {{ __('admin.message_edit_body_label') }}
                </label>
                <textarea name="body" id="body" rows="10" required maxlength="5000"
                          class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-900 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">{{ old('body', $message->body) }}</textarea>

                {{-- La consequence, NOMMEE : les membres verront que le message
                     a ete modifie. L'administrateur doit le savoir avant de
                     cliquer, pas le decouvrir apres. --}}
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('admin.message_edit_visibility_notice') }}</p>

                <div class="flex items-center gap-2 pt-1">
                    <button type="submit"
                            class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-indigo-700">
                        {{ __('admin.message_edit_submit') }}
                    </button>
                    <a href="{{ route('admin.messages', ['filter' => $isLoop ? 'chatloop' : 'exchanges']) }}"
                       class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-700 dark:border-gray-600 dark:text-gray-300">
                        {{ __('admin.message_edit_cancel') }}
                    </a>
                </div>
            </form>
        </div>
    </div>
</x-admin-layout>
