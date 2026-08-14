{{--
    Le visage d'une personne, ou ses initiales.

    `User::$avatar_url` retombe sur ui-avatars.com quand la personne n'a pas
    d'image : chaque « avatar » etait donc un appel a un tiers, a qui on
    envoyait au passage le nom de la personne. Les initiales se dessinent tres
    bien ici, sans reseau et sans fuite.

    Une personne desactivee n'a ni visage ni nom : `publicDisplayName()` renvoie
    deja un libelle neutre, on ne montre donc que le point d'interrogation.
--}}
@props([
    'user' => null,
    'size' => 'md',
])

@php
    $sizes = [
        'xs' => ['box' => 'h-6 w-6', 'text' => 'text-[9px]'],
        'sm' => ['box' => 'h-7 w-7', 'text' => 'text-[10px]'],
        'md' => ['box' => 'h-9 w-9', 'text' => 'text-xs'],
        'lg' => ['box' => 'h-11 w-11', 'text' => 'text-sm'],
    ];
    $dim = $sizes[$size] ?? $sizes['md'];

    $deactivated = $user?->isDeactivated() ?? false;
    // `avatar` et non `avatar_url` : seul le premier dit si une image a
    // reellement ete deposee.
    $hasImage = ! $deactivated && filled($user?->avatar);
    $initials = $deactivated ? '?' : ($user?->initials ?: '?');
    $label = $user?->publicDisplayName() ?? '—';
@endphp

@if($hasImage)
    <img src="{{ $user->avatar_url }}"
         alt=""
         aria-hidden="true"
         {{ $attributes->class([$dim['box'], 'shrink-0 rounded-full object-cover']) }}>
@else
    <span aria-hidden="true"
          title="{{ $label }}"
          {{ $attributes->class([
              $dim['box'],
              $dim['text'],
              'inline-flex shrink-0 items-center justify-center rounded-full bg-[var(--bp-primary)]/12 font-bold uppercase leading-none text-[var(--bp-primary-deep)] dark:bg-[var(--bp-primary)]/25 dark:text-white',
          ]) }}>{{ $initials }}</span>
@endif
