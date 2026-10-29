<?php

namespace App\Providers;

use App\Auth\AccountScopedPasswordBrokerManager;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\ServiceProvider;

/**
 * TASK-1727 (A2) — la reinitialisation de mot de passe cible un COMPTE.
 *
 * Deux branchements, et deux seulement.
 */
class PasswordResetServiceProvider extends ServiceProvider
{
    /**
     * 1. Le depot de tokens indexe par compte.
     *
     * `PasswordBrokerManager` n'a pas de point d'extension : reprendre le
     * binding `auth.password` est le seul chemin. La classe liee ETEND celle du
     * framework et ne surcharge que la fabrication du depot — voir
     * `AccountScopedPasswordBrokerManager` pour la demonstration que le broker
     * STOCK suffit (arbitrage MASTER D4).
     *
     * ## Pourquoi `extend()` et non `singleton()`
     *
     * Mesure, pas supposition : avec un `singleton('auth.password', ...)` ici,
     * `app('auth.password')` rendait toujours le `PasswordBrokerManager` du
     * framework. `Illuminate\Auth\AuthServiceProvider` est enregistre APRES
     * les providers de `bootstrap/providers.php` et son binding ecrasait le
     * notre — en silence, et le depot stock ecrivait alors des lignes dont
     * `user_id` restait NULL. Deux tests l'ont attrape.
     *
     * `extend()` ne depend d'aucun ordre d'enregistrement : l'extension est
     * memorisee et appliquee au moment de la RESOLUTION, donc apres le dernier
     * qui a lie la cle. Le manager stock est remplace par le notre, sans qu'il
     * reste deux managers vivants a cote l'un de l'autre.
     */
    public function register(): void
    {
        $this->app->extend('auth.password', fn ($manager, $app) => new AccountScopedPasswordBrokerManager($app));
    }

    /**
     * 2. Le lien conduit au bon parcours d'Organization (arbitrage MASTER D3).
     *
     * ## Le defaut
     *
     * La notification STOCK construit `route('password.reset', ...)` — la route
     * GLOBALE. Un membre qui demandait une reinitialisation depuis
     * `/org/{slug}/forgot-password` recevait donc un lien vers
     * `/reset-password/{token}` : seuls les FORMULAIRES etaient contextualises,
     * jamais le lien. Il n'y avait donc rien a « preserver » — il y avait un
     * contexte a AJOUTER.
     *
     * ## Le choix de la source
     *
     * L'Organization vient du COMPTE, pas de l'origine de la requete. C'est une
     * donnee qu'on possede, qui ne s'invente pas, et qui survit a tout : un
     * lien reste valide des jours, et l'origine de la demande ne serait plus la
     * pour le relire. Aucune colonne n'a eu a etre ajoutee au token.
     *
     * Un compte SANS Organization garde le lien global — c'est la condition du
     * point 6, et c'est aussi la seule forme qui ait un sens : il n'y a pas de
     * `/org/{slug}` ou l'envoyer.
     *
     * ## Compatibilite
     *
     * La route globale reste servie par les memes controleurs : les liens deja
     * envoyes, et ceux des comptes sans Organization, continuent de fonctionner
     * a l'identique. L'URL ne change que pour les NOUVEAUX liens des comptes
     * rattaches a un tenant.
     */
    public function boot(): void
    {
        ResetPassword::createUrlUsing(function (object $notifiable, string $token): string {
            $email = $notifiable->getEmailForPasswordReset();

            $organization = $notifiable instanceof User
                ? $notifiable->organization
                : null;

            if ($organization === null) {
                return url(route('password.reset', [
                    'token' => $token,
                    'email' => $email,
                ], false));
            }

            return url(route('organization.password.reset', [
                'organization' => $organization->slug,
                'token' => $token,
                'email' => $email,
            ], false));
        });
    }
}
