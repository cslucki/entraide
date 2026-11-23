<?php

/*
|--------------------------------------------------------------------------
| TASK-1895 — Application Android (Trusted Web Activity)
|--------------------------------------------------------------------------
|
| Servi par `/.well-known/assetlinks.json` (Digital Asset Links). Sans ce
| fichier, l'application Android `com.bouclepro.app` s'ouvre avec une barre
| d'adresse Chrome au lieu du plein ecran, et les liens `https` ne sont pas
| captes par l'application.
|
| `sha256_cert_fingerprints` : empreintes SHA-256 des certificats qui signent
| l'application, separees par des virgules, au format `AA:BB:...` (32
| octets). En pratique :
|   - celle de la clef de signature Google Play (installations Play Store) ;
|   - celle de la clef d'envoi (APK installe a la main, pour la recette).
| Ce sont des donnees PUBLIQUES (elles figurent dans tout APK signe), pas des
| secrets. Aucune valeur par defaut : tant qu'aucune empreinte valide n'est
| posee, la route rend `[]`, qui ne delegue rien a personne.
*/

return [
    'twa' => [
        'package' => env('ANDROID_TWA_PACKAGE', 'com.bouclepro.app'),
        'sha256_cert_fingerprints' => env('ANDROID_TWA_SHA256_CERT_FINGERPRINTS'),
    ],
];
