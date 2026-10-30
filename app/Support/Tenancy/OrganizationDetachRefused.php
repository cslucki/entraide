<?php

namespace App\Support\Tenancy;

use App\Models\Organization;
use RuntimeException;

/**
 * TASK-1729 phase 2 — refus de detacher des comptes dont l'adresse deviendrait
 * ambigue.
 *
 * `RuntimeException` et non une exception de validation : les appelants ne
 * sont pas tous des requetes HTTP. Deux sont des commandes `artisan`, deux
 * sont des services du Scenario Manager, qui traduit deja les refus du moteur
 * en `ScenarioVersionRefused`. Un refus metier doit se lire comme tel a chacune
 * de ces sorties, jamais comme un 500 ni comme une trace.
 */
class OrganizationDetachRefused extends RuntimeException
{
    /**
     * @param  list<string>  $adresses
     */
    public static function pourAdresses(Organization $organization, array $adresses): self
    {
        return new self(sprintf(
            'Suppression refusee : %d adresse(s) de « %s » sont deja portees par un autre compte, '
            .'et le detachement les rendrait ambigues — %s. '
            ."Aucun compte n'a ete detache ni supprime.",
            count($adresses),
            $organization->slug ?? (string) $organization->getKey(),
            implode(', ', $adresses),
        ));
    }
}
