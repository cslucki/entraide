<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1617 — quel modele OpenRouter sert quel assistant. PLATEFORME.
 *
 * Ni `organization_id`, ni `loop_id`, et c'est la garde : cette table decrit
 * l'INFRASTRUCTURE IA du plugin, decidee par le SuperAdmin pour toute la
 * plateforme. Les POSTURES, elles, sont Loop-scoped et vivent dans
 * `loop_ai_assistants` (TASK-1616). Melanger les deux ferait d'un reglage
 * d'infrastructure un etat de tenant.
 *
 * `verified_free_at` est une PREUVE DATEE, pas un drapeau. Sa presence ne
 * suffit pas : une preuve se perime (15 minutes, cf.
 * `LoopPluginAiModels::FREE_PROOF_TTL_SECONDS`), et un modele dont la preuve
 * a expire n'est pas eligible tant qu'un releve n'a pas reussi. `null`
 * signifie « jamais verifie », donc jamais eligible.
 *
 * `model_slug` est l'identite fonctionnelle, et la seule metadonnee stockee :
 * nom, contexte et tarifs restent chez OpenRouter, releves a la demande. Les
 * recopier ici creerait une seconde verite, perimee des le lendemain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loop_plugin_ai_models', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('plugin_key');
            $table->string('assistant_key');

            // `provider` est stocke bien qu'une seule valeur existe en V0 :
            // le jour ou un second provider apparait, la colonne est deja la,
            // et surtout la ligne DIT de quel provider le slug est
            // l'identifiant. Un slug seul serait ambigu.
            $table->string('provider')->default('openrouter');
            $table->string('model_slug');

            // `timestamp`, PAS `timestampTz`, et c'est un CORRECTIF trouve par
            // la recette : avec `timestampTz`, PostgreSQL rend la valeur dans
            // le fuseau de la SESSION (`+02:00` en CEST) alors que
            // `Carbon::now()` est en UTC. Une preuve ecrite a l'instant se
            // lisait donc DEUX HEURES dans le passe, donc toujours perimee —
            // la garde aurait refuse toute generation pendant l'heure d'ete.
            //
            // Toutes les autres colonnes de date du depot sont des `timestamp`
            // nus, dans la convention UTC de l'application. SQLite ignore les
            // fuseaux : le defaut est INVISIBLE en local et n'existe qu'en
            // PostgreSQL.
            $table->timestamp('verified_free_at')->nullable();

            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // UN modele par assistant. C'est cette garde qui rend
            // `updateOrCreate` honnete.
            $table->unique(['plugin_key', 'assistant_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loop_plugin_ai_models');
    }
};
