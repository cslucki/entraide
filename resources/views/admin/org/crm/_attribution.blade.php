{{-- TASK-1459 — Attribution (Mini-CRM V2 §11) : lue depuis les autorites Journey / Shortcut / Guest / Workshop, first touch, meme Organization, aucun transcript. --}}
@if(isset($attribution))
@php $rows = array_filter([
    'source_ref' => $attribution['source_label'],
    'journey' => $attribution['journey'],
    'campaign' => $attribution['campaign'],
    'shortcut' => $attribution['shortcut'] ? '/s/'.$attribution['shortcut'] : null,
    'utm_source' => $attribution['utm_source'],
    'first_touch' => $attribution['first_touch_at']?->format('d/m/Y H:i'),
    'claimed' => $attribution['claimed_at']?->format('d/m/Y H:i'),
]); @endphp
@if($rows !== [] || $attribution['workshops'] !== [])
<div class="mt-4 pt-4 border-t border-gray-100 dark:border-gray-700" data-crm-attribution>
    <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('crm.attribution.title') }}</h3>
    <dl class="mt-2 space-y-1.5 text-sm">
        @foreach($rows as $key => $value)
        <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">{{ __('crm.attribution.'.$key) }}</dt><dd class="text-gray-900 dark:text-gray-100 text-right" data-crm-attribution-{{ str_replace('_', '-', $key) }}>{{ $value }}</dd></div>
        @endforeach
        @foreach($attribution['workshops'] as $workshop)
        <div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">{{ __('crm.attribution.workshop') }}</dt><dd class="text-gray-900 dark:text-gray-100 text-right" data-crm-attribution-workshop>{{ $workshop['title'] }} · {{ $workshop['starts_at'] }}@if($workshop['status'] !== 'registered') · {{ __('workshops.registration_status_'.$workshop['status']) }}@endif</dd></div>
        @endforeach
    </dl>
</div>
@endif
@endif
