<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TASK-1412 — les comptes crees AVANT que la verification d'email existe sont
 * consideres verifies.
 *
 * Jusqu'ici `User` n'implementait pas l'interface `MustVerifyEmail` : aucun
 * email de verification n'a jamais ete envoye, et `email_verified_at` est
 * reste NULL pour la plupart des comptes reels (45 sur 57 sur le banc local
 * au 07/09/2026). Le middleware `verified` deja pose sur la redaction du blog
 * et sur les Dossiers etait donc un no-op.
 *
 * Activer l'interface rend ce middleware EFFECTIF. Sans ce backfill, tous ces
 * comptes — des membres reels — seraient exclus du blog et des Dossiers a
 * l'instant du deploiement, sans avoir jamais eu la possibilite de verifier
 * quoi que ce soit. Ce n'est pas une regression acceptable.
 *
 * Doctrine : un compte ne de l'epoque sans verification est repute verifie a
 * la date de sa creation. Les comptes crees APRES cette migration doivent
 * verifier leur email — c'est tout l'objet de la TASK.
 *
 * Migration de DONNEES, additive et tenant-neutre : elle ne remplit que des
 * NULL, ne touche aucune autre colonne, et ne lit aucune Organization.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->whereNull('email_verified_at')
            ->update(['email_verified_at' => DB::raw('created_at')]);
    }

    /**
     * Volontairement inerte. Une fois remplie, la colonne ne permet plus de
     * distinguer un compte backfille d'un compte reellement verifie : remettre
     * NULL frapperait aussi des verifications authentiques posterieures.
     * L'etat « verifie » est de toute facon le plus sur des deux cotes.
     */
    public function down(): void
    {
        //
    }
};
