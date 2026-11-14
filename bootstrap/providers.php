<?php

use App\Providers\AppServiceProvider;
use App\Providers\InterventionImageBindingProvider;
use App\Providers\PasswordResetServiceProvider;
use App\Providers\VersionServiceProvider;

return [
    AppServiceProvider::class,
    PasswordResetServiceProvider::class,
    VersionServiceProvider::class,
    // TASK-1829 — reimpose, dans son `boot()` (qui tourne APRES le
    // `register()` de tous les fournisseurs, position dans cette liste
    // sans importance), la liaison 'image' d'Intervention apres celle,
    // conflictuelle, du composant natif `illuminate/image` de Laravel
    // 13.19+. Voir le docblock de la classe.
    InterventionImageBindingProvider::class,
];
