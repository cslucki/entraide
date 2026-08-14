{{-- Owner names for a Loop, without ever implying one of them is primary.

     One owner shows a name; two show both; beyond that the first plus a count,
     because a catalogue card has no room for five names and none of them
     outranks the others.

     Expects `owners` to be eager-loaded — this renders inside lists. --}}
@props(['owners', 'fallback' => null])

@php
    $names = $owners->map(fn ($m) => $m->user?->publicDisplayName())->filter()->values();
@endphp

@if($names->isEmpty())
    {{ $fallback ?? '—' }}
@elseif($names->count() <= 2)
    {{ $names->implode(', ') }}
@else
    {{ __('loops.governance_and_others', ['name' => $names->first(), 'count' => $names->count() - 1]) }}
@endif
