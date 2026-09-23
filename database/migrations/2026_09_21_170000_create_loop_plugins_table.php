<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1616 — un plugin est-il ACTIVE dans cette Boucle ?
 *
 * Troisieme maillon de la chaine posee par la Product Spec V0 :
 *
 *     catalogue plateforme  ->  disponibilite Organization  ->  ACTIVATION BOUCLE
 *          (fichier)              (organization_loop_plugins)      (ici)
 *
 * Meme grammaire que `organization_loop_plugins` (TASK-1614), et pour les
 * memes raisons : cle technique en chaine confrontee au catalogue, un etat
 * explicite, une trace de qui a decide.
 *
 * FERME PAR DEFAUT : ligne absente = plugin non actif. Une capacite
 * experimentale ne s'allume que par un geste.
 *
 * Eteindre n'efface pas la ligne — `enabled = false` garde `updated_by` et
 * `updated_at`. Et surtout : eteindre ici n'efface AUCUNE instruction dans
 * `loop_ai_assistants`. Rallumer une Boucle doit lui rendre ses postures, pas
 * la renvoyer aux defauts.
 *
 * `organization_id` est PORTE en plus de `loop_id`, alors qu'il est deductible
 * de la Boucle. C'est deliberé : la garde de tenant se lit alors dans la
 * requete elle-meme, sans jointure, comme `loop_invitations` et
 * `loop_events`. Une Boucle appartient a exactement une Organization —
 * Loop != Tenant, Organization = Tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loop_plugins', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('loop_id')->constrained('loops')->cascadeOnDelete();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();

            $table->string('plugin_key');

            $table->boolean('enabled')->default(false);

            // TASK-1614 : une FK vers `users` se DECLARE dans
            // UserDataLifecycleRegistry. `nullOnDelete` — la decision survit a
            // son auteur, anonymement.
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // UNE decision par couple : c'est cette garde qui rend
            // `updateOrCreate` honnete.
            $table->unique(['loop_id', 'plugin_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loop_plugins');
    }
};
