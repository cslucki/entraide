<?php

use App\Http\Controllers\Auth\AccountApprovalPromptController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('register', [RegisteredUserController::class, 'create'])
        ->name('register');

    Route::post('register', [RegisteredUserController::class, 'store']);

    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])
        ->name('password.request');

    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->name('password.email');

    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])
        ->name('password.reset');

    Route::post('reset-password', [NewPasswordController::class, 'store'])
        ->name('password.store');
});

Route::middleware('auth')->group(function () {
    Route::get('verify-email', EmailVerificationPromptController::class)
        ->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    // TASK-1714 volet 2 — l'ecran d'un compte en attente de validation.
    //
    // Route GLOBALE, comme `verification.notice` : l'attente concerne le
    // COMPTE, pas la surface d'ou il vient. Une route par Organization
    // obligerait la garde a en choisir une, et elle n'a pas toujours le
    // contexte pour le faire.
    Route::get('approbation-en-attente', AccountApprovalPromptController::class)
        ->name('approval.notice');

    // TASK-1716 — la fermeture volontaire du compte, route GLOBALE et UNIQUE.
    //
    // Ici, et pas dans `routes/web.php` a cote des autres routes de profil,
    // pour trois raisons qui tiennent ensemble :
    //
    //  - la fermeture doit etre atteignable depuis la page d'attente
    //    d'approbation, et `RequireApprovedAccount` ferme `profile.*` a
    //    dessein (son docblock : un compte en attente n'a RIEN a corriger).
    //    Une route nommee a part se laisse autoriser par UNE entree explicite
    //    dans `ALWAYS_ALLOWED`, sans rouvrir les onze routes du profil ;
    //  - l'attente concerne le COMPTE, pas la surface d'ou il vient — meme
    //    raisonnement que `verification.notice` et `approval.notice`, qui
    //    vivent deja ici en route globale. Une route par Organization
    //    obligerait la page d'attente a en choisir une, et elle n'a pas
    //    toujours le contexte pour le faire ;
    //  - l'ancien nom `profile.destroy` existait en DEUX exemplaires (forme
    //    nue et forme prefixee par l'Organization) pour une action qui n'a
    //    aucune raison d'etre scopee : on ne ferme pas un compte « dans une
    //    Organization », on le ferme.
    //
    // `DELETE` est conserve comme verbe : c'est bien une suppression du point
    // de vue de la personne, et le formulaire existant n'a pas a changer de
    // methode.
    Route::delete('mon-compte', [ProfileController::class, 'close'])
        ->name('account.close');

    Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])
        ->name('password.confirm');

    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store']);

    Route::put('password', [PasswordController::class, 'update'])->name('password.update');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});
