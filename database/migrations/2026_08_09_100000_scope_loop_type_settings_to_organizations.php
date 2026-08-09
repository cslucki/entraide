<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les reglages de types deviennent scopables par Organization.
 *
 * `loop_type_settings` ne portait que `loop_type`, en cle **unique** : un
 * reglage etait donc forcement global. La chaine
 * **Plateforme -> Organization -> Boucle** existait deja pour les permissions
 * — `organizations.loop_permissions` et `LoopPermissionResolver` la tiennent —
 * mais pas pour les types.
 *
 * **`organization_id` nullable, et `null` signifie « Plateforme ».** C'est la
 * meme grammaire que les permissions : l'absence de valeur veut dire « le
 * niveau au-dessus ». Une table separee pour les overrides d'Organization
 * aurait fait un second systeme pour la meme chose.
 *
 * `cascadeOnDelete` : une Organization supprimee emporte ses reglages, qui
 * n'ont plus de sens sans elle.
 *
 * L'unique passe de `loop_type` seul au couple : une meme cle de type peut
 * desormais porter un reglage global **et** un reglage par Organization.
 * PostgreSQL et SQLite traitent tous deux plusieurs NULL comme distincts, ce
 * qui ne convient pas ici — il ne doit y avoir **qu'une** ligne globale par
 * type. D'ou l'index partiel ci-dessous, pose sur les deux moteurs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loop_type_settings', function (Blueprint $table) {
            $table->foreignUuid('organization_id')->nullable()->after('id')
                ->constrained('organizations')->cascadeOnDelete();
        });

        Schema::table('loop_type_settings', function (Blueprint $table) {
            $table->dropUnique(['loop_type']);
            $table->unique(['organization_id', 'loop_type']);
        });

        // **Une seule ligne globale par type.** Plusieurs NULL ne s'egalent pas
        // dans un index unique ordinaire : sans cette contrainte partielle,
        // deux reglages Plateforme du meme type pourraient coexister et le
        // service en lirait un au hasard.
        // PostgreSQL et SQLite acceptent tous deux la meme syntaxe d'index
        // partiel, ce qui evite d'avoir deux ecritures a garder d'accord.
        \Illuminate\Support\Facades\DB::statement(
            'CREATE UNIQUE INDEX loop_type_settings_global_unique
             ON loop_type_settings (loop_type) WHERE organization_id IS NULL',
        );
    }

    public function down(): void
    {
        \Illuminate\Support\Facades\DB::statement('DROP INDEX IF EXISTS loop_type_settings_global_unique');

        Schema::table('loop_type_settings', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'loop_type']);
            $table->dropConstrainedForeignId('organization_id');
            $table->unique('loop_type');
        });
    }
};
