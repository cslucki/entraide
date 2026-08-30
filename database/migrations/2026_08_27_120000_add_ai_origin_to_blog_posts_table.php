<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1310 : provenance d'un Article ne au ChatLoop — `blog_posts.ai_origin`.
 *
 * ADDITIVE, NULLABLE, et rien d'autre. Aucune colonne existante n'est touchee,
 * aucune donnee n'est reecrite, aucun backfill : tout Article deja en base
 * reste `ai_origin = null`, ce qui est exactement sa verite — il n'est pas ne
 * d'une reponse IA.
 *
 * ## Pourquoi une colonne, et pourquoi ICI
 *
 * L'audit T1310 a etabli qu'aucun emplacement libre n'existait :
 * `dossier_blog_posts` n'a pas de `metadata`, `blog_posts` n'a aucune colonne
 * JSON, et il n'existe aucune table de metadonnees generique. Persister la
 * provenance exigeait donc une migration — rapportee et **explicitement
 * autorisee** par Cyril/ChatGPT avant ecriture (GO du 2026-08-27, section 9 du
 * brief : « si une migration devient necessaire : STOP et rapporter »).
 *
 * Sur l'ARTICLE plutot que sur le pivot Dossier <-> Article : l'origine est une
 * propriete de l'ŒUVRE. Un Article detache d'un Dossier et rattache ailleurs
 * reste une synthese IA ; portee par le pivot, l'information disparaitrait a ce
 * moment-la. Et l'indexeur la lit sur la ligne qu'il tient deja, sans jointure.
 *
 * ## Ce que la colonne porte
 *
 * `null`  = Article ordinaire — ecrit ou televerse par un humain. La
 *           SOURCE PRIMAIRE du corpus.
 * objet   = Article ne d'une reponse IA, relu et valide par un humain :
 *
 *   {
 *     "origin_type": "ai_synthesis_human_validated",
 *     "source_loop_message_id": "...",   // la bulle IA capitalisee
 *     "source_loop_id": "...",
 *     "ai_interaction_id": "...",        // null si la bulle n'en portait pas
 *     "ai_mode": "llm" | "rag" | "llm_rag",
 *     "human_curator_id": "...",         // celui qui a relu et valide
 *     "sources": [ ... ]                 // forme publique T1297/T1309 : les
 *                                        // sources REELLEMENT citees, jamais
 *                                        // les seulement consultees
 *   }
 *
 * ## Ce que cette colonne NE fait PAS (encore)
 *
 * Elle ne pondere rien, ne filtre rien, n'exclut rien du RAG. Elle **preserve
 * l'information** qui permettra de distinguer plus tard source primaire et
 * synthese IA — la boucle synthetique (IA -> Article -> index -> RAG -> IA)
 * est un risque reel, mais sa mitigation est une decision produit distincte,
 * a instruire quand il existera de vraies syntheses a observer. Ecrire la
 * distinction est irreversible si on l'oublie ; l'exploiter ne l'est pas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('blog_posts', function (Blueprint $table): void {
            // `json()` rend `jsonb` sur PostgreSQL et `text` sur SQLite : les
            // deux moteurs de la CI acceptent la meme declaration, et le cast
            // `array` du modele les lit de la meme facon.
            $table->json('ai_origin')->nullable()->after('listed_in_blog');
        });
    }

    public function down(): void
    {
        Schema::table('blog_posts', function (Blueprint $table): void {
            $table->dropColumn('ai_origin');
        });
    }
};
