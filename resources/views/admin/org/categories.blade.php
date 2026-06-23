<x-org-admin-layout :title="__('navigation.org_admin_categories')" :organization="$organization">
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('navigation.org_admin_categories') }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('navigation.org_admin_manage_categories') }}</p>
        </div>
        <a href="{{ route('organization.admin.categories.create', $organization) }}"
           class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700 font-medium">
            + @lang('navigation.org_admin_new_category')
        </a>
    </div>

    <form method="GET" class="mb-5 flex flex-wrap gap-3">
        <input type="text" name="search" value="{{ request('search') }}" placeholder="{{ __('navigation.org_admin_search_category') }}"
            class="flex-1 min-w-48 px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
        <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700">{{ __('navigation.org_admin_filter') }}</button>
        @if(request()->hasAny(['search']))
        <a href="{{ route('organization.admin.categories', $organization) }}" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-600 dark:text-gray-400">{{ __('navigation.org_admin_clear') }}</a>
        @endif
    </form>

    <div class="space-y-4">
        @forelse($categories as $cat)
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="px-5 py-4 flex items-center justify-between border-b border-gray-100 dark:border-gray-700">
                <div class="flex items-center gap-3">
                    <span class="w-4 h-4 rounded-full flex-shrink-0" style="background-color:{{ $cat->color }}"></span>
                    <div>
                        <p class="font-semibold text-gray-900 dark:text-gray-100">{{ $cat->name_b2c }}</p>
                        <p class="text-xs text-gray-500">{{ $cat->name_b2b }} · {{ $cat->services_count }} services · {{ $cat->service_requests_count }} demandes · {{ $cat->skills_count }} compétences</p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <a href="{{ route('organization.admin.categories.edit', [$organization, $cat]) }}" class="text-xs text-indigo-600 hover:underline">@lang('navigation.org_admin_edit')</a>
                    @if($cat->services_count === 0 && $cat->service_requests_count === 0)
                    <form method="POST" action="{{ route('organization.admin.categories.destroy', [$organization, $cat]) }}"
                          onsubmit="return confirm('Supprimer cette catégorie et ses compétences ?')">
                        @csrf @method('DELETE')
                        <button class="text-xs text-red-500 hover:underline">@lang('navigation.org_admin_delete')</button>
                    </form>
                    @endif
                </div>
            </div>

            @php
                $services = array_filter([$cat->service_1, $cat->service_2, $cat->service_3, $cat->service_4, $cat->service_5]);
            @endphp

            @if(!empty($services))
            <div class="px-5 py-2 border-b border-gray-100 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/50">
                <div class="flex flex-wrap gap-1.5">
                    @foreach($services as $svc)
                    <span class="px-2 py-0.5 bg-gray-200 dark:bg-gray-700 text-gray-600 dark:text-gray-400 rounded text-xs">{{ $svc }}</span>
                    @endforeach
                </div>
            </div>
            @endif

            <div class="px-5 py-3">
                <div class="flex flex-wrap gap-2 mb-3">
                    @foreach($cat->skills as $skill)
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-indigo-50 dark:bg-indigo-900/30 text-indigo-700 dark:text-indigo-300 rounded text-xs">
                        {{ $skill->name }}
                        <form method="POST" action="{{ route('organization.admin.skills.destroy', [$organization, $skill]) }}" class="inline"
                              onsubmit="return confirm('Supprimer cette compétence ?')">
                            @csrf @method('DELETE')
                            <button type="submit" class="ml-0.5 text-indigo-400 hover:text-red-500 leading-none">&times;</button>
                        </form>
                    </span>
                    @endforeach
                    @if($cat->skills->isEmpty())
                    <span class="text-xs text-gray-400">Aucune compétence.</span>
                    @endif
                </div>

                <form method="POST" action="{{ route('organization.admin.categories.skills.store', [$organization, $cat]) }}" class="flex gap-2">
                    @csrf
                    <input type="text" name="name" placeholder="Nouvelle compétence..." required
                        class="flex-1 px-3 py-1.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-xs focus:ring-2 focus:ring-indigo-500">
                    <button type="submit" class="px-3 py-1.5 bg-indigo-600 text-white text-xs rounded-lg hover:bg-indigo-700">+</button>
                </form>
            </div>
        </div>
        @empty
        <p class="text-sm text-gray-400">@lang('navigation.org_admin_no_categories')</p>
        @endforelse
    </div>

    @if($categories->hasPages())
    <div class="mt-4">{{ $categories->withQueryString()->links() }}</div>
    @endif
</x-org-admin-layout>
