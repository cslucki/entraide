<x-org-admin-layout :title="__('acquisition.title')" :organization="$organization">
    {{-- TASK-1446 — AcquisitionJourney foundation : l'ecran OrgAdmin minimal (liste, brouillon, publication, retrait). Pas d'analytics. --}}
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('acquisition.title') }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('acquisition.hint') }}</p>
        </div>
        <a href="{{ route('organization.admin.acquisition.create', $organization) }}" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 text-sm font-medium" data-acquisition-new>{{ __('acquisition.new') }}</a>
    </div>

    @if(session('success'))
        <div class="mb-4 rounded-lg bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 px-4 py-3 text-sm text-emerald-800 dark:text-emerald-200">{{ session('success') }}</div>
    @endif

    @forelse($grouped as $key => $rows)
    @php $live = $rows->first(fn ($r) => $r->isPublished()); @endphp
    <section class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 mb-6" data-acquisition-journey="{{ $key }}" data-acquisition-live-version="{{ $live?->version ?? '' }}">
        <div class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 border-b border-gray-100 dark:border-gray-700">
            <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">{{ $rows->first()->name }} <code class="text-xs text-gray-400">{{ $key }}</code></h2>
            <span class="text-xs text-gray-500 dark:text-gray-400">{{ $live ? __('acquisition.live_version', ['version' => $live->version]) : __('acquisition.no_live_version') }}</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        @foreach(['version', 'state', 'name', 'locale', 'goal', 'campaign', 'author', 'published', 'actions'] as $col)
                        <th class="px-3 py-2 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('acquisition.col_'.$col) }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach($rows as $row)
                    <tr data-acquisition-row="{{ $row->id }}" data-acquisition-state="{{ $row->state }}" data-acquisition-version="{{ $row->version }}">
                        <td class="px-3 py-2 tabular-nums">v{{ $row->version }}</td>
                        <td class="px-3 py-2"><span class="px-2 py-0.5 rounded text-xs font-semibold {{ match($row->state) { 'published' => 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-800 dark:text-emerald-300', 'draft' => 'bg-amber-100 dark:bg-amber-900/40 text-amber-800 dark:text-amber-300', default => 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300' } }}">{{ __('acquisition.state_'.$row->state) }}</span></td>
                        <td class="px-3 py-2 text-gray-900 dark:text-gray-100">{{ $row->name }}</td>
                        <td class="px-3 py-2 font-mono text-xs">{{ strtoupper($row->locale) }}</td>
                        <td class="px-3 py-2 text-xs">{{ __('acquisition.goal_'.$row->conversion_goal) }}</td>
                        <td class="px-3 py-2 text-xs text-gray-500">{{ $row->campaign ?? '—' }}</td>
                        <td class="px-3 py-2 text-xs text-gray-500">{{ $row->author?->name ?? '—' }}</td>
                        <td class="px-3 py-2 text-xs text-gray-500">{{ $row->published_at?->format('d/m/Y H:i') ?? '—' }}</td>
                        <td class="px-3 py-2">
                            <div class="flex flex-wrap items-center gap-2">
                                @if($row->isDraft())
                                    <a href="{{ route('organization.admin.acquisition.edit', [$organization, $row]) }}" class="text-indigo-600 dark:text-indigo-400 hover:underline text-xs">{{ __('acquisition.edit') }}</a>
                                    <form method="POST" action="{{ route('organization.admin.acquisition.publish', [$organization, $row]) }}">@csrf<button type="submit" class="px-2 py-1 rounded bg-emerald-600 text-white text-xs hover:bg-emerald-700" data-acquisition-publish="{{ $row->id }}">{{ __('acquisition.publish') }}</button></form>
                                @elseif($row->isPublished())
                                    <form method="POST" action="{{ route('organization.admin.acquisition.retire', [$organization, $row]) }}" onsubmit="return confirm(@js(__('acquisition.retire_confirm')))">@csrf @method('DELETE')<button type="submit" class="px-2 py-1 rounded border border-gray-300 dark:border-gray-600 text-xs text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700" data-acquisition-retire="{{ $row->id }}">{{ __('acquisition.retire') }}</button></form>
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
    </section>
    @empty
    <p class="text-sm text-gray-500 dark:text-gray-400" data-acquisition-empty>{{ __('acquisition.empty') }}</p>
    @endforelse
</x-org-admin-layout>
