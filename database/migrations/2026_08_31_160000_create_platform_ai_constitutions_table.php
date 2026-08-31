<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1348 : Constitution IA de la PLATEFORME, versionnee.
 *
 * Une ligne = une version. Une seule version `active` a l'echelle de la
 * plateforme ; les precedentes passent `superseded` et sont conservees
 * (historique, auteur, horodatage). Additive : rien n'est modifie ailleurs.
 *
 * Pourquoi une table DEDIEE plutot qu'une colonne `organization_id nullable`
 * dans `organization_ai_doctrines` ou dans la table Organization ci-contre :
 * l'index unique partiel qui garantit « une seule active » porte sur
 * `(organization_id)`. En SQL, `NULL` n'est jamais egal a `NULL` : une ligne
 * globale a `organization_id = NULL` echapperait a la garantie, et la base
 * accepterait autant de constitutions plateforme actives qu'on veut. Ici
 * l'index porte sur `status` seul, et la garantie est exacte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_ai_constitutions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedInteger('version')->unique();
            $table->text('body');
            $table->string('status', 20)->index();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();
        });

        // Garantie AU NIVEAU BASE : au plus UNE version active pour toute la
        // plateforme. La primitive d'ecriture serialise deja les ecrivains ;
        // ceci empeche qu'un chemin futur ou une course en reintroduise deux.
        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement(
                "CREATE UNIQUE INDEX platform_ai_constitutions_one_active ON platform_ai_constitutions (status) WHERE status = 'active'"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_ai_constitutions');
    }
};
