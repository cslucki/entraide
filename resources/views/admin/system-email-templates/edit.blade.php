<x-admin-layout>
    {{-- TASK-1681 — le slot d'en-tete n'etait jamais FERME (ni le <a>, ni le
         <div>, ni le </x-slot>) : tout le corps de page etait avale par
         l'en-tete, d'ou le titre flottant et la carte ecrasee a droite
         constates en recette. Structure realignee sur index.blade.php. --}}
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
                {{ __('admin.edit_title', ['name' => $systemEmailTemplate->name]) }}
            </h2>
            <a href="{{ request('redirect', route('admin.system-email-templates')) }}"
               class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-md text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">
                {{ __('admin.back_to_list') }}
            </a>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg">
                <div class="p-6">
                    <div class="mb-4 flex gap-4 text-sm text-gray-500 dark:text-gray-400">
                        @if($systemEmailTemplate->organization)
                            <span>{{ __('admin.organization') }} : <strong>{{ $systemEmailTemplate->organization->name }}</strong></span>
                        @else
                            <span class="italic">{{ __('admin.global') }}</span>
                        @endif
                        @if($systemEmailTemplate->locale)
                            <span>{{ __('admin.locale') }} : <strong>{{ strtoupper($systemEmailTemplate->locale) }}</strong></span>
                        @endif
                            <span>{{ __('admin.slug') }} : <strong class="font-mono">{{ $systemEmailTemplate->slug }}</strong></span>
                    </div>

                    <form method="POST" action="{{ route('admin.system-email-templates.update', $systemEmailTemplate) }}" class="space-y-6">
                        @csrf
                        @method('PUT')

                        <input type="hidden" name="redirect" value="{{ request('redirect', route('admin.system-email-templates')) }}">

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('admin.slug') }}</label>
                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400 font-mono">{{ $systemEmailTemplate->slug }}</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('admin.common_name') }}</label>
                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $systemEmailTemplate->name }}</p>
                            </div>
                        </div>

                        <div>
                            <label for="subject" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                {{ __('admin.subject_required') }}
                            </label>
                            <input type="text" name="subject" id="subject" required
                                   class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 @error('subject') border-red-500 @enderror"
                                   value="{{ old('subject', $systemEmailTemplate->subject) }}">
                            @error('subject')
                                <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                @php $varDisplay = collect($systemEmailTemplate->variables ?? [])->map(fn($v) => '{{ '.$v.' }}')->implode(' '); @endphp
                                {{ __('admin.variables_available') }} <code class="text-indigo-600 dark:text-indigo-400">{{ $varDisplay }}</code>
                            </p>
                        </div>

                        <div>
                            <label for="content_html" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                {{ __('admin.html_content_required') }}
                            </label>
                            <textarea name="content_html" id="content_html" rows="15" required
                                      class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 font-mono text-sm @error('content_html') border-red-500 @enderror">{{ old('content_html', $systemEmailTemplate->content_html) }}</textarea>
                            @error('content_html')
                                <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                {{ __('admin.variable_hint') }}
                            </p>
                        </div>

                        {{-- TASK-1809 (micro-sequence C) — apercu FIDELE : rendu
                             REEL cote serveur (meme theme, memes variables
                             interpolees — de demonstration, jamais de donnee
                             reelle), jamais un envoi. Bascule desktop/mobile en
                             largeur d'iframe (aucun round-trip necessaire pour
                             ca), bascule sujet/corps actualises par un appel au
                             nouvel point `system-email-templates.preview`,
                             debounce pour ne pas spammer le serveur a chaque
                             frappe. --}}
                        <div data-html-preview>
                            <div class="flex items-center justify-between">
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                    {{ __('admin.html_preview_title') }}
                                </label>
                                <div class="flex items-center gap-1 rounded-md border border-gray-300 dark:border-gray-600 p-0.5 text-xs">
                                    <button type="button" id="preview_width_desktop"
                                            class="px-2 py-1 rounded bg-indigo-600 text-white">
                                        {{ __('admin.html_preview_desktop') }}
                                    </button>
                                    <button type="button" id="preview_width_mobile"
                                            class="px-2 py-1 rounded text-gray-600 dark:text-gray-300">
                                        {{ __('admin.html_preview_mobile') }}
                                    </button>
                                </div>
                            </div>
                            <p id="content_html_preview_subject" class="mt-1 text-sm font-medium text-gray-700 dark:text-gray-300"></p>
                            <iframe id="content_html_preview" sandbox="" title="{{ __('admin.html_preview_title') }}"
                                    class="mt-1 block rounded-md border border-gray-300 bg-white dark:border-gray-600 mx-auto"
                                    style="height: 480px; width: 100%; max-width: 640px; transition: max-width .15s ease;"></iframe>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                {{ __('admin.html_preview_hint') }}
                            </p>
                        </div>
                        <script>
                            (function () {
                                var source = document.getElementById('content_html');
                                var subjectSource = document.getElementById('subject');
                                var preview = document.getElementById('content_html_preview');
                                var subjectPreview = document.getElementById('content_html_preview_subject');
                                var btnDesktop = document.getElementById('preview_width_desktop');
                                var btnMobile = document.getElementById('preview_width_mobile');
                                var previewUrl = @json(route('admin.system-email-templates.preview', $systemEmailTemplate));
                                var csrfToken = @json(csrf_token());
                                var timer = null;

                                var setWidth = function (max, activeBtn, inactiveBtn) {
                                    preview.style.maxWidth = max;
                                    activeBtn.classList.add('bg-indigo-600', 'text-white');
                                    activeBtn.classList.remove('text-gray-600', 'dark:text-gray-300');
                                    inactiveBtn.classList.remove('bg-indigo-600', 'text-white');
                                    inactiveBtn.classList.add('text-gray-600', 'dark:text-gray-300');
                                };

                                btnDesktop.addEventListener('click', function () { setWidth('640px', btnDesktop, btnMobile); });
                                btnMobile.addEventListener('click', function () { setWidth('375px', btnMobile, btnDesktop); });

                                var render = function () {
                                    fetch(previewUrl, {
                                        method: 'POST',
                                        headers: {
                                            'Content-Type': 'application/json',
                                            'Accept': 'application/json',
                                            'X-CSRF-TOKEN': csrfToken,
                                        },
                                        body: JSON.stringify({
                                            subject: subjectSource.value,
                                            content_html: source.value,
                                        }),
                                    })
                                        .then(function (response) { return response.ok ? response.json() : null; })
                                        .then(function (data) {
                                            if (!data) {
                                                return;
                                            }

                                            subjectPreview.textContent = data.subject;
                                            preview.srcdoc = data.html;
                                        });
                                };

                                var debounced = function () {
                                    clearTimeout(timer);
                                    timer = setTimeout(render, 400);
                                };

                                source.addEventListener('input', debounced);
                                subjectSource.addEventListener('input', debounced);
                                render();
                            })();
                        </script>

                        <div class="flex items-center gap-2">
                            <input type="checkbox" name="enabled" id="enabled" value="1"
                                   class="rounded border-gray-300 dark:border-gray-600 text-indigo-600 shadow-sm focus:ring-indigo-500"
                                   @checked(old('enabled', $systemEmailTemplate->enabled))>
                            <label for="enabled" class="text-sm font-medium text-gray-700 dark:text-gray-300">
                                {{ __('admin.template_active') }}
                            </label>
                        </div>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400 ml-6">
                            {{ __('admin.template_inactive_hint') }}
                        </p>

                        <div class="flex items-center justify-between pt-4 border-t border-gray-200 dark:border-gray-700">
                            <a href="{{ request('redirect', route('admin.system-email-templates')) }}"
                               class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-md text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">
                                {{ __('admin.back_to_list') }}
                            </a>
                            <button type="submit"
                                    class="px-4 py-2 bg-indigo-600 border border-transparent rounded-md text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                                    {{ __('admin.update') }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-admin-layout>
