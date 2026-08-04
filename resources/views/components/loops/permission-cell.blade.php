{{--
    One matrix cell.

    Why not a checkbox: unchecked cannot distinguish "inherited, and the
    inherited value happens to be no" from "explicitly denied here". Those two
    behave differently the day the inherited value changes, so the interface has
    to say which one you chose.

    Reading the first version required parsing small grey text under every cell
    to find the handful that had actually been configured. A cell carrying an
    explicit setting is now visibly marked — coloured ring, tinted background, a
    dot and a worded badge — so the configured ones stand out and the inherited
    majority stays quiet. Colour is never the only signal.
--}}
@props(['permission', 'role', 'cell', 'stateLabels', 'scope' => 'global', 'mobile' => false])

@php
    $name = "cells[{$permission['key']}][{$role}]";
    $id = ($mobile ? 'm-' : '').Str::slug($permission['key'].'-'.$role);
    $describedBy = ($mobile ? 'desc-m-' : 'desc-').Str::slug($permission['key']);

    // "Inherited from the global setting" is only true when you are looking at
    // it from an Organization. On the global screen that value is not inherited
    // — it *is* the setting, and calling it inherited is misleading.
    $sourceLabel = match ($cell['source']) {
        'organization' => __('loops.permissions_source_organization'),
        'global' => $scope === 'organization'
            ? __('loops.permissions_source_global')
            : __('loops.permissions_source_global_own'),
        default => __('loops.permissions_source_system'),
    };

    $isConfigured = $cell['state'] !== 'inherited';
    $allowed = $cell['effective'];
@endphp

@if($permission['locked'])
    {{-- Visible, explained, never editable — and the padlock is not the only
         thing saying so. --}}
    <span class="inline-flex items-center gap-1.5 rounded-lg bg-gray-50 px-2 py-1.5 text-xs text-gray-500 dark:bg-gray-900 dark:text-gray-400">
        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z"/></svg>
        <span class="font-medium">{{ $allowed ? $stateLabels['allowed'] : $stateLabels['denied'] }}</span>
        <span class="sr-only">— {{ __('loops.permissions_locked_hint') }}</span>
    </span>
@else
    <fieldset @class([
        'inline-flex flex-col gap-1.5 rounded-xl p-1.5 transition',
        // A configured cell is lifted out of the page; an inherited one stays
        // deliberately quiet so the eye lands on what someone decided.
        'bg-indigo-50/70 ring-1 ring-indigo-300 dark:bg-indigo-900/25 dark:ring-indigo-600' => $isConfigured,
    ])>
        <legend class="sr-only">{{ $permission['label'] }} — {{ __('loops.members_role_'.$role) }}</legend>

        <div class="inline-flex overflow-hidden rounded-lg border border-gray-300 bg-white dark:border-gray-600 dark:bg-gray-900" role="radiogroup" aria-describedby="{{ $describedBy }}">
            @foreach(['inherited', 'allowed', 'denied'] as $state)
                @php
                    $active = match ($state) {
                        'allowed' => 'peer-checked:bg-emerald-600 peer-checked:text-white dark:peer-checked:bg-emerald-500',
                        'denied' => 'peer-checked:bg-rose-600 peer-checked:text-white dark:peer-checked:bg-rose-500',
                        default => 'peer-checked:bg-gray-200 peer-checked:text-gray-900 dark:peer-checked:bg-gray-600 dark:peer-checked:text-gray-50',
                    };
                @endphp
                <label class="cursor-pointer">
                    <input type="radio" name="{{ $name }}" value="{{ $state }}" id="{{ $id }}-{{ $state }}"
                           @checked($cell['state'] === $state)
                           class="peer sr-only">
                    <span class="flex min-h-[44px] items-center justify-center px-2.5 text-xs font-medium text-gray-600 transition {{ $active }} peer-focus-visible:ring-2 peer-focus-visible:ring-indigo-500 peer-focus-visible:ring-offset-1 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-gray-700">
                        {{ $stateLabels[$state] }}
                    </span>
                </label>
            @endforeach
        </div>

        {{-- Effective value, stated plainly rather than buried in grey. --}}
        <span class="flex flex-wrap items-center gap-1 text-[11px] leading-4">
            <span @class([
                'inline-flex items-center gap-1 font-medium',
                'text-emerald-700 dark:text-emerald-400' => $allowed,
                'text-gray-500 dark:text-gray-400' => ! $allowed,
            ])>
                <span @class([
                    'h-1.5 w-1.5 rounded-full',
                    'bg-emerald-500' => $allowed,
                    'bg-gray-400' => ! $allowed,
                ]) aria-hidden="true"></span>
                {{ $allowed ? __('loops.permissions_effective_allowed') : __('loops.permissions_effective_denied') }}
            </span>

            @if($isConfigured)
                <span class="rounded bg-indigo-100 px-1.5 py-0.5 font-semibold text-indigo-700 dark:bg-indigo-900/50 dark:text-indigo-300">
                    {{ $sourceLabel }}
                </span>
            @else
                <span class="text-gray-400 dark:text-gray-500">· {{ $sourceLabel }}</span>
            @endif
        </span>
    </fieldset>
@endif
