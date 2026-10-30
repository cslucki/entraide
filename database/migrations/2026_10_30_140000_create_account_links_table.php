<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1731 (A5b) — la liaison explicite et revocable entre deux comptes.
 *
 * ## La paire est ORDONNEE, et ce n'est pas un detail d'implementation
 *
 * Une liaison est bidirectionnelle : A lie a B, c'est B lie a A. Stocker
 * `user_id` et `linked_user_id` aurait laisse la base accepter les DEUX lignes
 * (A,B) et (B,A) — deux verites pour un seul fait, et c'est la seconde qu'on
 * oublie de revoquer. En rangeant toujours le plus petit identifiant dans
 * `user_a_id`, un unique index sur la paire suffit a rendre la bidirectionnalite
 * et l'unicite STRUCTURELLES, pas seulement tenues par le code.
 *
 * Exigence MASTER (30/10) : « unicite de la paire et interdiction de
 * l'auto-liaison EN BASE ».
 *
 * ## L'auto-liaison : contrainte CHECK sur PostgreSQL, et seulement la
 *
 * `CHECK (user_a_id <> user_b_id)` dit exactement la regle. PostgreSQL
 * l'accepte en `ALTER TABLE`; SQLite ne sait PAS ajouter une contrainte apres
 * coup, et la poser a la creation exigerait d'ecrire le `CREATE TABLE` a la
 * main, donc de renoncer a la gestion des cles etrangeres par le constructeur
 * de schema. L'asymetrie est ASSUMEE et declaree ici plutot que masquee : sur
 * SQLite, l'interdiction est tenue par le service et par un test qui tente
 * explicitement l'auto-liaison. Le banc local tourne sur SQLite, la gate
 * PostgreSQL exerce la contrainte reelle.
 *
 * ## Pas d'`organization_id`, et c'est le sujet
 *
 * Cette table est INTER-TENANT par construction : elle relie deux comptes de
 * deux Organizations differentes. Elle ne peut donc pas porter
 * `organization_id`, ce qui la fait tomber dans
 * `ScenarioSandboxPreflight::TABLES_HORS_PORTEE` — ou elle est declaree avec sa
 * raison. Elle est aussi inscrite au `UserDataLifecycleRegistry`, une entree
 * par colonne. Ces deux declarations ne sont pas optionnelles : leurs tests de
 * completude exigent une correspondance exacte au schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_links', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // `cascadeOnDelete` des deux cotes : un lien ne survit a aucun de
            // ses deux comptes. C'est la CEINTURE ; la bretelle est l'entree
            // POLICY_DELETE du registre de cycle de vie, qui fait que la
            // suppression auditee les compte et les retire explicitement.
            $table->foreignUuid('user_a_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('user_b_id')->constrained('users')->cascadeOnDelete();

            // Qui a cree le lien. Trace seulement : ce n'est NI une propriete,
            // ni un privilege — la revocation est ouverte aux deux comptes.
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['user_a_id', 'user_b_id'], 'account_links_pair_unique');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE account_links ADD CONSTRAINT account_links_not_self CHECK (user_a_id <> user_b_id)'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('account_links');
    }
};
