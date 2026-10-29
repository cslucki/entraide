<?php

namespace App\Auth;

use Illuminate\Auth\Passwords\PasswordBrokerManager;

/**
 * TASK-1727 (A2) — injecte le depot indexe par compte, et RIEN d'autre.
 *
 * ## Pourquoi cette classe existe
 *
 * `PasswordBrokerManager` n'offre aucun point d'extension : ni `extend()`, ni
 * `customCreators`. `resolve()` fait un `new PasswordBroker(...)` en dur et
 * `createTokenRepository()` choisit entre `DatabaseTokenRepository` et
 * `CacheTokenRepository` selon le driver. Remplacer le depot passe donc
 * necessairement par le binding `auth.password`.
 *
 * ## Ce qu'elle ne fait PAS
 *
 * Elle ne touche pas a `PasswordBroker`, ni ne le recopie. Arbitrage MASTER D4
 * : le broker STOCK suffit, et c'est demontre plutot qu'affirme —
 * `PasswordBroker::getUser()` delegue a `retrieveByCredentials()`, qui ajoute
 * un `where` par credential fourni (en filtrant les cles contenant
 * `password`). Lui passer `['id' => <uuid>, 'email' => <email>]` produit donc
 * `where id = ? and email = ?` : la cle primaire ET l'adresse, soit au plus un
 * compte. Aucune desambiguisation n'a a etre reecrite.
 *
 * Seul `createTokenRepository()` est surcharge, et seulement pour le driver
 * base de donnees. Le driver `cache` reste celui du framework : ce depot n'est
 * pas employe ici (`config/auth.php` ne declare pas de driver), et lui
 * substituer silencieusement une autre implementation serait une surprise.
 */
class AccountScopedPasswordBrokerManager extends PasswordBrokerManager
{
    protected function createTokenRepository(array $config)
    {
        if (($config['driver'] ?? null) === 'cache') {
            return parent::createTokenRepository($config);
        }

        $key = $this->app['config']['app.key'];

        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7));
        }

        return new AccountScopedTokenRepository(
            $this->app['db']->connection($config['connection'] ?? null),
            $this->app['hash'],
            $config['table'],
            $key,
            // `expire` est en MINUTES dans `config/auth.php`, le depot l'attend
            // en SECONDES. Les memes `* 60` et les memes defauts que le
            // framework, repris a l'identique : s'en ecarter aurait allonge ou
            // raccourci la validite des liens sans que rien ne le dise.
            ($config['expire'] ?? 60) * 60,
            $config['throttle'] ?? 0,
        );
    }
}
