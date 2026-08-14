<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un type de Boucle peut desormais naitre sans passer par un deploiement.
 *
 * **Pourquoi une table, et non une ligne de plus dans `loop_type_settings`.**
 * Cette derniere porte des *surcharges* : ce qu'un niveau change a un type qui
 * existe deja. Un type cree, lui, **est** la chose. Les confondre casserait la
 * regle posee en TASK-1116, ou `reset()` supprime la ligne pour revenir au
 * niveau au-dessus : « Revenir aux reglages Plateforme » effacerait le type
 * lui-meme, avec les Boucles qui le portent restees orphelines. Une ligne vide
 * y serait aussi indistinguable d'une absence.
 *
 * Les deux couches se superposent donc sans se melanger :
 *
 *   `config/loop_types.php`  ─┐
 *   `custom_loop_types`      ─┴─> le **catalogue** : ce qui existe
 *   `loop_type_settings`     ───> les **surcharges** : ce qu'un niveau en change
 *
 * `organization_id` nullable garde la meme grammaire que partout ailleurs :
 * `null` signifie « Plateforme ». Un type cree par une Organization n'existe que
 * chez elle ; un type cree par la Plateforme existe partout.
 *
 * `cascadeOnDelete` : une Organization supprimee emporte les types qu'elle a
 * crees — ils n'ont aucun sens sans elle, et aucune autre ne peut les porter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_loop_types', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('organization_id')->nullable()
                ->constrained('organizations')->cascadeOnDelete();

            // **La cle technique, immuable.** Prefixee pour les types
            // d'Organization (`org_7f3a__parcours`), ce qui rend l'appartenance
            // lisible dans la cle elle-meme et rend impossible toute collision
            // avec une cle du fichier de configuration.
            $table->string('key', 80);

            $table->string('label', 80);
            $table->text('description')->nullable();

            // Le type dont celui-ci est parti, s'il en vient un. Purement
            // documentaire : la composition est copiee a la creation, jamais
            // suivie ensuite — sinon un type « parti de » se mettrait a bouger
            // tout seul quand son modele change.
            $table->string('based_on', 80)->nullable();

            $table->string('icon', 40)->nullable();
            $table->json('cards')->nullable();
            $table->boolean('available')->default(true);
            $table->unsignedInteger('order')->default(500);

            $table->foreignUuid('created_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['organization_id', 'key']);
            $table->index('organization_id');
        });

        // **Une seule ligne globale par cle.** Plusieurs NULL ne s'egalent pas
        // dans un index unique ordinaire : sans cette contrainte partielle, deux
        // types Plateforme de meme cle coexisteraient et le registre en lirait
        // un au hasard. Meme garde qu'en TASK-1115, meme syntaxe sur les deux
        // moteurs.
        \Illuminate\Support\Facades\DB::statement(
            'CREATE UNIQUE INDEX custom_loop_types_global_key_unique
             ON custom_loop_types (key) WHERE organization_id IS NULL',
        );
    }

    public function down(): void
    {
        \Illuminate\Support\Facades\DB::statement('DROP INDEX IF EXISTS custom_loop_types_global_key_unique');

        Schema::dropIfExists('custom_loop_types');
    }
};
