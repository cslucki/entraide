{{-- TASK-1788 correctif 11 (demande Cyril) — sur QUOI la notification a ete
     delivree.

     ⚠️ `in_app` n'a PAS de ligne de livraison, et ce n'est pas un oubli : la
     notification EST la livraison en application (lecon T1786). La pastille
     « App » est donc TOUJOURS posee, par construction — la deduire des
     livraisons donnerait toujours faux.

     Email et PWA, eux, viennent des lignes reelles, AVEC leur statut : une
     livraison `skipped_preference` n'est pas une livraison, et une pastille
     verte sur un envoi qui n'a jamais eu lieu serait un mensonge d'ecran. Le
     statut exact est dans l'infobulle. --}}
@props(['notification'])
@php
    $pastille = function (string $statut): string {
        return match (true) {
            $statut === 'sent' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-200',
            str_starts_with($statut, 'skipped') => 'bg-gray-100 text-gray-500 line-through dark:bg-gray-800 dark:text-gray-400',
            $statut === 'failed' => 'bg-red-50 text-red-700 dark:bg-red-900/30 dark:text-red-200',
            default => 'bg-amber-50 text-amber-700 dark:bg-amber-900/30 dark:text-amber-200',
        };
    };
    $libelles = [
        'email' => __('admin.notifications_channel_email'),
        'web_push' => __('admin.notifications_channel_web_push'),
    ];
@endphp
<div class="flex flex-wrap items-center gap-1" data-marqueur="canaux">
    <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-200"
          title="{{ __('admin.notifications_channel_in_app_hint') }}"
          data-canal="in_app">{{ __('admin.notifications_channel_in_app') }}</span>

    @foreach($notification->deliveries as $livraison)
        <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $pastille($livraison->status) }}"
              title="{{ $livraison->status }}"
              data-canal="{{ $livraison->channel }}"
              data-statut="{{ $livraison->status }}">{{ $libelles[$livraison->channel] ?? $livraison->channel }}</span>
    @endforeach
</div>
