@props([
    'value' => null,
    // TASK-1871 — marquer ce qui est OBLIGATOIRE, pas ce qui est facultatif.
    //
    // La regle habituelle est l'inverse : on marque l'optionnel, parce que
    // dans la plupart des formulaires presque tout est exige. Ici c'est le
    // contraire — sur une vingtaine de champs de profil, quatre seulement
    // sont requis. Marquer les quatre est plus econome, et surtout plus
    // honnete : rien n'indiquait jusqu'ici lesquels bloquaient l'envoi.
    'required' => false,
])

<label {{ $attributes->merge(['class' => 'block text-sm font-medium text-[color:var(--bp-text)]']) }}>
    {{ $value ?? $slot }}
    @if($required)
        {{-- Le mot plutot que l'asterisque : un `*` se devine, il ne se lit
             pas, et il n'est annonce par aucun lecteur d'ecran. --}}
        <span class="ms-1 text-xs font-normal text-[color:var(--bp-muted)]">{{ __('profile.field_required') }}</span>
    @endif
</label>
