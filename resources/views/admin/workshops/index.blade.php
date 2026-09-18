<x-admin-layout :title="__('admin.workshops_title')">
    {{-- TASK-1456 (prep) — SuperAdmin Workshops : vue transversale LECTURE SEULE (V3 §14). Toute mutation passe par l'OrgAdmin de l'Organization explicite. --}}
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('admin.workshops_title') }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('admin.workshops_hint') }}</p>
        </div>
        <form method="GET" class="flex items-center gap-2" data-admin-workshops-filter>
            <select name="organization" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm" onchange="this.form.submit()">
                <option value="">{{ __('admin.workshops_all_organizations') }}</option>
                @foreach($organizations as $org)<option value="{{ $org->id }}" @selected($selected?->id === $org->id)>{{ $org->name }} ({{ $org->slug }})</option>@endforeach
            </select>
        </form>
    </div>

    @if($workshops->isEmpty())
    <p class="text-sm text-gray-500 dark:text-gray-400" data-admin-workshops-empty>{{ __('admin.workshops_empty') }}</p>
    @else
    <section class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        @foreach(['organization', 'title', 'status', 'journey', 'sessions', 'interests', 'registrations', 'conversions', 'actions'] as $col)
                        <th class="px-3 py-2 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('admin.workshops_col_'.$col) }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach($workshops as $row)
                    <tr data-admin-workshop="{{ $row->id }}" data-admin-workshop-organization="{{ $row->organization_id }}" data-admin-workshop-registrations="{{ $row->registrations_count }}" data-admin-workshop-interests="{{ $interests[$row->id] ?? 0 }}">
                        <td class="px-3 py-2 text-xs">{{ $row->organization?->name }} <code class="text-gray-400">{{ $row->organization?->slug }}</code></td>
                        <td class="px-3 py-2 text-gray-900 dark:text-gray-100">{{ $row->title }} <code class="text-xs text-gray-400">{{ $row->slug }}</code></td>
                        <td class="px-3 py-2 text-xs">{{ __('workshops.status_'.$row->status) }}</td>
                        <td class="px-3 py-2 text-xs text-gray-500">{{ $row->journey ? $row->journey->name.' v'.$row->journey->version : '—' }}</td>
                        <td class="px-3 py-2 text-xs tabular-nums">{{ $row->sessions_count }} @if(($sessions[$row->id] ?? collect())->isNotEmpty())<span class="text-gray-400">({{ ($sessions[$row->id])->where('status', 'published')->count() }} {{ __('workshops.session_status_published') }})</span>@endif</td>
                        <td class="px-3 py-2 text-xs tabular-nums">{{ $interests[$row->id] ?? 0 }}</td>
                        <td class="px-3 py-2 text-xs tabular-nums">{{ $row->registrations_count }}</td>
                        <td class="px-3 py-2 text-xs tabular-nums">{{ $conversions[$row->organization_id] ?? 0 }}</td>
                        <td class="px-3 py-2 text-xs">
                            @if($row->organization)
                                <a href="{{ route('organization.admin.workshops.registrants', [$row->organization, $row]) }}" class="text-indigo-600 dark:text-indigo-400 hover:underline" data-admin-workshop-open="{{ $row->id }}">{{ __('admin.workshops_open') }}</a>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
    @endif
</x-admin-layout>
