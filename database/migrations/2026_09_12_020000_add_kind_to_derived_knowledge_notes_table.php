<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1540 — une Boucle porte desormais PLUSIEURS enonces adressables.
 *
 * ## Pourquoi aucune table neuve
 *
 * `derived_knowledge_notes` portait deja, PAR LIGNE, tout ce qu'un claim
 * reclame : `subject_key` (une identite), `version`, `status`,
 * `superseded_by_id` (une histoire), `observed_at` / `derived_at` (deux temps
 * distincts), `provenance` (des preuves). Ses deux index uniques incluent deja
 * `subject_key`, et `dossier_chunks.derived_knowledge_note_id` pointe deja une
 * LIGNE — donc un chunk par claim, sans rien changer.
 *
 * Le digest n'occupait qu'une seule valeur de `subject_key`. Passer a N claims
 * ne demande donc pas une structure : cela demande d'arreter de n'en ecrire
 * qu'une.
 *
 * ## Ce que cette colonne ajoute, et pourquoi elle n'est pas cosmetique
 *
 * Deux populations cohabitent le temps de la transition : le digest
 * conversationnel (conteneur, repli, provenance agregee) et les claims. Les
 * distinguer par un `subject_key === 'conversation_digest'` marcherait, mais
 * ferait dependre une regle d'indexation et d'eligibilite d'une comparaison de
 * chaine libre — exactement le genre d'implicite qui diverge au premier ajout
 * de famille.
 *
 * `kind` rend la distinction interrogeable, indexable, et lisible en base.
 *
 * Purement additive : la valeur par defaut `digest` decrit exactement ce que
 * toutes les lignes existantes SONT deja.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('derived_knowledge_notes', function (Blueprint $table) {
            $table->string('kind', 20)->default('digest')->after('source_type');

            // L'indexeur et le retrieval lisent « les claims ACTIFS de cette
            // Organization » : c'est cet acces-la qui merite l'index.
            $table->index(['organization_id', 'kind', 'status'], 'derived_notes_kind_status');
        });
    }

    public function down(): void
    {
        Schema::table('derived_knowledge_notes', function (Blueprint $table) {
            $table->dropIndex('derived_notes_kind_status');
            $table->dropColumn('kind');
        });
    }
};
