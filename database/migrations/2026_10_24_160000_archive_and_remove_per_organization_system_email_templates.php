<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1694 bug S — les gabarits rattaches a une Organization sont ARCHIVES
 * puis retires.
 *
 * ## La demande
 *
 * Cyril : « Il faudrait qu'on ait uniquement des emails de type Global [...] Je
 * pense qu'il faudra prevoir une suppression dans la base en prod. »
 *
 * ## Pourquoi une table d'archive, et pas un simple `delete()`
 *
 * Une migration qui supprime n'a pas de `down()` possible : les lignes sont
 * parties, et le retour ne peut plus que mentir. L'archive rend la suppression
 * REVERSIBLE — c'est la seule facon de detruire des donnees de production sans
 * paris.
 *
 * Elle sert aussi de PREUVE : ce qui a ete retire reste lisible, avec sa date,
 * pour qui voudra verifier qu'on n'a rien perdu.
 *
 * ## Mesure avant de detruire, et elle doit etre REFAITE en production
 *
 * Local, le 23/10 : **22 lignes rattachees, 22 identiques au catalogue, 0
 * modifiee**. Aucune personnalisation humaine — la suppression ne detruit donc
 * rien que le catalogue ne redonne.
 *
 * **Cette mesure ne vaut que pour la base ou elle a ete faite.** Avant de jouer
 * cette migration en production, refaire le comptage (la commande est dans le
 * fichier TASK) : si une Organization a reellement edite un gabarit, il faut
 * d'abord decider — promouvoir son texte en Global, ou la prevenir. L'archive
 * protege de l'irreversible, pas de la surprise.
 *
 * ## Elle n'est sure que depuis les bugs M, P et Q
 *
 * - **M** : une ligne Global est enfin LUE (sept resolveurs sur neuf ne
 *   regardaient que le tenant) ;
 * - **Q** : les 18 lignes Global existent et sont actives ;
 * - **P** : un gabarit de base part habille, donc les activer ne deshabille
 *   rien.
 *
 * Jouee avant ces trois-la, elle aurait fait taire toute la base.
 */
return new class extends Migration
{
    private const ARCHIVE = 'system_email_templates_archive';

    private const COLONNES = [
        'id', 'organization_id', 'locale', 'slug', 'name',
        'subject', 'content_html', 'variables', 'enabled',
        'created_at', 'updated_at',
    ];

    public function up(): void
    {
        if (! Schema::hasTable(self::ARCHIVE)) {
            Schema::create(self::ARCHIVE, function (Blueprint $table) {
                // Pas de contrainte etrangere sur `organization_id` : une
                // archive doit survivre a la suppression de l'Organization dont
                // elle garde la trace. Une cle etrangere la ferait disparaitre
                // au moment ou elle devient le seul temoin.
                // `system_email_templates.id` est un BIGINT, pas un uuid —
                // ce modele n'a pas `HasUuids`, contrairement a `EmailTemplate`
                // qui lui ressemble de nom. Premiere version de cette migration
                // ecrite en `uuid`, et PostgreSQL l'a refusee : « invalid input
                // syntax for type uuid: "18" ». SQLite l'aurait avalee en
                // silence (il compare comme du texte), et l'erreur ne serait
                // apparue qu'en production. Type lu dans
                // `information_schema`, pas suppose.
                $table->unsignedBigInteger('id')->primary();
                $table->uuid('organization_id')->nullable()->index();
                $table->string('locale')->nullable();
                $table->string('slug')->index();
                $table->string('name')->nullable();
                $table->string('subject')->nullable();
                $table->text('content_html')->nullable();
                $table->text('variables')->nullable();
                $table->boolean('enabled')->default(false);
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->timestamp('archived_at');
            });
        }

        $aRetirer = DB::table('system_email_templates')
            ->whereNotNull('organization_id')
            ->get(self::COLONNES);

        if ($aRetirer->isEmpty()) {
            return;
        }

        foreach ($aRetirer->chunk(100) as $lot) {
            DB::table(self::ARCHIVE)->insertOrIgnore(
                $lot->map(fn (object $ligne) => ((array) $ligne) + ['archived_at' => now()])->all(),
            );
        }

        DB::table('system_email_templates')
            ->whereIn('id', $aRetirer->pluck('id'))
            ->delete();
    }

    /**
     * Remet en place ce qui a ete archive, et n'ecrase rien.
     *
     * `insertOrIgnore` : si quelqu'un a recree une ligne a la main depuis
     * l'aller, la sienne gagne. Un retour qui ecraserait une edition humaine
     * serait pire que pas de retour du tout.
     *
     * La table d'archive n'est PAS supprimee : elle garde la trace de ce qui
     * s'est passe, et la rejouer est sans effet.
     */
    public function down(): void
    {
        if (! Schema::hasTable(self::ARCHIVE)) {
            return;
        }

        $archivees = DB::table(self::ARCHIVE)->get(self::COLONNES);

        foreach ($archivees->chunk(100) as $lot) {
            DB::table('system_email_templates')->insertOrIgnore($lot->map(
                fn (object $ligne) => (array) $ligne,
            )->all());
        }
    }
};
