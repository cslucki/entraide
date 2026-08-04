{{--
    One matrix cell: three segmented states plus, textually, where the effective
    value comes from.

    Why not a checkbox: unchecked cannot distinguish "inherited, and the
    inherited value happens to be no" from "explicitly denied here". Those two
    behave differently the day the inherited value changes, so the interface has
    to say which one you chose.

    Provenance is written out, never conveyed by colour or icon alone.
--}}
@props(['permission', 'role', 'cell', 'stateLabels', 'scope' => 'global', 'mobile' => false])

@php
    $name = "cells[{$permission['key']}][{$role}]";
    $id = ($mobile ? 'm-' : '').Str::slug($permission['key'].'-'.$role);
    $describedBy = ($mobile ? 'desc-m-' : 'desc-').Str::slug($permission['key']);

    // "Inherited from the global setting" is only true when you are looking at
    // it from an Organization. On the global screen itself that value is not
    // inherited — it *is* the setting, and calling it inherited is misleading.
    $sourceLabel = match ($cell['source']) {
        'organization' => __('loops.permissions_source_organization'),
        'global' => $scope === 'organization'
            ? __('loops.permissions_source_global')
            : __('loops.permissions_source_global_own'),
        default => __('loops.permissions_source_system'),
    };
@endphp

@if($permission['locked'])
    {{-- Visible, explained, never editable — and the padlock is not the only
         thing saying so. --}}
    <span class="inline-flex items-center gap-1.5 rounded-lg bg-gray-50 px-2 py-1 text-xs text-gray-500 dark:bg-gray-900 dark:text-gray-400">
        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z"/></svg>
        {{ $cell['effective'] ? $stateLabels['allowed'] : $stateLabels['denied'] }}
        <span class="sr-only">— {{ __('loops.permissions_locked_hint') }}</span>
    </span>
@else
    <fieldset class="inline-flex flex-col gap-1">
        <legend class="sr-only">{{ $permission['label'] }} — {{ __('loops.members_role_'.$role) }}</legend>

        <div class="inline-flex overflow-hidden rounded-lg border border-gray-300 dark:border-gray-600" role="radiogroup" aria-describedby="{{ $describedBy }}">
            @foreach(['inherited', 'allowed', 'denied'] as $state)
                <label class="relative cursor-pointer">
                    <input type="radio" name="{{ $name }}" value="{{ $state }}" id="{{ $id }}-{{ $state }}"
                           @checked($cell['state'] === $state)
                           class="peer sr-only">
                    <span class="flex min-h-[44px] items-center justify-center px-2.5 text-xs font-medium text-gray-600 transition peer-checked:bg-indigo-600 peer-checked:text-white peer-focus-visible:ring-2 peer-focus-visible:ring-indigo-500 peer-focus-visible:ring-offset-1 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-gray-700 dark:peer-checked:bg-indigo-500">
                        {{ $stateLabels[$state] }}
                    </span>
                </label>
            @endforeach
        </div>

        {{-- Effective value and its origin, in words. --}}
        <span class="text-[11px] text-gray-400 dark:text-gray-500">
            {{ $cell['effective'] ? __('loops.permissions_effective_allowed') : __('loops.permissions_effective_denied') }}
            · {{ $sourceLabel }}
        </span>
    </fieldset>
@endif
