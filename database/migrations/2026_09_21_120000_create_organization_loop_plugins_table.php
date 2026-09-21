<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1614 — ou un plugin de Boucle est disponible.
 *
 * Le CATALOGUE est un fichier (config/loop_plugins.php). Cette table ne dit
 * pas ce qu'un plugin est : elle dit dans quelle Organization le SuperAdmin
 * l'a autorise. Une ligne = une decision, pour une Organization et un plugin.
 *
 * `organization_id` est NOT NULL, et c'est une decision, pas un oubli.
 * `loop_type_settings` porte un `organization_id` NULLABLE parce qu'un reglage
 * de type existe a DEUX portees et que `null` y veut dire « la Plateforme ».
 * Ici il n'y a qu'une portee : une disponibilite est TOUJOURS celle d'une
 * Organization. Accepter `null` inventerait un niveau « Plateforme » que le
 * produit ne demande pas — et ce `null` voudrait dire « disponible partout »,
 * exactement le contraire de ce qu'une capacite experimentale doit faire quand
 * personne n'a rien decide.
 *
 * FERME PAR DEFAUT : l'absence de ligne vaut « non disponible ». Un plugin
 * experimental ne s'allume que par un geste explicite.
 *
 * `available` est une colonne, et la ligne n'est PAS supprimee quand on
 * eteint. Revenir au defaut en supprimant la ligne — la convention de
 * `loop_type_settings` — perdrait `updated_by` et `updated_at`, donc QUI a
 * coupe et QUAND. Pour une capacite experimentale qui doit pouvoir etre
 * retiree vite, c'est precisement ce qu'on veut garder.
 *
 * `plugin_key` est une chaine simple, confrontee a config/loop_plugins.php par
 * LoopPluginRegistry. Pas d'enum, pas de cle etrangere : un plugin retire du
 * catalogue doit cesser de s'appliquer en silence, pas casser une ligne.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_loop_plugins', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Le tenant. Une Organization supprimee emporte ses disponibilites,
            // qui n'ont plus de sens sans elle.
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();

            $table->string('plugin_key');

            $table->boolean('available')->default(false);

            // Qui a decide. `nullOnDelete` : la trace d'une decision survit au
            // depart de son auteur, elle devient simplement anonyme.
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // UNE decision par couple. C'est la garde qui empeche deux lignes
            // contradictoires pour la meme Organization — et c'est elle qui
            // rend `updateOrCreate` sur ce couple honnete.
            $table->unique(['organization_id', 'plugin_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_loop_plugins');
    }
};
