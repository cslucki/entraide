{{-- Security preconditions, shown so the picture is complete and never as
     editable cells. Nothing here is a permission, and nothing can be stored. --}}
@props(['invariants'])

<section class="mt-8 rounded-2xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-800/60">
    <h2 class="text-sm font-semibold text-gray-800 dark:text-gray-100">{{ __('loops.permissions_invariants_title') }}</h2>
    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ __('loops.permissions_invariants_intro') }}</p>
    <ul class="mt-3 space-y-1.5">
        @foreach($invariants as $key => $description)
            <li class="flex items-start gap-2 text-xs text-gray-600 dark:text-gray-300">
                <svg class="mt-0.5 h-3.5 w-3.5 shrink-0 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z"/></svg>
                <span>{{ $description }}</span>
            </li>
        @endforeach
    </ul>
</section>
