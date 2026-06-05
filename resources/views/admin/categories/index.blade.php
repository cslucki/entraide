<x-admin-layout title="Catégories">
    <div class="flex items-center justify-between mb-6 gap-4">
        <div>
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $categories->count() }} catégories</p>
            @if($organization)
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                Organisation active : <span class="font-medium text-gray-800 dark:text-gray-200">{{ $organization->name }}</span>
                <span class="font-mono">({{ $organization->slug }} · {{ Str::limit($organization->id, 8, '') }})</span>
            </p>
            @else
            <p class="mt-1 text-xs text-red-600 dark:text-red-400">Aucune organisation active pour cet administrateur.</p>
            @endif
        </div>
        <div class="flex items-center gap-3">
            <form method="GET" action="{{ route('admin.categories') }}" class="flex items-center gap-2">
                <label for="organization_id" class="text-xs font-medium text-gray-500 dark:text-gray-400">Organisation</label>
                <select id="organization_id" name="organization_id" onchange="this.form.submit()"
                    class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
                    @foreach($organizations as $org)
                    <option value="{{ $org->id }}" @selected($organization?->id === $org->id)>
                        {{ $org->name }} ({{ $org->slug }})
                    </option>
                    @endforeach
                </select>
            </form>
            <a href="{{ route('admin.categories.create', ['organization_id' => $organization?->id]) }}" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700 font-medium">
                + Nouvelle catégorie
            </a>
        </div>
    </div>

    <div class="space-y-4">
        @forelse($categories as $cat)
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="px-5 py-4 flex items-center justify-between border-b border-gray-100 dark:border-gray-700">
                <div class="flex items-center gap-3">
                    <span class="w-4 h-4 rounded-full flex-shrink-0" style="background-color:{{ $cat->color }}"></span>
                    <div>
                        <p class="font-semibold text-gray-900 dark:text-gray-100">{{ $cat->name_b2c }}</p>
                        <p class="text-xs text-gray-500">{{ $cat->name_b2b }} · {{ $cat->services_count }} services · {{ $cat->service_requests_count }} demandes · {{ $cat->skills_count }} compétences</p>
                        <p class="mt-1 text-[11px] text-gray-400 dark:text-gray-500">
                            Org : {{ $cat->organization?->name ?? 'inconnue' }}
                            <span class="font-mono">({{ $cat->organization?->slug ?? 'n/a' }} · {{ Str::limit($cat->organization_id, 8, '') }})</span>
                        </p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <a href="{{ route('admin.categories.edit', $cat) }}" class="text-xs text-indigo-600 hover:underline">Modifier</a>
                    @if($cat->services_count === 0 && $cat->service_requests_count === 0)
                    <form method="POST" action="{{ route('admin.categories.destroy', $cat) }}"
                          onsubmit="return confirm('Supprimer cette catégorie et ses compétences ?')">
                        @csrf @method('DELETE')
                        <button class="text-xs text-red-500 hover:underline">Supprimer</button>
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
                        <form method="POST" action="{{ route('admin.skills.destroy', $skill) }}" class="inline"
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

                <form method="POST" action="{{ route('admin.categories.skills.store', $cat) }}" class="flex gap-2">
                    @csrf
                    <input type="text" name="name" placeholder="Nouvelle compétence..." required
                        class="flex-1 px-3 py-1.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-xs focus:ring-2 focus:ring-indigo-500">
                    <button type="submit" class="px-3 py-1.5 bg-indigo-600 text-white text-xs rounded-lg hover:bg-indigo-700">+</button>
                </form>
            </div>
        </div>
        @empty
        <p class="text-sm text-gray-400">Aucune catégorie.</p>
        @endforelse
    </div>
</x-admin-layout>
