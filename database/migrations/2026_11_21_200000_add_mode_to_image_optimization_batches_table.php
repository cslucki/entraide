<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1875 — mode d'execution d'un lot d'optimisation d'images.
 *
 *   `queue`  (defaut) : lot T1873, un job par image sur la queue `images` ;
 *   `manual`          : lot pilote depuis le navigateur du SuperAdmin, UNE
 *                       image par requete HTTP, sans worker.
 *
 * Purement additive : colonne NON NULL avec valeur par defaut constante, les
 * lots existants deviennent `queue`, sans reecriture de table sur PostgreSQL
 * (valeur par defaut constante, metadonnee seule depuis PostgreSQL 11).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('image_optimization_batches', function (Blueprint $table) {
            $table->string('mode', 10)->default('queue')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('image_optimization_batches', function (Blueprint $table) {
            $table->dropColumn('mode');
        });
    }
};
