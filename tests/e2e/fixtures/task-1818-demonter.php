<?php

/**
 * TASK-1818 — demontage de la fixture E2E isolee.
 *
 * Supprime UNIQUEMENT ce que le montage a cree. Aucune configuration existante
 * n'ayant ete touchee, il n'y a rien a restaurer — c'est tout l'interet.
 */

$organization = App\Models\Organization::withTrashed()->where('slug', 'e2e-1818')->first();

if ($organization) {
    App\Models\User::where('organization_id', $organization->id)->delete();
    App\Models\OrganizationAiSetting::where('organization_id', $organization->id)->delete();
    $organization->forceDelete();
}

echo 'FIXTURE_1818_DEMONTEE';
