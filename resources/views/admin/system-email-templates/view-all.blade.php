<x-admin-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('admin.system_email_view_all_title') }}
        </h2>
    </x-slot>

    {{-- TASK-1894 — tous les gabarits globaux d'une langue sur une page, pour
         comparer leur mise en forme. Lecture seule : rendu de demonstration. --}}
    <div class="py-6" data-system-email-view-all data-locale="{{ $locale }}">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('admin.system_email_view_all_intro') }}</p>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400" data-system-email-view-all-count="{{ $gabarits->count() }}">
                        {{ __('admin.system_email_view_all_count', ['count' => $gabarits->count()]) }}
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    {{-- TASK-1894 — au nom de quelle organisation l'apercu est habille. --}}
                    <form method="GET" class="flex items-center gap-2 text-sm">
                        <input type="hidden" name="locale" value="{{ $locale }}">
                        <label for="vue-organisation" class="text-gray-600 dark:text-gray-400">{{ __('admin.system_email_view_all_as') }}</label>
                        <select id="vue-organisation" name="organisation" onchange="this.form.submit()" data-system-email-view-all-org
                                class="rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300 text-sm py-1.5">
                            @foreach($organisations as $o)
                                <option value="{{ $o->slug }}" @selected($organisation?->id === $o->id)>{{ $o->name }}</option>
                            @endforeach
                        </select>
                    </form>

                    <nav class="inline-flex rounded-lg border border-gray-300 dark:border-gray-600 overflow-hidden text-sm" aria-label="{{ __('admin.locale') }}">
                        @foreach(['fr' => 'Français', 'en' => 'English'] as $code => $libelle)
                            <a href="{{ route('admin.system-email-templates.view-all', ['locale' => $code, 'organisation' => $organisation?->slug]) }}"
                               data-system-email-view-all-locale="{{ $code }}"
                               @if($code === $locale) aria-current="page" @endif
                               class="px-3 py-1.5 {{ $code === $locale ? 'bg-indigo-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700' }}">
                                {{ $libelle }}
                            </a>
                        @endforeach
                    </nav>

                    <a href="{{ route('admin.system-email-templates', ['locale' => $locale]) }}"
                       class="text-sm text-indigo-600 hover:underline dark:text-indigo-400">
                        {{ __('admin.system_email_view_all_back') }}
                    </a>
                </div>
            </div>

            @if($gabarits->isEmpty())
                <p class="py-16 text-center text-sm text-gray-500 dark:text-gray-400" data-system-email-view-all-empty>
                    {{ __('admin.system_email_view_all_empty') }}
                </p>
            @endif

            {{-- Sommaire : 29 gabarits, on y saute directement. --}}
            @if($gabarits->isNotEmpty())
                <nav class="flex flex-wrap gap-2 text-xs">
                    @foreach($gabarits as $item)
                        <a href="#gabarit-{{ $item['gabarit']->slug }}"
                           class="rounded-full bg-gray-100 px-2.5 py-1 text-gray-700 hover:bg-indigo-50 hover:text-indigo-700 dark:bg-gray-700 dark:text-gray-200">
                            {{ $item['gabarit']->name }}
                        </a>
                    @endforeach
                </nav>
            @endif

            @foreach($gabarits as $item)
                @php($gabarit = $item['gabarit'])
                <section id="gabarit-{{ $gabarit->slug }}" class="scroll-mt-20 bg-white dark:bg-gray-800 shadow sm:rounded-lg overflow-hidden"
                         data-system-email-view-all-item="{{ $gabarit->slug }}">
                    <header class="flex flex-wrap items-start justify-between gap-2 border-b border-gray-200 dark:border-gray-700 p-4">
                        <div class="min-w-0">
                            <h3 class="font-semibold text-gray-900 dark:text-gray-100">
                                {{ $gabarit->name }}
                                @unless($gabarit->enabled)
                                    <span class="ml-2 rounded bg-amber-100 px-1.5 py-0.5 text-xs font-medium text-amber-800">{{ __('admin.system_email_view_all_disabled') }}</span>
                                @endunless
                            </h3>
                            <p class="mt-0.5 font-mono text-xs text-gray-500 dark:text-gray-400">{{ $gabarit->slug }}</p>
                            <p class="mt-2 text-sm text-gray-700 dark:text-gray-300">
                                <span class="text-gray-500 dark:text-gray-400">{{ __('admin.system_email_view_all_subject') }} :</span>
                                <span data-system-email-view-all-subject>{{ $item['subject'] }}</span>
                            </p>
                        </div>
                        <a href="{{ route('admin.system-email-templates.edit', $gabarit) }}"
                           class="shrink-0 text-sm text-indigo-600 hover:underline dark:text-indigo-400">
                            {{ __('admin.system_email_view_all_edit') }}
                        </a>
                    </header>

                    {{-- `srcdoc` isole les styles de l'email de ceux de l'admin ;
                         `sandbox` sans scripts : le rendu ne peut rien executer. --}}
                    <iframe srcdoc="{{ $item['html'] }}" sandbox="allow-same-origin" loading="lazy"
                            title="{{ $gabarit->name }}"
                            class="block w-full border-0 bg-gray-50" style="height: 560px"
                            onload="try { this.style.height = (this.contentDocument.documentElement.scrollHeight + 8) + 'px'; } catch (e) {}"></iframe>
                </section>
            @endforeach
        </div>
    </div>
</x-admin-layout>
