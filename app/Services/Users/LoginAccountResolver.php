<?php

namespace App\Services\Users;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * TASK-1728 (A3) — QUEL compte une tentative de connexion designe-t-elle ?
 *
 * Autorite unique de cette question, comme
 * {@see PasswordResetAccountResolver} l'est pour la reinitialisation (T1727).
 * Avant, chaque surface la reposait pour son compte et toujours de la meme
 * facon fausse : `User::where('email', ...)->first()` — dans `LoginRequest`
 * (deux fois, dont une cachee dans `Auth::attempt()`), dans
 * `Api/AuthController`, et dans `ConfirmablePasswordController`.
 *
 * ## Le principe, et il est l'inverse de l'intuition
 *
 * Quand plusieurs comptes portent l'email fourni et que rien ne designe
 * d'Organization, on REFUSE — **sans eprouver leurs mots de passe**.
 *
 * Essayer les mots de passe l'un apres l'autre serait la reponse naturelle, et
 * c'est la mauvaise. Elle transforme le formulaire de connexion en oracle : le
 * temps de reponse, le compteur d'echecs et le verrouillage diffuseraient
 * l'information « cette adresse existe dans plusieurs Organizations ». Et elle
 * ferait d'un mot de passe la designation d'un compte, alors qu'un mot de
 * passe n'identifie personne — deux comptes peuvent avoir le meme.
 *
 * Arbitrage MASTER D2 : « Si plusieurs comptes correspondent : refus
 * generique, sans tester leurs mots de passe. »
 *
 * ## Aujourd'hui, ce service ne change RIEN
 *
 * `UNIQUE(users.email)` tient toujours (son retrait est l'etape A4) : un email
 * ne peut donc correspondre qu'a un compte au plus, et `compteUnique()` rend
 * exactement ce que `->first()` rendait. A3 pose le mecanisme et le rend
 * testable ; elle n'ouvre rien.
 */
class LoginAccountResolver
{
    /**
     * Les comptes qu'une adresse designe, eventuellement bornes a un tenant.
     *
     * @return Collection<int, User>
     */
    public function candidats(string $email, ?Organization $organization = null): Collection
    {
        return User::query()
            ->where('email', $email)
            ->when(
                $organization !== null,
                fn ($query) => $query->where('organization_id', $organization->getKey())
            )
            // Ni `first()`, ni `limit` : c'est tout l'objet de ce service. Le
            // tri rend l'ensemble stable pour les tests, il ne sert pas a
            // choisir — personne ne choisit ici.
            ->orderBy('id')
            ->get();
    }

    /**
     * Le compte a authentifier, ou `null`.
     *
     * `null` couvre TROIS cas, et c'est voulu qu'ils soient indiscernables de
     * l'exterieur : adresse inconnue, adresse absente de l'Organization
     * demandee, et adresse ambigue. Les trois doivent rendre le meme refus
     * generique, sans quoi la reponse dirait a qui la lit dans quelle
     * Organization une adresse se trouve.
     */
    public function compteUnique(string $email, ?Organization $organization = null): ?User
    {
        $candidats = $this->candidats($email, $organization);

        return $candidats->count() === 1 ? $candidats->first() : null;
    }

    /**
     * L'Organization d'une selection explicite, ou `null`.
     *
     * PUBLIQUE et ACTIVE seulement (arbitrage MASTER D2). Le selecteur du
     * formulaire global n'en propose pas d'autres, et le serveur le reverifie
     * plutot que de faire confiance a ce qui est poste : une valeur bricolee a
     * la main ne doit pas ouvrir une porte que l'interface ne montre pas.
     *
     * Une Organization privee garde son propre parcours
     * `/org/{slug}/login` — c'est la l'equivalent de sa selection, et il n'a
     * pas besoin d'etre annonce.
     */
    public function organizationSelectionnable(?string $slug): ?Organization
    {
        if ($slug === null || $slug === '') {
            return null;
        }

        return Organization::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->where('is_public', true)
            ->first();
    }

    /**
     * Les Organizations que le selecteur du formulaire global peut proposer.
     *
     * Publiques et actives : cette liste ne dit RIEN de personne. Elle est la
     * meme pour tout visiteur, authentifie ou non, et ne revele aucune
     * appartenance individuelle — c'est la condition posee en D2.
     *
     * @return Collection<int, Organization>
     */
    public function organizationsProposables(): Collection
    {
        return Organization::query()
            ->where('is_active', true)
            ->where('is_public', true)
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);
    }

    /**
     * Les credentials a passer au framework pour qu'il ne redevine rien.
     *
     * `retrieveByCredentials()` ajoute un `where` par cle fournie en filtrant
     * celles qui contiennent `password` : `id` est la cle primaire, `email` une
     * colonne unique — la paire designe au plus un compte. Un couple
     * incoherent rend `null`, donc un refus, jamais l'authentification d'un
     * autre compte.
     *
     * Demontre en T1727, reemploye ici sans extension du framework.
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
