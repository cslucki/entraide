<x-app-layout>
    @php $organizationRouteParam = request()->route('organization'); @endphp

    <x-slot name="title">{{ __('dossiers.edit_title') }} — {{ $brandOrganizationName ?? 'BouclePro' }}</x-slot>

    <x-page-container>
        <div class="mx-auto max-w-2xl">
            <a href="{{ route('organization.dossiers.index', ['organization' => $organizationRouteParam]) }}" class="text-sm font-medium text-indigo-600 hover:underline dark:text-indigo-400">{{ __('dossiers.back') }}</a>

            <div class="mt-5 rounded-3xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800 sm:p-8">
                <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('dossiers.edit_title') }}</h1>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ __('dossiers.edit_help') }}</p>

                <form method="POST"
                      action="{{ route('organization.dossiers.update', ['organization' => $organizationRouteParam, 'dossier' => $dossier->getKey()]) }}"
                      class="mt-6 space-y-5"
                      x-data="{
                          initial: @js($dossier->visibility),
                          choice: @js(old('visibility', $dossier->visibility)),
                          loop: @js(old('shared_with_loop_id', $dossier->shared_with_loop_id)),
                          pending: false,
                          get changed() { return this.choice !== this.initial },
                      }">
                    @csrf
                    @method('PATCH')
                    <div>
                        <x-input-label for="name" :value="__('dossiers.name_label')" />
                        <x-text-input id="name" name="name" type="text" class="mt-1 block w-full" :value="old('name', $dossier->name)" required autofocus maxlength="120" />
                        <x-input-error :messages="$errors->get('name')" class="mt-2" />
                        <x-input-error :messages="$errors->get('owner_id')" class="mt-2" />
                    </div>

                    @if($dossier->visibilityIsInherited())
                        {{-- A root Dossier has no audience of its own. No
                             control is offered, and the server refuses one
                             anyway. --}}
                        <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 dark:border-gray-700 dark:bg-gray-800/60">
                            <p class="text-sm font-medium text-gray-800 dark:text-gray-100">
                                {{ __('dossiers.visibility_shared_in_loop', ['loop' => $dossier->loop?->name]) }}
                            </p>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('dossiers.visibility_inherited') }}</p>
                        </div>
                    @else
                        <div>
                            <x-input-label :value="__('dossiers.visibility_label')" />
                            <div class="mt-2 space-y-2">
                                @foreach([
                                    \App\Models\Dossier::VISIBILITY_PRIVATE => 'dossiers.visibility_private_help',
                                    \App\Models\Dossier::VISIBILITY_ORGANIZATION => 'dossiers.visibility_organization_help',
                                    \App\Models\Dossier::VISIBILITY_LOOP => 'dossiers.visibility_loop_help',
                                ] as $value => $helpKey)
                                    <label class="flex cursor-pointer items-start gap-2.5 rounded-xl border border-gray-200 p-3 transition has-[:checked]:border-indigo-500 has-[:checked]:bg-indigo-50/70 dark:border-gray-600 dark:has-[:checked]:bg-indigo-900/25">
                                        <input type="radio" name="visibility" value="{{ $value }}" x-model="choice"
                                               class="mt-0.5 h-4 w-4 border-gray-300 text-indigo-600 focus:ring-indigo-500 dark:border-gray-500 dark:bg-gray-900">
                                        <span class="min-w-0">
                                            <span class="block text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('dossiers.visibility_'.$value) }}</span>
                                            <span class="mt-0.5 block text-xs text-gray-500 dark:text-gray-400">{{ __($helpKey) }}</span>
                                        </span>
                                    </label>
                                @endforeach
                            </div>

                            <div x-show="choice === 'loop'" x-cloak class="mt-3">
                                <x-input-label for="shared_with_loop_id" :value="__('dossiers.visibility_loop_select')" />
                                <select id="shared_with_loop_id" name="shared_with_loop_id" x-model="loop"
                                        class="mt-1 block w-full rounded-lg border-gray-300 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                                    <option value="">{{ __('dossiers.visibility_loop_placeholder') }}</option>
                                    {{-- La variable d'iteration ne s'appelle PAS
                                         `$loop` : Blade reserve ce nom a l'objet
                                         de boucle et l'ecrase a chaque tour. Le
                                         `<option>` lisait donc `id` sur un
                                         stdClass — 500 des qu'une Boucle etait
                                         offerte. Bug reste invisible tant que la
                                         liste etait vide (elle l'etait toujours,
                                         faute d'`organization_id` selectionne). --}}
                                    @foreach($shareableLoops as $boucle)
                                        <option value="{{ $boucle->id }}">
                                            {{ $boucle->name }} — {{ $boucle->visibility === 'private' ? __('dossiers.loop_private') : __('dossiers.loop_wider') }}
                                        </option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('shared_with_loop_id')" class="mt-2" />
                            </div>

                            <x-input-error :messages="$errors->get('visibility')" class="mt-2" />

                            {{-- Widening is never silent. An Alpine modal, never
                                 window.confirm(). Cancelling mutates nothing. --}}
                            <div x-show="pending" x-cloak
                                 class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/50 p-4"
                                 x-on:keydown.escape.window="pending = false">
                                <div class="w-full max-w-md rounded-2xl bg-white p-5 shadow-xl dark:bg-gray-800" role="dialog" aria-modal="true">
                                    <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">{{ __('dossiers.visibility_confirm_title') }}</h3>
                                    <p class="mt-3 text-sm leading-6 text-gray-600 dark:text-gray-300"
                                       x-text="choice === 'organization'
                                            ? @js(__('dossiers.visibility_warn_to_organization'))
                                            : (choice === 'loop'
                                                ? @js(__('dossiers.visibility_warn_to_loop'))
                                                : @js(__('dossiers.visibility_warn_narrowing')))"></p>
                                    <div class="mt-5 flex justify-end gap-2">
                                        <button type="button" @click="pending = false"
                                                class="min-h-[44px] rounded-xl border border-gray-300 px-4 text-sm font-medium text-gray-600 dark:border-gray-600 dark:text-gray-300">
                                            {{ __('dossiers.cancel') }}
                                        </button>
                                        <button type="button" @click="pending = false; $el.closest('form').submit()"
                                                class="min-h-[44px] rounded-xl bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700">
                                            {{ __('dossiers.visibility_confirm') }}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endif

                    <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                        <a href="{{ route('organization.dossiers.index', ['organization' => $organizationRouteParam]) }}" class="inline-flex justify-center rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">{{ __('dossiers.cancel') }}</a>
                        @if($dossier->visibilityIsInherited())
                            <x-primary-button>{{ __('dossiers.save') }}</x-primary-button>
                        @else
                            <x-primary-button x-bind:type="changed ? 'button' : 'submit'"
                                              @click="if (changed) { $event.preventDefault(); pending = true }">
                                {{ __('dossiers.save') }}
                            </x-primary-button>
                        @endif
                    </div>
                </form>
            </div>
        </div>
    </x-page-container>
</x-app-layout>
