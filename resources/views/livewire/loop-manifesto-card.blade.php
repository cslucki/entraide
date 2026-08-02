<div class="space-y-4">
    <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-800/60">
        <p class="text-[11px] font-semibold uppercase tracking-wide text-violet-600 dark:text-violet-300">{{ __('loops.cards.manifesto.label') }}</p>
        <p class="mt-1 text-sm leading-6 text-gray-600 dark:text-gray-300">{{ __('loops.cards.manifesto.description') }}</p>
    </div>

    @if($errorMessage)
        <div class="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-700/50 dark:bg-amber-900/20 dark:text-amber-200">{{ $errorMessage }}</div>
    @endif

    @if(! $canRead)
        <div class="rounded-2xl border border-dashed border-gray-300 bg-white p-5 text-center text-sm text-gray-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-400">
            {{ __('loops.cards.manifesto.empty_title') }}
        </div>
    @elseif($choosing)
        {{-- Choose an existing article of this Loop --}}
        <div class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
            <div class="flex items-center justify-between gap-2">
                <p class="text-sm font-semibold text-gray-800 dark:text-gray-100">{{ __('loops.manifesto_candidates_title') }}</p>
                <button type="button" wire:click="toggleChoosing" class="text-xs font-medium text-gray-500 transition hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200">{{ __('loops.cancel') }}</button>
            </div>
            @if($candidates->isEmpty())
                <p class="mt-3 text-sm leading-6 text-gray-500 dark:text-gray-400">{{ __('loops.manifesto_no_candidates') }}</p>
            @else
                <ul class="mt-3 space-y-2">
                    @foreach($candidates as $post)
                        <li class="flex items-center gap-3 rounded-xl border border-gray-200 bg-gray-50 px-3 py-2 dark:border-gray-700 dark:bg-gray-800/60">
                            <span class="min-w-0 flex-1 truncate text-sm text-gray-800 dark:text-gray-100">{{ $post->title }}</span>
                            <span class="shrink-0 rounded-full border border-gray-200 bg-white px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-400">{{ $post->status === 'published' ? __('loops.manifesto_published') : __('loops.manifesto_draft') }}</span>
                            <button type="button" wire:click="designate('{{ $post->id }}')" class="shrink-0 rounded-lg bg-violet-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-violet-700">{{ __('loops.manifesto_designate') }}</button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @elseif($manifesto)
        {{-- Designated manifesto --}}
        <div class="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
            <div class="flex items-start justify-between gap-2">
                <h3 class="min-w-0 text-base font-semibold text-gray-900 dark:text-gray-100">{{ $manifesto->title }}</h3>
                <span class="shrink-0 rounded-full border px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide {{ $manifesto->status === 'published' ? 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-800/60 dark:bg-emerald-900/20 dark:text-emerald-300' : 'border-gray-200 bg-gray-50 text-gray-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400' }}">
                    {{ $manifesto->status === 'published' ? __('loops.manifesto_published') : __('loops.manifesto_draft') }}
                </span>
            </div>
            <p class="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-300">{{ \Illuminate\Support\Str::limit(strip_tags((string) ($manifesto->summary ?: $manifesto->content)), 240) }}</p>
            <p class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-gray-400 dark:text-gray-500">
                @if($manifesto->user)<span>{{ __('loops.manifesto_by', ['name' => $manifesto->user->publicDisplayName()]) }}</span>@endif
                <span>{{ __('loops.manifesto_updated', ['date' => $manifesto->updated_at?->isoFormat('D MMM Y')]) }}</span>
                @if($version)<span class="rounded bg-gray-100 px-1.5 py-0.5 font-bold text-gray-500 dark:bg-gray-700 dark:text-gray-300">v{{ $version }}</span>@endif
            </p>
        </div>

        <a href="{{ $editorUrl }}" class="inline-flex w-full items-center justify-center gap-2 rounded-xl border border-gray-200 bg-white px-4 py-2.5 text-sm font-semibold text-gray-800 transition hover:border-violet-200 hover:text-violet-800 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 dark:hover:border-violet-700 dark:hover:text-violet-200">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Z"/></svg>
            {{ __('loops.manifesto_open_in_editor') }}
        </a>

        @if($canManage)
            <div class="flex items-center gap-2">
                <button type="button" wire:click="toggleChoosing" class="inline-flex flex-1 items-center justify-center gap-1.5 rounded-xl border border-gray-200 bg-white px-3 py-2 text-xs font-semibold text-gray-700 transition hover:border-violet-200 hover:text-violet-800 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200 dark:hover:border-violet-700 dark:hover:text-violet-200">
                    {{ __('loops.manifesto_replace') }}
                </button>
                <button type="button" wire:click="removeManifesto" wire:confirm="{{ __('loops.manifesto_remove_confirm') }}" class="inline-flex items-center justify-center gap-1.5 rounded-xl border border-gray-200 bg-white px-3 py-2 text-xs font-semibold text-gray-500 transition hover:border-red-200 hover:text-red-600 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-400 dark:hover:border-red-700 dark:hover:text-red-400">
                    {{ __('loops.manifesto_remove') }}
                </button>
            </div>
        @endif
    @else
        {{-- Empty: no manifesto designated --}}
        <div class="rounded-2xl border border-dashed border-gray-300 bg-white p-6 text-center dark:border-gray-700 dark:bg-gray-900">
            <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-sky-50 text-sky-600 dark:bg-sky-900/30 dark:text-sky-200">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-6a2.25 2.25 0 0 0-.659-1.591l-3-3A2.25 2.25 0 0 0 14.25 3H6.75A2.25 2.25 0 0 0 4.5 5.25v13.5A2.25 2.25 0 0 0 6.75 21h10.5a2.25 2.25 0 0 0 2.25-2.25v-4.5z"/></svg>
            </div>
            <h3 class="mt-4 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('loops.cards.manifesto.empty_title') }}</h3>
            <p class="mx-auto mt-2 max-w-sm text-sm leading-6 text-gray-500 dark:text-gray-400">{{ __('loops.manifesto_pitch') }}</p>
            @if($canManage)
                <div class="mt-5 flex flex-col items-center gap-2">
                    @if($canCreate)
                        <button type="button" wire:click="createManifesto" class="inline-flex items-center justify-center gap-2 rounded-xl bg-violet-600 px-4 py-2 text-xs font-semibold text-white transition hover:bg-violet-700">
                            {{ __('loops.cards.manifesto.action') }}
                        </button>
                    @endif
                    <button type="button" wire:click="toggleChoosing" class="text-xs font-semibold text-violet-600 transition hover:text-violet-800 dark:text-violet-300">{{ __('loops.manifesto_choose_existing') }}</button>
                </div>
            @endif
        </div>
    @endif
</div>
