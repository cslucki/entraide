<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('admin.outils.detail_title') }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 dark:bg-gray-900 text-gray-900 dark:text-gray-100 p-6">
    <h1 class="text-lg font-bold mb-1">{{ __('admin.outils.detail_title') }}</h1>
    @if($orgName)
    <p class="text-sm text-gray-500 dark:text-gray-400 mb-6">{{ __('admin.outils.target_org') }} <strong>{{ $orgName }}</strong></p>
    @else
    <p class="text-sm text-gray-500 dark:text-gray-400 mb-6">{{ __('admin.outils.target_org_default') }}</p>
    @endif

    <div class="mb-6 flex flex-wrap gap-3">
        <a href="{{ route('admin.outils.assign-data.detail', array_merge(request()->query(), ['filter' => 'all'])) }}" class="px-3 py-1 text-xs rounded {{ $filter === 'all' ? 'bg-indigo-600 text-white' : 'bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300' }}">{{ __('admin.outils.filter_all') }}</a>
        @if($organizationId)
        <a href="{{ route('admin.outils.assign-data.detail', array_merge(request()->query(), ['filter' => 'in_org'])) }}" class="px-3 py-1 text-xs rounded {{ $filter === 'in_org' ? 'bg-indigo-600 text-white' : 'bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300' }}">{{ __('admin.outils.detail_filter_in_org') }}</a>
        <a href="{{ route('admin.outils.assign-data.detail', array_merge(request()->query(), ['filter' => 'other_orgs'])) }}" class="px-3 py-1 text-xs rounded {{ $filter === 'other_orgs' ? 'bg-indigo-600 text-white' : 'bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300' }}">{{ __('admin.outils.detail_filter_other_orgs') }}</a>
        @else
        <a href="{{ route('admin.outils.assign-data.detail', array_merge(request()->query(), ['filter' => 'with_org'])) }}" class="px-3 py-1 text-xs rounded {{ $filter === 'with_org' ? 'bg-indigo-600 text-white' : 'bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300' }}">{{ __('admin.outils.detail_filter_with_org') }}</a>
        @endif
        <a href="{{ route('admin.outils.assign-data.detail', array_merge(request()->query(), ['filter' => 'without_org'])) }}" class="px-3 py-1 text-xs rounded {{ $filter === 'without_org' ? 'bg-indigo-600 text-white' : 'bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300' }}">{{ __('admin.outils.detail_filter_without_org') }}</a>
    </div>

    @foreach($previews as $key => $preview)
    <div class="mb-6 bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
        <div class="px-4 py-3 bg-gray-50 dark:bg-gray-700 border-b border-gray-200 dark:border-gray-600 flex items-center justify-between">
            <h2 class="text-sm font-semibold">{{ $preview['label'] }}</h2>
            <span class="text-xs text-gray-500 dark:text-gray-400">
                {{ $preview['displayed'] }} / {{ $preview['total'] }}
                @if($organizationId)
                @if($preview['in_org'] > 0) · {{ $preview['in_org'] }} {{ __('admin.outils.in_org') }} @endif
                @if($preview['other_orgs'] > 0) · {{ $preview['other_orgs'] }} {{ __('admin.outils.other_orgs') }} @endif
                @endif
                @if($preview['without_organization'] > 0) · {{ $preview['without_organization'] }} {{ __('admin.outils.without_org') }} @endif
            </span>
        </div>
        @if(count($preview['rows']) > 0)
        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead>
                    <tr class="bg-gray-50 dark:bg-gray-750">
                        @foreach(array_keys($preview['rows'][0]) as $col)
                        <th class="px-3 py-2 text-left font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide whitespace-nowrap">{{ $col }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach($preview['rows'] as $row)
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-750">
                        @foreach($row as $val)
                        <td class="px-3 py-2 text-gray-700 dark:text-gray-300 whitespace-nowrap max-w-xs truncate" title="{{ is_null($val) ? 'NULL' : (is_array($val) ? json_encode($val) : $val) }}">{{ is_null($val) ? 'NULL' : (is_array($val) ? json_encode($val) : $val) }}</td>
                        @endforeach
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="px-4 py-2 text-xs text-gray-400 border-t border-gray-100 dark:border-gray-700">
            {{ trans_choice('admin.outils.preview_lines', min(5, $preview['total']), ['count' => min(5, $preview['total'])]) }} — {{ __('admin.outils.read_only') }}
        </div>
        @else
        <div class="px-4 py-6 text-center text-sm text-gray-400">{{ __('admin.outils.no_rows') }}</div>
        @endif
    </div>
    @endforeach

    <p class="text-xs text-gray-400 mt-4">{{ __('admin.outils.read_only_notice') }}</p>
</body>
</html>
