{{--
    Domain badges (Annuaire referential — Category). Renders nothing at all when
    the Loop carries no domain, so a card never shows an empty row.
    Names come from Category::displayName('loops'), i.e. the Organization's
    loops_naming b2b/b2c setting — same mechanism as Blog and Transactions.
--}}
@props(['loop', 'limit' => 3])
@php
    $domains = $loop->relationLoaded('categories') ? $loop->categories : $loop->categories()->get();
    $domains = $domains->take($limit);
@endphp
@if($domains->isNotEmpty())
    <div {{ $attributes->merge(['class' => 'flex flex-wrap items-center gap-1.5']) }}>
        @foreach($domains as $domain)
            <span class="inline-flex items-center whitespace-nowrap rounded-md bg-gray-100 px-1.5 py-0.5 text-[11px] font-medium text-gray-600 dark:bg-gray-700/70 dark:text-gray-300">
                {{ $domain->displayName('loops') }}
            </span>
        @endforeach
    </div>
@endif
