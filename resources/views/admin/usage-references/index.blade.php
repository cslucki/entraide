<x-admin-layout :title="__('admin.usage_reference_title')">
    {{-- TASK-1439 — UsageReference V1 : « a quoi sert cette surface ? » — cure, versionne, publie par un humain. Plateforme-only. --}}
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('admin.usage_reference_title') }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('admin.usage_reference_hint', ['locale' => $platformLocale]) }}</p>
        </div>
        <a href="{{ route('admin.usage-references.create') }}" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 text-sm font-medium" data-usage-reference-new>{{ __('admin.usage_reference_new') }}</a>
    </div>

    @if(session('success'))
        <div class="mb-4 rounded-lg bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 px-4 py-3 text-sm text-emerald-800 dark:text-emerald-200">{{ session('success') }}</div>
    @endif

    @foreach($surfaces as $surface)
    @php $rows = $grouped->get($surface, collect()); @endphp
    <section class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 mb-6" data-usage-reference-surface="{{ $surface }}">
        <div class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 border-b border-gray-100 dark:border-gray-700">
            <div>
                <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">{{ __('admin.usage_reference_surface_'.$surface) }} <code class="text-xs text-gray-400">{{ $surface }}</code></h2>
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    @foreach($locales as $loc)
                        @php $live = $rows->first(fn ($r) => $r->locale === $loc && $r->isPublished()); @endphp
                        <span class="inline-block mr-3" data-usage-reference-live="{{ $surface }}:{{ $loc }}" data-usage-reference-live-version="{{ $live?->version ?? '' }}">{{ strtoupper($loc) }} : {{ $live ? __('admin.usage_reference_live_version', ['version' => $live->version]) : __('admin.usage_reference_none') }}</span>
                    @endforeach
                </p>
            </div>
            <a href="{{ route('admin.usage-references.create', ['surface' => $surface]) }}" class="text-sm text-indigo-600 dark:text-indigo-400 hover:underline">{{ __('admin.usage_reference_new_for_surface') }}</a>
        </div>
        @if($rows->isEmpty())
            <p class="px-4 py-4 text-sm text-gray-500 dark:text-gray-400" data-usage-reference-empty>{{ __('admin.usage_reference_empty_surface') }}</p>
        @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        @foreach(['locale', 'version', 'state', 'reference_title', 'author', 'published', 'actions'] as $col)
                        <th class="px-3 py-2 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('admin.usage_reference_col_'.$col) }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach($rows as $row)
                    <tr data-usage-reference-row="{{ $row->id }}" data-usage-reference-state="{{ $row->state }}" data-usage-reference-version="{{ $row->version }}">
                        <td class="px-3 py-2 font-mono text-xs">{{ strtoupper($row->locale) }}</td>
                        <td class="px-3 py-2 tabular-nums">v{{ $row->version }}</td>
                        <td class="px-3 py-2"><span class="px-2 py-0.5 rounded text-xs font-semibold {{ match($row->state) { 'published' => 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-800 dark:text-emerald-300', 'draft' => 'bg-amber-100 dark:bg-amber-900/40 text-amber-800 dark:text-amber-300', default => 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300' } }}">{{ __('admin.usage_reference_state_'.$row->state) }}</span></td>
                        <td class="px-3 py-2 text-gray-900 dark:text-gray-100">{{ $row->title }}</td>
                        <td class="px-3 py-2 text-xs text-gray-500">{{ $row->author?->name ?? __('admin.usage_reference_author_system') }}</td>
                        <td class="px-3 py-2 text-xs text-gray-500">{{ $row->published_at?->format('d/m/Y H:i') ?? '—' }}</td>
                        <td class="px-3 py-2">
                            <div class="flex flex-wrap items-center gap-2">
                                @if($row->isDraft())
                                    <a href="{{ route('admin.usage-references.edit', $row) }}" class="text-indigo-600 dark:text-indigo-400 hover:underline text-xs">{{ __('admin.usage_reference_edit') }}</a>
                                    <form method="POST" action="{{ route('admin.usage-references.publish', $row) }}">@csrf<button type="submit" class="px-2 py-1 rounded bg-emerald-600 text-white text-xs hover:bg-emerald-700" data-usage-reference-publish="{{ $row->id }}">{{ __('admin.usage_reference_publish') }}</button></form>
                                @elseif($row->isPublished())
                                    <form method="POST" action="{{ route('admin.usage-references.retire', $row) }}" onsubmit="return confirm(@js(__('admin.usage_reference_retire_confirm')))">@csrf @method('DELETE')<button type="submit" class="px-2 py-1 rounded border border-gray-300 dark:border-gray-600 text-xs text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700" data-usage-reference-retire="{{ $row->id }}">{{ __('admin.usage_reference_retire') }}</button></form>
                                @else
                                    <span class="text-xs text-gray-400">—</span>
                                @endif
                            </div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </section>
    @endforeach
    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('admin.usage_reference_footer', ['max' => $maxChars]) }}</p>
</x-admin-layout>
