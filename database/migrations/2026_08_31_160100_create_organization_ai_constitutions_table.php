<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1348 : Constitution IA d'une ORGANIZATION, versionnee et optionnelle.
 *
 * Organization = Tenant : `organization_id` est OBLIGATOIRE ici, jamais
 * nullable. Une seule version `active` par Organization ; les precedentes
 * passent `superseded` et sont conservees.
 *
 * Table DISTINCTE de `organization_ai_doctrines` : la Constitution repond a
 * « qui sommes-nous et quels principes fondamentaux gouvernent notre IA ? »,
 * la Doctrine a « comment voulons-nous que l'IA se comporte dans notre
 * metier ? ». Les deux sont composees, la Constitution AU-DESSUS.
 *
 * Meme forme que la doctrine, volontairement : meme index unique partiel,
 * meme historique, meme audit d'auteur. Ce qui est deja eprouve n'est pas
 * reinvente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_ai_constitutions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->text('body');
            $table->string('status', 20)->index();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'version']);
            $table->index(['organization_id', 'status']);
        });

        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement(
                "CREATE UNIQUE INDEX organization_ai_constitutions_one_active_per_organization ON organization_ai_constitutions (organization_id) WHERE status = 'active'"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_ai_constitutions');
    }
};
