<?php

namespace App\Support\ScenarioPacks\Contracts;

use App\Models\Organization;

/**
 * TASK-1351 — contrat OPT-IN : un pack qui sait provisionner lui-meme son
 * Organization de demonstration.
 *
 * ## Pourquoi une interface separee, et pas une methode de plus sur
 * {@see ScenarioPackDefinition}
 *
 * Les deux packs existants (`artscilab-roger-demo`, `test20260822-dogfooding`)
 * ciblent une Organization creee en dehors d'eux. Leur ajouter une methode
 * qu'ils n'implementent pas obligerait a les modifier — ce que l'arbitrage du
 * 2026-09-01 interdit explicitement. Une interface separee les laisse
 * strictement intacts : le moteur ne change pas, seule la commande de
 * chargement gagne une branche de repli, et seulement pour un pack qui la
 * demande.
 *
 * ## Ce que ce contrat ne fait PAS
 *
 * Il ne rend pas l'Organization « propriete du registre » : la table
 * `scenario_pack_entities` exige un `organization_id` sur chaque entite
 * inscrite (voir {@see \App\Support\ScenarioPacks\ScenarioPackEntityRegistrar::track()}),
 * or une Organization n'en porte pas. L'Organization ne peut donc etre ni
 * tracee, ni purgee par {@see \App\Support\ScenarioPacks\ScenarioPackEntityPurger}.
 * Sa provenance est representee ailleurs, explicitement : la colonne
 * `scenario_pack_loads.organization_created_by_pack`, ecrite par la commande
 * de chargement au moment ou elle a provisionne, et lue par la commande de
 * suppression avant de retirer quoi que ce soit.
 *
 * ## L'invariant qui compte
 *
 * Un pack ne doit JAMAIS adopter silencieusement une Organization qu'il n'a pas
 * creee. `assertOrganizationAdoptable()` est appelee AVANT tout chargement dans
 * une Organization preexistante : elle doit lever si cette Organization porte
 * la moindre donnee metier. Une Organization vide (cas normal apres un retrait)
 * reste chargeable, mais ne devient jamais propriete du pack pour autant.
 */
interface ProvisionsItsOrganization
{
    /**
     * Le slug UNIQUE auquel ce pack est lie. Ne change jamais entre deux
     * versions du pack : c'est lui qui rend le pack hard-bound.
     */
    public function organizationSlug(): string;

    /**
     * Cree l'Organization de ce pack. Appelee UNIQUEMENT quand aucune
     * Organization ne porte `organizationSlug()`.
     */
    public function provisionOrganization(): Organization;

    /**
     * Verifie qu'une Organization PREEXISTANTE peut recevoir ce pack.
     *
     * Doit lever (et ne rien ecrire) des que l'Organization porte une donnee
     * metier : adopter le tenant de quelqu'un d'autre, ou ecraser un contenu
     * existant, n'est jamais un comportement acceptable pour un pack de
     * demonstration.
     */
    public function assertOrganizationAdoptable(Organization $organization): void;
}
