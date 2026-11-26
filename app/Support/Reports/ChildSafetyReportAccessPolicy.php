<?php

namespace App\Support\Reports;

use App\Models\User;

/**
 * TASK-1905 (decision MASTER D3) — qui lit et traite les signalements
 * « Securite des enfants » : le SUPERADMIN uniquement.
 *
 * `is_admin` seul ouvre tout `/admin`, y compris a des comptes de
 * demonstration ou de recette. Hors environnement local/test, un superadmin
 * dont l'adresse finit par un suffixe reserve (`.test`, `.invalid`...) est
 * donc exclu. Meme esprit que `SuperAdminPushAccessPolicy` (TASK-1821).
 * Les administrateurs d'organisation n'y ont jamais acces (scope
 * `visibleByOrganizationAdmin`, TASK-1904).
 */
class ChildSafetyReportAccessPolicy
{
    public function estAutorise(?User $utilisateur): bool
    {
        if ($utilisateur === null || ! $utilisateur->is_admin) {
            return false;
        }

        $email = mb_strtolower(trim((string) $utilisateur->email));

        if ($email === '') {
            return false;
        }

        // En local (recette) et en tests, les comptes QA `.test` sont admis.
        if (app()->environment(['local', 'testing'])) {
            return true;
        }

        foreach ((array) config('child_safety.restricted_admin_email_suffixes', []) as $suffixe) {
            if ($suffixe !== '' && str_ends_with($email, mb_strtolower($suffixe))) {
                return false;
            }
        }

        return true;
    }
}
