<?php

namespace App\Services\Users;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * TASK-1734 — QUELLES organisations le menu avatar propose-t-il a ce compte ?
 *
 * ## L'union, et pourquoi ce n'est pas « les freres »
 *
 * Deux familles, dedupliquees par compte :
 *
 *  1. les comptes LIES (`account_links`), quelle que soit leur adresse —
 *     le lien est l'autorisation, et il survit a un changement d'adresse ;
 *     ne lister que les freres ferait DISPARAITRE du menu un compte lie dont
 *     l'email a diverge, alors qu'il reste basculable en un clic ;
 *  2. les comptes FRERES non lies — meme adresse, `LOWER()` des deux cotes
 *     (A7) — mais SEULEMENT si leur adresse est VERIFIEE : un compte qui n'a
 *     jamais prouve son adresse a la boite n'a pas a etre revele par elle.
 *
 * ## Decouverte n'est pas autorisation
 *
 * Cette liste ne DONNE acces a rien : un frere non lie est `a_confirmer`, et
 * le serveur n'acceptera que la preuve par la boite. Elle ne REVELE rien non
 * plus que la personne ne possede deja : elle est calculee pour un compte
 * authentifie ET verifie (gardes du groupe `web`), donc pour quelqu'un qui
 * controle la boite — laquelle atteint deja chacun de ces comptes par
 * `/forgot-password`.
 *
 * ## Memoisation PAR REQUETE
 *
 * Le menu avatar vit sur plusieurs surfaces rendues dans la MEME requete
 * (bureau + responsive, side-nav, topbar). Le memo evite de reposer les deux
 * requetes a chaque surface. La classe est liee en `scoped()` — par requete,
 * jamais au-dela : un singleton garderait le panneau d'un utilisateur pour
 * le suivant sous Octane.
 */
class SiblingAccountsPanel
{
    public const ETAT_BASCULABLE = 'basculable';

    public const ETAT_A_CONFIRMER = 'a_confirmer';

    public const ETAT_INDISPONIBLE = 'indisponible';

    /** @var array<string, Collection<int, MyOrganizationEntry>> */
    private array $memo = [];

    public function __construct(
        private readonly AccountLinkService $liens,
        private readonly LoginAccountResolver $comptes,
        private readonly AccountSwitchPreflight $preflight,
    ) {}

    /**
     * Les entrees du panneau, triees par nom d'Organization.
     *
     * @return Collection<int, MyOrganizationEntry>
     */
    public function entrees(User $moi): Collection
    {
        $cleMemo = (string) $moi->getKey();

        if (array_key_exists($cleMemo, $this->memo)) {
            return $this->memo[$cleMemo];
        }

        $lies = $this->liens->liensDe($moi)
            ->map(fn ($lien) => $lien->autreQue($moi))
            ->filter()
            ->unique(fn (User $compte) => (string) $compte->getKey())
            ->values();

        $idsLies = $lies->map(fn (User $compte) => (string) $compte->getKey())->all();

        $freres = $this->comptes->candidats($moi->email)
            ->reject(fn (User $compte) => (string) $compte->getKey() === (string) $moi->getKey())
            ->reject(fn (User $compte) => in_array((string) $compte->getKey(), $idsLies, true))
            // Un frere non lie et NON verifie n'apparait pas du tout : son
            // adresse ne l'a pas encore revendique.
            ->filter(fn (User $compte) => $compte->hasVerifiedEmail())
            ->values();

        $freres->loadMissing('organization');

        $entrees = $lies->map(fn (User $compte) => $this->entree($compte, lie: true))
            ->concat($freres->map(fn (User $compte) => $this->entree($compte, lie: false)))
            ->sortBy(fn (MyOrganizationEntry $entree) => [
                mb_strtolower($entree->organization?->name ?? ''),
                (string) $entree->compte->getKey(),
            ])
            ->values();

        return $this->memo[$cleMemo] = $entrees;
    }

    /**
     * L'entree que designe un slug d'Organization, pour ce compte — ou `null`.
     *
     * C'est la SEULE resolution que le controleur emploie : jamais une
     * recherche generale d'Organization. Un slug qui n'est pas dans le
     * panneau de la personne rend `null`, que l'Organization existe ou non —
     * une valeur bricolee n'apprend donc rien.
     *
     * Deux comptes dans la meme Organization (un lie d'adresse differente ET
     * un frere) : le basculable gagne, puis l'ordre stable du panneau.
     */
    public function entreePourSlug(User $moi, string $slug): ?MyOrganizationEntry
    {
        $candidates = $this->entrees($moi)
            ->filter(fn (MyOrganizationEntry $entree) => $entree->organization?->slug === $slug);

        return $candidates->firstWhere('etat', self::ETAT_BASCULABLE)
            ?? $candidates->first();
    }

    private function entree(User $compte, bool $lie): MyOrganizationEntry
    {
        $compte->loadMissing('organization');

        $motif = $compte->organization === null
            // Sans Organization, pas de destination : `canonicalHome()` n'a
            // nulle part ou atterrir. Le cas est theorique (comptes orphelins
            // d'avant A6), on le ferme plutot que de le decouvrir en 500.
            ? 'sans_organization'
            : $this->preflight->motifDeRefus($compte);

        // Le motif n'est PAS journalise ici : ce panneau se rend a chaque
        // page. Le journal appartient aux TENTATIVES (le POST du controleur),
        // pas a l'affichage.
        return new MyOrganizationEntry(
            compte: $compte,
            organization: $compte->organization,
            etat: $motif !== null
                ? self::ETAT_INDISPONIBLE
                : ($lie ? self::ETAT_BASCULABLE : self::ETAT_A_CONFIRMER),
            lie: $lie,
        );
    }
}
