<x-admin-layout title="Grand livre des points">
    {{-- TASK-1667 — cet ecran n'existait pas, et son absence rendait un blocage
         de suppression inexplicable : « ce membre a N ecriture(s) au grand livre
         des points », sans aucun moyen de les voir.

         Il est VOLONTAIREMENT en lecture seule : un historique comptable ne se
         supprime pas, et c'est precisement pour cela qu'il bloque. Cet ecran
         explique le blocage ; il ne le leve pas. --}}
    <p class="text-sm text-gray-500 dark:text-gray-400 mb-5">
        {{ __('admin.points_intro') }}
    </p>

    <form method="GET" class="mb-5 flex flex-wrap gap-3">
        {{-- Sans ce report, changer d'organisation ELARGIT la liste en silence. --}}
        @if($filteredUser)
        <input type="hidden" name="user_id" value="{{ request('user_id') }}">
        @endif
        <select name="organization_id" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
            <option value="all" {{ $selectedOrganizationId === 'all' ? 'selected' : '' }}>{{ __('admin.all_organizations') }}</option>
            @foreach($organizations as $org)
            <option value="{{ $org->id }}" {{ $selectedOrganizationId === $org->id ? 'selected' : '' }}>{{ $org->name }}</option>
            @endforeach
        </select>
        <input type="text" name="reason" value="{{ request('reason') }}" placeholder="{{ __('admin.points_reason_placeholder') }}"
               class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 text-sm">
        <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm hover:bg-indigo-700">{{ __('admin.filter') }}</button>
        @if(request()->hasAny(['organization_id', 'reason', 'user_id']))
        <a href="{{ route('admin.points') }}" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-600 dark:text-gray-400">{{ __('admin.clear') }}</a>
        @endif
    </form>

    {{-- Une liste bornee a une personne doit se VOIR : sinon une ecriture se lit
         comme « il n'y en a qu'une sur toute la plateforme ». --}}
    @if($filteredUser)
    <div class="mb-4 flex flex-wrap items-center gap-2 rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm text-indigo-800 dark:border-indigo-800 dark:bg-indigo-900/20 dark:text-indigo-300">
        <span>{{ __('admin.points_filtered_on_user', ['name' => $filteredUser->full_name, 'solde' => $solde]) }}</span>
        <a href="{{ route('admin.points', array_filter(['organization_id' => request('organization_id')])) }}"
           class="font-medium underline">{{ __('admin.points_filtered_clear') }}</a>
    </div>
    @endif

    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('admin.points_col_date') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('admin.points_col_member') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('admin.points_col_delta') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('admin.points_col_reason') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('admin.points_col_organization') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse($entries as $entry)
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-750">
                        <td class="px-4 py-3 text-xs text-gray-500 whitespace-nowrap">{{ $entry->created_at?->format('d/m/Y H:i') ?? '—' }}</td>
                        <td class="px-4 py-3">
                            @if($entry->user)
                            {{-- La Fiche, pas `profile.show` : cette derniere est bornee au
                                 tenant et rend 404 hors organisation courante (TASK-1666). --}}
                            <button type="button"
                                @click="$dispatch('open-user-profile', { id: '{{ $entry->user->id }}', name: @js($entry->user->full_name) })"
                                class="text-indigo-600 hover:underline text-xs text-left">{{ $entry->user->full_name }}</button>
                            @else <span class="text-xs text-gray-400">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <span class="font-medium {{ $entry->delta < 0 ? 'text-red-600 dark:text-red-400' : 'text-emerald-600 dark:text-emerald-400' }}">
                                {{ $entry->delta > 0 ? '+' : '' }}{{ $entry->delta }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-xs text-gray-600 dark:text-gray-400 font-mono">{{ $entry->reason }}</td>
                        <td class="px-4 py-3 text-xs text-gray-600 dark:text-gray-400">{{ $entry->organization?->name ?? '—' }}</td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="5" class="px-4 py-12 text-center text-sm text-gray-500 dark:text-gray-400">
                            {{ __('admin.points_empty') }}
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-4">{{ $entries->links() }}</div>

    @include('admin.partials.user-profile-modal')
</x-admin-layout>
