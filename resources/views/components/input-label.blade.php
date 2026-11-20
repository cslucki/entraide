@props([
    'value' => null,
    // TASK-1871 — marquer ce qui est OBLIGATOIRE, pas ce qui est facultatif.
    //
    // La regle habituelle est l'inverse : on marque l'optionnel, parce que
    // dans la plupart des formulaires presque tout est exige. Ici c'est le
    // contraire — sur une vingtaine de champs de profil, quatre seulement
    // sont requis. Marquer les quatre est plus econome, et surtout plus
    // honnete : rien n'indiquait jusqu'ici lesquels bloquaient l'envoi.
    //
    // Ajout OPT-IN : les ecrans qui ne passent pas cette prop rendent
    // exactement ce qu'ils rendaient avant, habillage compris. L'habillage
    // d'origine est conserve tel quel, a la demande de MASTER — l'evolution
    // visuelle de T1871 reste au formulaire de profil.
    'required' => false,
])

<label {{ $attributes->merge(['class' => 'block font-medium text-sm text-gray-700 dark:text-gray-300']) }}>
    {{ $value ?? $slot }}
    @if($required)
        {{-- Le mot plutot que l'asterisque : un `*` se devine, il ne se lit
             pas, et il n'est annonce par aucun lecteur d'ecran. --}}
        <span class="ms-1 text-xs font-normal text-gray-500 dark:text-gray-400">{{ __('profile.field_required') }}</span>
    @endif
</label>
