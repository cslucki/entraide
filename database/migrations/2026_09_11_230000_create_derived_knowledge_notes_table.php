<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1534 — la premiere connaissance DERIVEE de BouclePro.
 *
 * ## Pourquoi une table, et pas un Article
 *
 * `LoopAnswerCapitalizationService` (T1310) sait deja rendre durable une
 * reponse IA : elle devient un Article, et son auteur est **l'humain qui
 * valide** (`blog_posts.user_id`, invariant ecrit). Deriver automatiquement
 * depuis une conversation ne peut donc pas emprunter ce chemin : il faudrait
 * forger une paternite humaine pour une note que personne n'a ecrite.
 *
 * Cette table dit ce qu'elle est : une note DERIVEE. Elle ne pretend pas etre
 * une Interaction, elle n'a pas d'auteur humain, et sa provenance nomme les
 * messages dont elle est tiree.
 *
 * ## Ce qu'elle doit pouvoir repondre (contrat CDC CORE section 20)
 *
 * quelle Organization · de quelle source · ce qui est cru · quand observe ·
 * quand derive · quelle version · quel statut · quelle provenance · qui peut
 * la voir · ce qui la supersede.
 *
 * ## Deux horodatages, jamais un seul
 *
 * `observed_at` est la date de l'activite humaine ; `derived_at` celle du
 * calcul. Les confondre rendrait impossible toute question temporelle future
 * (« qu'est-ce qui a change depuis mardi ? ») : une reprise de derivation
 * ferait paraitre recent un fait ancien. Le depot distingue deja les deux
 * ailleurs (`loop_decisions.decided_on`, `crm_contact_events.occurred_at`).
 *
 * ## Fraicheur et concurrence
 *
 * `source_fingerprint` est l'empreinte des messages sources. Meme empreinte =
 * rien a recalculer (meme idiome que `dossier_chunks.content_hash`). Une
 * derivation dont l'empreinte decrit un etat anterieur a la version courante
 * ne peut pas gagner : l'index unique partiel n'admet qu'UNE note active par
 * sujet, et la reprise passe par une supersession explicite.
 *
 * Ce patron « version + un seul actif » est deja ecrit trois fois dans le
 * depot (`organization_ai_constitutions`, `organization_ai_doctrines`,
 * `usage_references`) : on le reprend, on n'en invente pas un quatrieme.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('derived_knowledge_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Le cloisonnement est porte par la LIGNE, jamais deduit de la
            // Boucle ou du Dossier (doctrine `loop_decisions`).
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();

            // La famille de source. Une seule valeur aujourd'hui
            // (`loop_conversation`) : la colonne existe pour que la deuxieme
            // n'exige pas une migration de forme.
            $table->string('source_type', 40);

            // La Boucle SOURCE. C'est elle qui decide qui peut lire cette
            // note — jamais le Dossier ou elle est rangee (addendum 1.3).
            $table->foreignUuid('source_loop_id')->nullable()->constrained('loops')->cascadeOnDelete();

            // Le Dossier ou la note est indexee, pour que le moteur
            // documentaire existant la trouve sans second index.
            $table->foreignUuid('dossier_id')->constrained()->cascadeOnDelete();

            // Ce qui identifie LE SUJET de la note : deux derivations du meme
            // sujet se superseden, elles ne s'empilent pas.
            $table->string('subject_key', 190);

            $table->text('content');

            // Empreinte des messages sources — idempotence et fraicheur.
            $table->char('source_fingerprint', 64);

            // source_loop_message_ids[], derived_by, ai_interaction_id...
            $table->jsonb('provenance');

            $table->timestamp('observed_at');
            $table->timestamp('derived_at');

            $table->unsignedInteger('version')->default(1);
            $table->string('status', 20)->default('active');
            $table->uuid('superseded_by_id')->nullable();
            $table->timestamp('superseded_at')->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'source_loop_id']);
            $table->index('dossier_id');
            // `source_loop_id` FAIT PARTIE de l'identite du sujet. Sans lui,
            // une Organization n'aurait qu'UNE seule note active pour TOUTES
            // ses Boucles : la deuxieme Boucle derivee heurtait la contrainte.
            // Mesure sur PostgreSQL, pas relecture — la premiere redaction
            // l'oubliait, et le test l'a dit.
            $table->unique(
                ['organization_id', 'source_type', 'source_loop_id', 'subject_key', 'version'],
                'derived_notes_unique_version',
            );
        });

        // Un seul ACTIF par sujet — porte par la BASE, pas par une convention
        // applicative. PostgreSQL seul sait faire un index unique partiel ;
        // sous SQLite la garde applicative reste, et les tests de contrat
        // deterministe n'en dependent pas.
        //
        // `coalesce(source_loop_id, ...)` plutot que la colonne nue : en SQL,
        // deux NULL ne sont pas egaux, donc une future famille sans Boucle
        // source echapperait entierement a l'unicite. Un UUID nul explicite
        // range tous ces cas dans le MEME seau.
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            Schema::getConnection()->statement(
                "CREATE UNIQUE INDEX derived_notes_one_active
                 ON derived_knowledge_notes (
                     organization_id,
                     source_type,
                     coalesce(source_loop_id, '00000000-0000-0000-0000-000000000000'::uuid),
                     subject_key
                 )
                 WHERE status = 'active'"
            );
        }

        // La TROISIEME famille de chunk. Meme style assume que la migration
        // qui a ajoute `dossier_file_id` : deux FK dediees plutot qu'une
        // colonne polymorphique, et l'invariant « exactement une des trois »
        // reste applicatif, porte par les indexeurs.
        Schema::table('dossier_chunks', function (Blueprint $table) {
            $table->foreignUuid('derived_knowledge_note_id')
                ->nullable()
                ->after('dossier_file_id')
                ->constrained('derived_knowledge_notes')
                ->cascadeOnDelete();

            $table->index('derived_knowledge_note_id');
        });
    }

    public function down(): void
    {
        Schema::table('dossier_chunks', function (Blueprint $table) {
            $table->dropForeign(['derived_knowledge_note_id']);
            $table->dropIndex(['derived_knowledge_note_id']);
            $table->dropColumn('derived_knowledge_note_id');
        });

        Schema::dropIfExists('derived_knowledge_notes');
    }
};
