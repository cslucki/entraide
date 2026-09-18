<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TASK-1412 — cutover de la verification d'email.
 *
 * Jusqu'a cette release, `User` n'implementait pas l'interface
 * `MustVerifyEmail` : aucun email de verification n'a jamais ete envoye et le
 * middleware `verified` (blog, Dossiers) etait un no-op. Les comptes existants
 * sont donc a `email_verified_at NULL` sans avoir jamais eu l'occasion de
 * verifier quoi que ce soit.
 *
 * Doctrine (MASTER, 07/09/2026 17h08) : « legacy accounts trusted during
 * verification cutover ». Ces comptes sont reputes verifies A L'INSTANT DU
 * CUTOVER — l'horodatage d'execution de cette migration — et PAS a leur date
 * de creation, qui fabriquerait une fausse preuve historique. Les comptes
 * crees apres le cutover restent reellement non verifies jusqu'au lien signe.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cutover = now();

        DB::table('users')
            ->whereNull('email_verified_at')
            ->update(['email_verified_at' => $cutover]);
    }

    /**
     * No-op documente : une fois le cutover passe, rien ne distingue un compte
     * grandfathered d'un compte reellement verifie, et remettre des NULL
     * exclurait des membres reels du blog et des Dossiers.
     */
    public function down(): void
    {
        //
    }
};
