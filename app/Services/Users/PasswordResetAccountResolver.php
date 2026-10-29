<?php

namespace App\Services\Users;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Password;

/**
 * TASK-1727 (A2) — QUEL compte une reinitialisation concerne-t-elle ?
 *
 * Autorite unique de cette question. Avant, chaque surface la reposait pour
 * son compte, et toujours de la meme facon fausse :
 * `User::where('email', ...)->first()`. Quatre occurrences dans les deux
 * controleurs d'authentification, une dans `AdminController`, et une dans le
 * broker lui-meme.
 *
 * ## Les deux questions sont DIFFERENTES
 *
 * Elles ne se repondent pas avec la meme donnee, et les confondre est le
 * defaut de fond :
 *
 *  - **DEMANDER** un lien : on ne connait qu'un email (et, dans un parcours
 *    d'Organization, le tenant). Il peut donc y avoir ZERO, UN, ou — demain —
 *    PLUSIEURS comptes concernes. D'ou une Collection : `first()` ici
 *    choisirait arbitrairement, et c'est precisement ce qu'on retire ;
 *  - **UTILISER** un lien : on connait l'email ET le token. Le token, lui,
 *    appartient a UN compte depuis TASK-1727 : c'est la seule donnee qui
 *    puisse trancher entre homonymes, et c'est pourquoi l'arbitrage D1 a
 *    retenu qu'il le fasse (option B). L'URL n'a pas eu a changer, et les
 *    liens deja envoyes continuent de fonctionner.
 *
 * ## Aucune information sur les autres tenants
 *
 * `comptesCibles()` filtre par Organization AVANT de rendre quoi que ce soit.
 * Un email appartenant a une autre Organization rend donc une collection VIDE
 * — indiscernable d'une adresse inconnue. L'appelant produit la meme reponse
 * dans les deux cas, et rien ne dit jamais « cette personne existe, mais
 * ailleurs ».
 */
class PasswordResetAccountResolver
{
    /**
     * Les comptes qu'une DEMANDE de lien concerne.
     *
     * @param  Organization|null  $organization  le tenant du parcours ; `null`
     *         pour le parcours global, qui ne filtre pas.
     * @return Collection<int, User>
     */
    public function comptesCibles(string $email, ?Organization $organization = null): Collection
    {
        return User::query()
            ->where('email', $email)
            ->when(
                $organization !== null,
                // D3 — un reset demande depuis `/org/X` ne peut cibler qu'un
                // compte de X. Le filtre porte sur l'Organization DU COMPTE,
                // jamais sur l'origine de la requete : c'est une donnee qu'on
                // possede et qui ne s'invente pas.
                fn ($query) => $query->where('organization_id', $organization->getKey())
            )
            // Pas de `first()`, et pas de `limit`. Aujourd'hui l'unique global
            // sur `users.email` borne ce resultat a un element au plus — T1727
            // ne touche pas a cette contrainte. La forme Collection est ce qui
            // rendra le jour venu (A4) le comportement correct sans reecrire
            // cette methode : chaque compte recevra SON lien, portant SON
            // token, parce que la table peut desormais en porter plusieurs.
            ->orderBy('id')
            ->get();
    }

    /**
     * Le compte qu'un token designe, ou `null`.
     *
     * La verification est deleguee au DEPOT de tokens — le meme objet que le
     * broker consultera ensuite. C'est deliberé : le hachage, l'expiration et
     * la fenetre d'anti-rejeu ont une autorite unique. Reimplementer ici le
     * `Hash::check()` et la comparaison de dates aurait cree un second juge,
     * libre de deriver du premier.
     */
    public function compteDuToken(string $email, string $token, ?Organization $organization = null): ?User
    {
        $depot = Password::broker()->getRepository();

        foreach ($this->comptesCibles($email, $organization) as $compte) {
            if ($depot->exists($compte, $token)) {
                return $compte;
            }
        }

        return null;
    }

    /**
     * Les credentials a passer au broker STOCK pour qu'il ne redevine rien.
     *
     * `retrieveByCredentials()` ajoute un `where` par cle fournie : `id` est la
     * cle primaire, `email` une colonne unique — la paire designe au plus un
     * compte. Un couple incoherent (un `id` et un `email` qui ne vont pas
     * ensemble) rend `null`, donc un refus, jamais l'ouverture d'un autre
     * compte.
     *
     * @return array{id: string, email: string}
     */
    public function credentials(User $compte): array
    {
        return [
            'id' => (string) $compte->getKey(),
            'email' => (string) $compte->email,
        ];
    }
}
