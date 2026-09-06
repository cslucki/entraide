<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TASK-1377 — `ambiguous` doit pouvoir s'ecrire dans la preuve historique.
 *
 * ## Le defaut, et pourquoi il etait invisible
 *
 * La migration historique declare `enum('status', ['sent', 'failed'])`. Sur
 * PostgreSQL, cela produit une contrainte `CHECK` reelle ; sur SQLite, Laravel
 * n'ecrit qu'un `varchar` sans contrainte. Les deux moteurs ne faisaient donc
 * PAS respecter la meme regle — et une suite qui ne tourne que sur SQLite ne
 * pouvait pas le voir.
 *
 * Ecrire `ambiguous` aurait echoue en production PostgreSQL, et nulle part
 * ailleurs. C'est exactement l'ecart que la CI double-moteur existe pour
 * attraper.
 *
 * ## `ambiguous` n'est PAS un `failed` prudent
 *
 * `failed` est un echec CONNU : rien n'est parti, et on pourrait le rejouer.
 * `ambiguous` est un resultat INCONNU — le transport a leve apres qu'on lui a
 * remis le message. Les fondre en un seul etat rendrait le rejeu automatique
 * dangereux : on renverrait un message peut-etre deja delivre.
 *
 * La distinction est donc ce qui GARANTIT l'absence de rejeu automatique. Elle
 * doit exister jusque dans le schema, sinon la base refuserait l'etat que le
 * code a besoin d'ecrire.
 *
 * ## La migration historique n'est PAS modifiee
 *
 * Une migration deja jouee est un fait passe. On fait evoluer ici, dans la
 * tranche qui a besoin de l'evolution.
 *
 * ## SQLite : rien a faire, et c'est dit plutot que tu
 *
 * Aucune contrainte n'y existe — verifie sur le schema reel, pas suppose.
 * Reconstruire la table pour en ajouter une couterait cher pour un garde que
 * cette base n'a jamais porte. L'uniformite entre moteurs est obtenue au
 * niveau applicatif, par `EmailLog` qui valide le vocabulaire sur les DEUX.
 */
return new class extends Migration
{
    private const CONTRAINTE = 'email_logs_status_check';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE email_logs DROP CONSTRAINT IF EXISTS '.self::CONTRAINTE);
        DB::statement(
            'ALTER TABLE email_logs ADD CONSTRAINT '.self::CONTRAINTE
            ." CHECK (status IN ('sent', 'failed', 'ambiguous'))"
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Le retour arriere ne peut pas reussir si des lignes `ambiguous`
        // existent : c'est voulu. Effacer ou reecrire une preuve d'envoi pour
        // faire passer un rollback serait pire que l'echec du rollback.
        DB::statement('ALTER TABLE email_logs DROP CONSTRAINT IF EXISTS '.self::CONTRAINTE);
        DB::statement(
            'ALTER TABLE email_logs ADD CONSTRAINT '.self::CONTRAINTE
            ." CHECK (status IN ('sent', 'failed'))"
        );
    }
};
