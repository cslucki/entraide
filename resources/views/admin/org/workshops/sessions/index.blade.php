<x-org-admin-layout :title="__('workshops.sessions_title', ['title' => $workshop->title])" :organization="$organization">
    {{-- TASK-1451 — B4-A : les sessions d'un atelier, ecran OrgAdmin minimal. Ni inscrits ni meeting_url. --}}
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('workshops.sessions_title', ['title' => $workshop->title]) }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('workshops.sessions_hint') }}</p>
        </div>
        <div class="flex items-center gap-3">
            <a href="{{ route('organization.admin.workshops', $organization) }}" class="text-sm text-gray-600 dark:text-gray-300 hover:underline">{{ __('workshops.back') }}</a>
            <a href="{{ route('organization.admin.workshops.sessions.create', [$organization, $workshop]) }}" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 text-sm font-medium" data-session-new>{{ __('workshops.session_new') }}</a>
        </div>
    </div>

    @if(session('success'))
        <div class="mb-4 rounded-lg bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 px-4 py-3 text-sm text-emerald-800 dark:text-emerald-200">{{ session('success') }}</div>
    @endif

    @if($rows->isEmpty())
    <p class="text-sm text-gray-500 dark:text-gray-400" data-session-empty>{{ __('workshops.sessions_empty') }}</p>
    @else
    <section class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        @foreach(['status', 'starts_at', 'ends_at', 'timezone', 'location', 'capacity', 'author', 'actions'] as $col)
                        <th class="px-3 py-2 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('workshops.col_'.$col) }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach($rows as $row)
                    <tr data-session-row="{{ $row->id }}" data-session-status="{{ $row->status }}">
                        <td class="px-3 py-2"><span class="px-2 py-0.5 rounded text-xs font-semibold {{ match($row->status) { 'published' => 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-800 dark:text-emerald-300', 'draft' => 'bg-amber-100 dark:bg-amber-900/40 text-amber-800 dark:text-amber-300', default => 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300' } }}">{{ __('workshops.session_status_'.$row->status) }}</span></td>
                        <td class="px-3 py-2 tabular-nums" data-session-start>{{ $row->localStartsAt()->format('d/m/Y H:i') }}</td>
                        <td class="px-3 py-2 tabular-nums">{{ $row->localEndsAt()?->format('d/m/Y H:i') ?? '—' }}</td>
                        <td class="px-3 py-2 text-xs">{{ $row->timezone }}</td>
                        <td class="px-3 py-2 text-xs text-gray-500">{{ $row->location ?? '—' }}</td>
                        <td class="px-3 py-2 text-xs tabular-nums">{{ $row->capacity ?? '—' }}</td>
                        <td class="px-3 py-2 text-xs text-gray-500">{{ $row->author?->name ?? '—' }}</td>
                        <td class="px-3 py-2">
                            <div class="flex flex-wrap items-center gap-2">
                                @unless($row->isCancelled())
                                    <a href="{{ route('organization.admin.workshops.sessions.edit', [$organization, $workshop, $row]) }}" class="text-indigo-600 dark:text-indigo-400 hover:underline text-xs">{{ __('workshops.edit') }}</a>
                                    @if($row->isDraft())
                                        <form method="POST" action="{{ route('organization.admin.workshops.sessions.publish', [$organization, $workshop, $row]) }}">@csrf<button type="submit" class="px-2 py-1 rounded bg-emerald-600 text-white text-xs hover:bg-emerald-700" data-session-publish="{{ $row->id }}">{{ __('workshops.session_publish') }}</button></form>
                                    @endif
                                    <form method="POST" action="{{ route('organization.admin.workshops.sessions.cancel', [$organization, $workshop, $row]) }}" onsubmit="return confirm(@js(__('workshops.session_cancel_confirm')))">@csrf @method('DELETE')<button type="submit" class="px-2 py-1 rounded border border-gray-300 dark:border-gray-600 text-xs text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700" data-session-cancel="{{ $row->id }}">{{ __('workshops.session_cancel') }}</button></form>
                                @else
                                    <span class="text-xs text-gray-400">—</span>
                                @endunless
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
