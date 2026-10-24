<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\Route;

/**
 * TASK-1695 — le partage d'un DOSSIER avec des membres est DÉSACTIVÉ.
 *
 * ## Pourquoi cette garde plutôt qu'une suppression
 *
 * Décision de Cyril le 24/10 : désactiver la fonction, **garder le code**. Ces
 * tests sont la seule documentation exécutable de ce que le partage de Dossier
 * garantissait — ils repartiront tels quels le jour où la fonction revient.
 *
 * ## Pourquoi elle est posée PAR TEST, et non sur les classes
 *
 * Les 52 tests concernés vivent dans **5 fichiers** qui en contiennent bien
 * plus. Un `markTestSkipped()` placé en `setUp()` aurait neutralisé au passage
 * 23 des 24 tests d'`UserDeactivationTest`, qui n'ont rien à voir avec le
 * partage — une désactivation silencieuse bien plus large que demandée.
 *
 * ## Elle se répare toute seule
 *
 * La condition est l'EXISTENCE de la route, pas une constante à tenir à jour.
 * Décommenter les cinq routes `dossiers.members.*` et le corps de
 * `DossierPolicy::manageMembers()` suffit : ces tests redeviennent actifs sans
 * qu'on ait à repasser dans les 5 fichiers. Une liste écrite à la main, elle,
 * aurait dérivé.
 */
trait RequiresDossierMemberSharing
{
    protected function skipIfDossierMemberSharingDisabled(): void
    {
        if (! Route::has('organization.dossiers.members.search')) {
            $this->markTestSkipped(
                'TASK-1695 — partage de Dossier avec des membres désactivé '
                .'(routes dossiers.members.* commentées, DossierPolicy::manageMembers() ferme).',
            );
        }
    }
}
