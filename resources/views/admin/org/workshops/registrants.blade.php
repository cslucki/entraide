<x-org-admin-layout :title="__('workshops.registrants_title', ['title' => $workshop->title])" :organization="$organization">
    {{-- TASK-1455 (prep) — « Inscrits & interets » d'un atelier : LECTURE SEULE. Inscrits = membres (nom, email, provenance, Contact CRM) ; interets Guest = compteur + pseudonymes (aucune donnee personnelle). --}}
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ __('workshops.registrants_title', ['title' => $workshop->title]) }}</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ __('workshops.registrants_hint') }}</p>
        </div>
        <a href="{{ route('organization.admin.workshops', $organization) }}" class="text-sm text-gray-600 dark:text-gray-300 hover:underline">{{ __('workshops.back') }}</a>
    </div>

    @forelse($sessions as $session)
    @php $rows = $registrations->get($session->id, collect()); $guests = $interests->get($session->id, collect()); $active = $rows->filter(fn ($r) => $r->isRegistered()); @endphp
    <section class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 mb-6" data-registrants-session="{{ $session->id }}" data-registrants-count="{{ $active->count() }}" data-registrants-interests="{{ $guests->count() }}">
        <div class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 border-b border-gray-100 dark:border-gray-700">
            <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">{{ $session->localStartsAt()->format('d/m/Y H:i') }} <span class="text-xs text-gray-400">({{ $session->timezone }}) · {{ __('workshops.session_status_'.$session->status) }}</span></h2>
            <span class="text-xs text-gray-500 dark:text-gray-400">{{ __('workshops.registrants_summary', ['registered' => $active->count(), 'capacity' => $session->capacity ?? '∞', 'interests' => $guests->count()]) }}</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-700">
                    <tr>
                        @foreach(['status', 'member', 'email', 'registered_at', 'provenance', 'contact'] as $col)
                        <th class="px-3 py-2 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('workshops.col_registrant_'.$col) }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @forelse($rows as $row)
                    <tr data-registrant-row="{{ $row->id }}" data-registrant-status="{{ $row->status }}">
                        <td class="px-3 py-2"><span class="px-2 py-0.5 rounded text-xs font-semibold {{ $row->isRegistered() ? 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-800 dark:text-emerald-300' : 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300' }}">{{ __('workshops.registration_status_'.$row->status) }}</span></td>
                        <td class="px-3 py-2 text-gray-900 dark:text-gray-100">{{ $row->user?->name ?? '—' }}</td>
                        <td class="px-3 py-2 text-xs text-gray-500">{{ $row->user?->email ?? '—' }}</td>
                        <td class="px-3 py-2 text-xs tabular-nums">{{ $row->registered_at?->format('d/m/Y H:i') }}</td>
                        <td class="px-3 py-2 text-xs text-gray-500" data-registrant-provenance>{{ $row->journey ? $row->journey->name.' v'.$row->journey->version : '—' }}{{ $row->visitor?->utm_campaign ? ' · '.$row->visitor->utm_campaign : '' }}{{ $row->visitor?->shortcut ? ' · /s/'.$row->visitor->shortcut : '' }}</td>
                        <td class="px-3 py-2 text-xs">
                            @if($row->user_id && $contacts->has($row->user_id))
                                <a href="{{ route('organization.admin.crm.contacts.show', [$organization, $contacts->get($row->user_id)]) }}" class="text-indigo-600 dark:text-indigo-400 hover:underline" data-registrant-contact="{{ $contacts->get($row->user_id)->id }}">{{ __('workshops.registrant_open_contact') }}</a>
                            @else
                                <span class="text-gray-400">—</span>
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="6" class="px-3 py-3 text-xs text-gray-500" data-registrants-empty>{{ __('workshops.registrants_empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($guests->isNotEmpty())
        <p class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400 border-t border-gray-100 dark:border-gray-700" data-registrants-guests>{{ __('workshops.registrants_guests', ['count' => $guests->count()]) }} : {{ $guests->map(fn ($i) => $i->visitor?->pseudonym() ?? '?')->implode(', ') }}</p>
        @endif
    </section>
    @empty
    <p class="text-sm text-gray-500 dark:text-gray-400" data-registrants-no-session>{{ __('workshops.sessions_empty') }}</p>
    @endforelse
</x-org-admin-layout>
