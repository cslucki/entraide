<?php

return [
    'min' => [
        'string' => 'Le champ :attribute doit contenir au moins :min caractères.',
        'numeric' => 'La valeur de :attribute doit être au moins de :min.',
    ],
    'max' => [
        'string' => 'Le champ :attribute ne peut pas dépasser :max caractères.',
        'numeric' => 'La valeur de :attribute ne peut pas dépasser :max.',
    ],
    'required' => 'Le champ :attribute est obligatoire.',
    'email' => 'L\'adresse e-mail doit être une adresse valide.',
    'unique' => 'Cette valeur est déjà utilisée.',
    'confirmed' => 'La confirmation ne correspond pas.',
    'attributes' => [
        'title' => 'titre',
        'description' => 'description',
        'points_cost' => 'points demandés',
        'budget_min' => 'budget minimum',
        'budget_max' => 'budget maximum',
        'phone' => 'numéro de téléphone',
    ],
    'custom' => [
        'points_cost' => [
            'min' => 'Le service doit coûter au moins :min points.',
            'max' => 'Le service ne peut pas coûter plus de :max points.',
        ],
        'budget_min' => [
            'min' => 'Le budget doit être d\'au moins :min points.',
        ],
        'budget_max' => [
            'min' => 'Le budget maximum doit être d\'au moins :min points.',
        ],
    ],
];
