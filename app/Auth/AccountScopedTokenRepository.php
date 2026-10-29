<?php

namespace App\Auth;

use Illuminate\Auth\Passwords\DatabaseTokenRepository;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Support\Carbon;

/**
 * TASK-1727 (A2) — le depot de tokens de reinitialisation, indexe par COMPTE.
 *
 * ## Ce qui change, et ce qui ne change pas
 *
 * `DatabaseTokenRepository` employait `$user->getEmailForPasswordReset()` dans
 * ses CINQ methodes. Celle-ci substitue `$user->getKey()` partout. Rien
 * d'autre : le hachage, la duree de validite, la fenetre d'anti-rejeu et le
 * format du token restent ceux du framework, et sont toujours calcules par lui
 * (`$this->getHasher()`, `$this->expires`, `tokenExpired()`,
 * `tokenRecentlyCreated()`). On ne RECOPIE pas le framework, on redirige sa
 * cle de lecture.
 *
 * L'email continue d'etre ECRIT : il n'est plus la cle, mais il reste la trace
 * lisible de l'adresse a laquelle le lien est parti, et le `down()` de la
 * migration en depend.
 *
 * ## Pourquoi `getKey()` et non une colonne de plus
 *
 * `users.id` est un uuid. Un token porte donc l'identifiant exact du compte,
 * et deux comptes partageant une adresse auront deux LIGNES distinctes — ce
 * que la cle primaire `email` rendait impossible. C'est l'unique raison d'etre
 * de cette classe.
 *
 * ## Le cas NULL
 *
 * Un `user_id` a NULL (token orphelin herite de la migration) ne correspond a
 * aucun compte : `where('user_id', $user->getKey())` ne le rendra jamais.
 * Conserve, inutilisable — exactement la condition posee en D2.
 */
class AccountScopedTokenRepository extends DatabaseTokenRepository
{
    public function create(CanResetPasswordContract $user)
    {
        $this->deleteExisting($user);

        $token = $this->createNewToken();

        $this->getTable()->insert([
            'user_id' => $user->getKey(),
            'email' => $user->getEmailForPasswordReset(),
            'token' => $this->getHasher()->make($token),
            'created_at' => new Carbon,
        ]);

        return $token;
    }

    protected function deleteExisting(CanResetPasswordContract $user)
    {
        return $this->getTable()->where('user_id', $user->getKey())->delete();
    }

    public function exists(CanResetPasswordContract $user, #[\SensitiveParameter] $token)
    {
        $record = (array) $this->getTable()->where('user_id', $user->getKey())->first();

        return $record
            && ! $this->tokenExpired($record['created_at'])
            && $this->getHasher()->check($token, $record['token']);
    }

    public function recentlyCreatedToken(CanResetPasswordContract $user)
    {
        $record = (array) $this->getTable()->where('user_id', $user->getKey())->first();

        return $record && $this->tokenRecentlyCreated($record['created_at']);
    }

    public function delete(CanResetPasswordContract $user)
    {
        $this->deleteExisting($user);
    }
}
