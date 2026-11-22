{{--
    TASK-1879 (design) — pastille de categorie de veille : un point de
    couleur STABLE (LoopWatchCategory::teinte()) + le nom. La meme dans la
    Card, l'annonce ChatLoop et le Flux. Categorie archivee : gris.
--}}
@props(['category' => null, 'nom' => true])
@php
    $palette = [
        ['dot' => 'bg-sky-500', 'text' => 'text-sky-700 dark:text-sky-300'],
        ['dot' => 'bg-violet-500', 'text' => 'text-violet-700 dark:text-violet-300'],
        ['dot' => 'bg-emerald-500', 'text' => 'text-emerald-700 dark:text-emerald-300'],
        ['dot' => 'bg-amber-500', 'text' => 'text-amber-700 dark:text-amber-300'],
        ['dot' => 'bg-rose-500', 'text' => 'text-rose-700 dark:text-rose-300'],
        ['dot' => 'bg-teal-500', 'text' => 'text-teal-700 dark:text-teal-300'],
    ];
    $teinte = $category && ! $category->isArchived()
        ? $palette[$category->teinte()]
        : ['dot' => 'bg-gray-400', 'text' => 'text-gray-500 dark:text-gray-400'];
@endphp
@if($category)
    <span {{ $attributes->merge(['class' => 'inline-flex min-w-0 items-center gap-1.5 font-medium '.$teinte['text']]) }} data-watch-category-chip>
        <span class="h-2 w-2 shrink-0 rounded-full {{ $teinte['dot'] }}" aria-hidden="true"></span>
        @if($nom)<span class="truncate">{{ $category->name }}</span>@endif
    </span>
@endif
