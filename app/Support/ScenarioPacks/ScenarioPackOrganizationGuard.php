<?php

namespace App\Support\ScenarioPacks;

use App\Models\Organization;
use App\Support\ScenarioPacks\Exceptions\ScenarioPackOrganizationNotAllowedException;

/**
 * Garde-fou unique (TASK-1240, contrat TASK-1239 S3) : une Organization ne
 * peut recevoir un chargement, un reset ou une suppression de scenario pack
 * que si elle est QUALIFIEE comme cible de demonstration. Partage par
 * `ScenarioPackLoader`, `ScenarioPackResetter` et `ScenarioPackRemover` afin
 * qu'aucune des trois operations ne puisse, par oubli, s'appliquer a une
 * Organization qui ne l'est pas.
 *
 * ## TASK-1642 — deux preuves de qualification, jamais un bypass
 *
 * Le garde repond toujours a la meme question. Ce qui change, c'est qu'il
 * existe desormais deux facons de prouver la reponse, et AUCUNE des deux
 * n'est falsifiable par l'appelant :
 *
 *  1. **Allowlist commitee** — inchangee. Le slug figure dans
 *     `config('scenario_packs.allowed_organizations')`. C'est la preuve des
 *     quatre packs historiques : y ajouter un slug exige un commit revu et un
 *     deploiement, jamais un flag en base ni une UI.
 *
 *  2. **Provenance sandbox server-side** — nouvelle. L'Organization porte
 *     `scenario_sandbox_created_at`, pose par le seul service qui CREE une
 *     sandbox de manifeste, sur une ligne qu'il vient d'inserer, dans la meme
 *     transaction. La colonne n'est pas `fillable` : ni un manifeste, ni un
 *     formulaire, ni un mass assignment ne peut l'atteindre.
 *
 * Cette seconde preuve existe parce qu'une sandbox Manifest ne PEUT PAS etre
 * dans l'allowlist : son slug est choisi au moment du Load (`proposed_slug`
 * n'est qu'une suggestion, et une collision doit produire une AUTRE sandbox).
 * Un slug decide au runtime ne peut pas figurer dans un tableau commite.
 *
 * ## Ce que ce n'est PAS
 *
 * Ce n'est pas un `if ($pack instanceof ...) return;`. Une exception par TYPE
 * DE PACK ouvrirait ce pack sur n'importe quelle Organization, Organization
 * cliente comprise. Ici la qualification reste une propriete de la CIBLE, et
 * une Organization cliente — qui n'est ni dans l'allowlist, ni nee comme
 * sandbox — reste refusee quel que soit le pack, l'appelant ou le manifeste.
 * Le garde s'ouvre franchement sur un fait de provenance, jamais par une
 * astuce (doctrine TASK-1632).
 */
class ScenarioPackOrganizationGuard
{
    public static function assertAllowed(Organization $organization): void
    {
        if (self::isCommittedAllowlistTarget($organization) || self::isScenarioSandbox($organization)) {
            return;
        }

        throw ScenarioPackOrganizationNotAllowedException::forSlug($organization->slug);
    }

    /**
     * Preuve 1 : slug declare dans la configuration commitee.
     */
    public static function isCommittedAllowlistTarget(Organization $organization): bool
    {
        return in_array($organization->slug, config('scenario_packs.allowed_organizations', []), true);
    }

    /**
     * Preuve 2 : l'Organization est NEE comme sandbox de scenario.
     *
     * Lu sur la colonne de provenance, jamais sur le slug : deriver la
     * qualification du slug ferait d'une chaine choisie par le manifeste une
     * permission, ce que la spec 10.2 interdit explicitement.
     */
    public static function isScenarioSandbox(Organization $organization): bool
    {
        return $organization->scenario_sandbox_created_at !== null;
    }
}
