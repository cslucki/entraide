<x-org-admin-layout :title="__('workshops.title')" :organization="$organization">
    {{-- TASK-1450 — Workshop domain foundation : l'ecran OrgAdmin minimal (liste, brouillon, publication, retrait). Ni sessions ni inscrits (B4). --}}
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('workshops.title') }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('workshops.hint') }}</p>
        </div>
        <a href="{{ route('organization.admin.workshops.create', $organization) }}" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 text-sm font-medium" data-workshop-new>{{ __('workshops.new') }}</a>
    </div>

    @if(session('success'))
        <div class="mb-4 rounded-lg bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 px-4 py-3 text-sm text-emerald-800 dark:text-emerald-200">{{ session('success') }}</div>
    @endif

    @if($rows->isEmpty())
    <p class="text-sm text-gray-500 dark:text-gray-400" data-workshop-empty>{{ __('workshops.empty') }}</p>
    @else
    <section class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        @foreach(['status', 'title', 'slug', 'format', 'duration', 'locale', 'journey', 'funnel', 'author', 'published', 'actions'] as $col)
                        <th class="px-3 py-2 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('workshops.col_'.$col) }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach($rows as $row)
                    <tr data-workshop-row="{{ $row->id }}" data-workshop-status="{{ $row->status }}" data-workshop-slug="{{ $row->slug }}">
                        <td class="px-3 py-2"><span class="px-2 py-0.5 rounded text-xs font-semibold {{ match($row->status) { 'published' => 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-800 dark:text-emerald-300', 'draft' => 'bg-amber-100 dark:bg-amber-900/40 text-amber-800 dark:text-amber-300', default => 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300' } }}">{{ __('workshops.status_'.$row->status) }}</span></td>
                        <td class="px-3 py-2 text-gray-900 dark:text-gray-100">{{ $row->title }}</td>
                        <td class="px-3 py-2 font-mono text-xs text-gray-500">{{ $row->slug }}</td>
                        <td class="px-3 py-2 text-xs">{{ __('workshops.format_'.$row->format) }}</td>
                        <td class="px-3 py-2 text-xs tabular-nums">{{ $row->duration_minutes ?? '—' }}</td>
                        <td class="px-3 py-2 font-mono text-xs">{{ strtoupper($row->locale) }}</td>
                        <td class="px-3 py-2 text-xs text-gray-500">{{ $row->journey ? $row->journey->name.' v'.$row->journey->version : '—' }}{{ $row->journey?->campaign ? ' · '.$row->journey->campaign : '' }}</td>
                        {{-- TASK-1454 : le cockpit — compteurs interets Guest / inscrits confirmes (lecture seule), provenance synthetique. --}}
                        <td class="px-3 py-2 text-xs tabular-nums" data-workshop-funnel="{{ $row->id }}" data-workshop-interests="{{ $row->interests_count }}" data-workshop-registrations="{{ $row->registrations_count }}" data-workshop-published-sessions="{{ $row->published_sessions_count }}">{{ __('workshops.funnel_cell', ['sessions' => $row->published_sessions_count, 'interests' => $row->interests_count, 'registrations' => $row->registrations_count]) }}</td>
                        <td class="px-3 py-2 text-xs text-gray-500">{{ $row->author?->name ?? '—' }}</td>
                        <td class="px-3 py-2 text-xs text-gray-500">{{ $row->published_at?->format('d/m/Y H:i') ?? '—' }}</td>
                        <td class="px-3 py-2">
                            <div class="flex flex-wrap items-center gap-2">
                                <a href="{{ route('organization.admin.workshops.edit', [$organization, $row]) }}" class="text-indigo-600 dark:text-indigo-400 hover:underline text-xs">{{ __('workshops.edit') }}</a>
                                <a href="{{ route('organization.admin.workshops.sessions', [$organization, $row]) }}" class="text-indigo-600 dark:text-indigo-400 hover:underline text-xs" data-workshop-sessions="{{ $row->id }}">{{ __('workshops.sessions_link', ['count' => $row->sessions_count ?? 0]) }}</a>
                                <a href="{{ route('organization.admin.workshops.registrants', [$organization, $row]) }}" class="text-indigo-600 dark:text-indigo-400 hover:underline text-xs" data-workshop-registrants="{{ $row->id }}">{{ __('workshops.registrants_link') }}</a>
                                @if($row->isPublished())
                                    <a href="{{ route('organization.workshop.show', ['organization' => $organization->slug, 'workshop' => $row->slug]) }}" class="text-xs text-gray-600 dark:text-gray-300 hover:underline" data-workshop-public="{{ $row->id }}">{{ __('workshops.view_public') }}</a>
                                    <form method="POST" action="{{ route('organization.admin.workshops.retire', [$organization, $row]) }}" onsubmit="return confirm(@js(__('workshops.retire_confirm')))">@csrf @method('DELETE')<button type="submit" class="px-2 py-1 rounded border border-gray-300 dark:border-gray-600 text-xs text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700" data-workshop-retire="{{ $row->id }}">{{ __('workshops.retire') }}</button></form>
                                @else
                                    <form method="POST" action="{{ route('organization.admin.workshops.publish', [$organization, $row]) }}">@csrf<button type="submit" class="px-2 py-1 rounded bg-emerald-600 text-white text-xs hover:bg-emerald-700" data-workshop-publish="{{ $row->id }}">{{ __('workshops.publish') }}</button></form>
                                @endif
                            </div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
    @endif
</x-org-admin-layout>
