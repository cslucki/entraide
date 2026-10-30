<?php

namespace App\Services\Users;

use App\Models\Organization;
use App\Models\User;
use App\Rules\UniqueEmailInOrganization;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

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
 * ## Ce que A3 posait, et ce que A4 a active
 *
 * A l'ecriture de ce service, `UNIQUE(users.email)` tenait encore : un email ne
 * correspondait qu'a un compte au plus, et `compteUnique()` rendait exactement
 * ce que `->first()` rendait. A3 posait le mecanisme sans rien ouvrir.
 *
 * **A4 (T1729) a retire cet unique global.** Le pluriel de `candidats()` est
 * donc reel depuis le 30/10, et le refus pour ambiguite ci-dessus n'est plus
 * theorique.
 *
 * ## TASK-1733 (A7) — la recherche est INSENSIBLE A LA CASSE
 *
 * Elle ne l'etait pas, et c'etait un defaut MESURE : un compte enregistre
 * `Majuscule@exemple.test` rendait **zero** candidat quand la personne tapait
 * son adresse en minuscules, donc un refus generique — indiscernable d'un mot
 * de passe oublie. Le meme compte etait aussi injoignable par
 * `/forgot-password`. La personne etait enfermee dehors, en silence.
 *
 * L'adresse peut porter une majuscule : `/admin/users` n'impose deliberement
 * pas de regle `lowercase` (constat T1729). Et `email` est sensible a la casse
 * sous PostgreSQL.
 *
 * Tout le cote ECRITURE pose deja la question en `LOWER(email)` —
 * {@see UniqueEmailInOrganization}, les deux index uniques de
 * T1729, le service d'invitation. Ces deux lecteurs-la etaient les seuls a
 * comparer exactement. **Une adresse se cherche partout avec `LOWER()`, ou
 * nulle part** : sinon le produit refuse ce que la base autorise, et promet ce
 * qu'elle interdit.
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
            // TASK-1733 (A7) — `LOWER()` des DEUX cotes, et c'est exactement la
            // forme que portent les index de T1729. La question posee ici et
            // celle posee a l'ecriture doivent etre la MEME, sinon l'une refuse
            // ce que l'autre autorise. `users_email_lower_index` sert cette
            // lecture : T1729 l'a cree pour ca, en remplacant la capacite de
            // lecture que l'unique global fournissait.
            ->whereRaw('LOWER(email) = ?', [Str::lower($email)])
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
     * celles qui contiennent `password`. `id` est la cle primaire : elle
     * designe a elle seule au plus un compte, et l'`email` ne fait que
     * confirmer. Un couple incoherent rend `null`, donc un refus, jamais
     * l'authentification d'un autre compte.
     *
     * TASK-1733 (A7) : ce raisonnement disait « `email` une colonne unique »,
     * ce qui est FAUX depuis A4. Il tient quand meme — mais par la cle primaire
     * seule, et il faut le dire ainsi. L'`email` passe ici est la valeur
     * STOCKEE, relue sur la ligne : la comparaison exacte du framework porte
     * donc sur la casse reelle du compte, et n'a pas besoin de `LOWER()`.
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
