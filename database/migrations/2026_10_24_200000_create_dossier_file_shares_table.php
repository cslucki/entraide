<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1695 geste A — une autorisation posee sur UN FICHIER.
 *
 * ## Ce que cette table rend possible
 *
 * Le partage vivait jusqu'ici sur le DOSSIER (`dossier_members`). Mesure du
 * 24/10 sur OFSH : la creation de dossiers a ete supprimee (TASK-1629) et
 * « Mes documents » ne peut pas etre une ancre de partage (TASK-1136) — un
 * membre n'avait donc AUCUN chemin pour partager un fichier.
 *
 * ## Ce qu'elle N'EST PAS
 *
 * Pas un `dossier_members` de plus. Arbitrage de Cyril (chemin « C ») :
 * LECTURE SEULE, pas de re-partage, pas d'heritage. Il n'y a donc ni colonne
 * `role` ni notion de niveau : une ligne existe, ou elle n'existe pas. Ajouter
 * un role « au cas ou » inventerait une question que personne n'a posee, et
 * chaque lecteur du code devrait ensuite deviner ce qu'un role vaut.
 *
 * ## `organization_id` est PORTE, pas deduit
 *
 * Meme doctrine que les 40 modeles de `HasOrganizationId`, et meme lecon que
 * TASK-1691 : une frontiere de tenant qui se deduit du client n'est pas une
 * frontiere. La colonne est NOT NULL et contrainte.
 *
 * ## Le partage MEURT avec le fichier
 *
 * `ON DELETE CASCADE` sur `dossier_file_id`. C'est la regle que Cyril a posee —
 * « le partage suit le fichier » — et sa consequence : un fichier supprime ne
 * laisse pas derriere lui une autorisation qui ne designe plus rien.
 *
 * Idem pour la personne (`user_id`) : un compte supprime emporte les partages
 * qu'on lui avait faits. `shared_by` est en revanche `SET NULL` — la trace du
 * partage survit a celui qui l'a pose, comme `added_by` sur `dossier_members`.
 *
 * ## L'unicite
 *
 * (fichier, personne) : partager deux fois la meme chose a la meme personne
 * n'a pas de sens, et deux lignes rendraient la revocation ambigue — il
 * faudrait en retirer une puis decouvrir que l'acces tient toujours.
 */
return new class extends Migration
{
    private const MARQUE = '2026_10_24_200000_create_dossier_file_shares_table';

    private const CONSERVATION = 'rollback_conservation';

    public function up(): void
    {
        // TASK-1711 — la table de conservation existe des l'aller, vide.
        //
        // La creer seulement au `down()` la rendait absente de toute base migree
        // normalement : declaree au registre d'integrite mais inexistante, elle
        // faisait rendre 500 a l'ecran « Integrite des donnees ». Et un `down()`
        // qui doit d'abord creer une table est plus fragile qu'un `down()` qui
        // ecrit dans une table deja la.
        $this->assurerLaConservation();

        Schema::create('dossier_file_shares', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('dossier_file_id');
            $table->uuid('user_id');
            $table->uuid('shared_by')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('dossier_file_id')->references('id')->on('dossier_files')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('shared_by')->references('id')->on('users')->nullOnDelete();

            $table->unique(['dossier_file_id', 'user_id']);

            // Les deux lectures du produit : « qu'a-t-on partage avec moi ? »
            // (user_id) et « qui a acces a ce fichier ? » (dossier_file_id, deja
            // couvert par l'index unique). Le tenant est borne a chaque requete,
            // d'ou l'index qui le prefixe.
            $table->index(['organization_id', 'user_id']);
        });
    }

    public function down(): void
    {
        // TASK-1711 — conserver AVANT de detruire.
        //
        // La table entiere disparait, et avec elle tous les partages de
        // fichiers que les membres ont crees pendant la vie de 1.709. Aucun
        // dump pris avant le deploiement ne les contient.
        if (Schema::hasTable('dossier_file_shares')) {
            $this->conserverOuEchouer(
                'dossier_file_shares',
                DB::table('dossier_file_shares')->orderBy('id')->get(),
                'id',
            );
        }

        Schema::dropIfExists('dossier_file_shares');
    }

    /**
     * La conservation partagee des rollbacks de la release 1.709 (TASK-1711).
     *
     * Quatre `down()` de cette release etaient purement destructeurs : ils
     * supprimaient des donnees que leur `up()` ou les utilisateurs avaient
     * creees, et aucun dump pris AVANT le deploiement ne pouvait les couvrir.
     * Decision de Cyril : « je refuse toute perte de donnees implicite ».
     *
     * Chaque migration depose donc, AVANT de detruire, les lignes concernees
     * dans cette table, et ne lit jamais que SES propres depots — d'ou la
     * colonne `migration`. Si la conservation ne peut pas etre prouvee, le
     * `down()` ECHOUE et ne detruit rien.
     *
     * Le bloc est recopie dans les quatre fichiers, deliberement : une
     * migration ne doit pas dependre d'une classe applicative qui peut changer,
     * ou disparaitre, apres son execution. Meme doctrine qu'en TASK-1709.
     */
    private function assurerLaConservation(): void
    {
        if (Schema::hasTable(self::CONSERVATION)) {
            return;
        }

        Schema::create(self::CONSERVATION, function (Blueprint $table) {
            $table->bigIncrements('conservation_id');
            $table->string('migration');
            $table->string('surface');
            $table->string('cle')->nullable();
            $table->json('donnees');
            $table->timestamp('archived_at');
            $table->index(['migration', 'surface']);
        });
    }

    /**
     * Conserve des lignes, et n'en detruit aucune si la conservation echoue.
     *
     * Ce qui protege VRAIMENT, et c'est mesure : l'insertion PRECEDE le `drop`.
     * Si elle echoue, PDO leve, le `down()` s'interrompt, et la donnee est
     * encore la. C'est la demande de Cyril — « echouer explicitement » plutot
     * que detruire a l'aveugle. Un sabotage qui remonte le `drop` au-dessus de
     * la conservation fait rougir deux gardes.
     *
     * La relecture qui suit, et l'exception qu'elle peut lever, DOUBLENT cette
     * protection sans rien y ajouter d'observable : une insertion partielle qui
     * ne leverait pas n'existe pas avec PDO. Les deux sabotages qui la
     * neutralisent — retirer l'interruption, remplacer la relecture par le
     * compteur local — laissent les neuf gardes VERTES. Elle est donc
     * defensive, pas load-bearing, et mieux vaut l'ecrire que de pretendre
     * l'avoir prouvee : son role est de faire echouer bruyamment une
     * modification future de ce bloc qui casserait l'insertion en silence.
     *
     * La relecture porte sur `conservation_id > $borne`, jamais sur un
     * horodatage : deux rollbacks successifs tombent dans la MEME seconde, et
     * une borne temporelle comptait alors les depots du passage precedent — ce
     * `down()` s'interrompait en annoncant une perte qui n'existait pas. Une
     * garde l'a trouve.
     *
     * Une surface vide ne conserve rien et n'echoue pas : il n'y a rien a
     * perdre.
     *
     * @param  Collection<int, object>  $lignes
     */
    private function conserverOuEchouer(string $surface, Collection $lignes, string $clef): void
    {
        if ($lignes->isEmpty()) {
            return;
        }

        $this->assurerLaConservation();

        $attendu = $lignes->count();

        // La borne est l'IDENTIFIANT du dernier depot, pas un horodatage.
        //
        // `archived_at >= now()` semblait suffire et ne l'etait pas : deux
        // rollbacks successifs tombent dans la MEME seconde, la relecture
        // comptait alors les depots du passage precedent, et ce `down()`
        // s'interrompait en annoncant une perte qui n'existait pas. Un
        // discriminant temporel ne separe pas deux instants ; une sequence,
        // oui.
        $borne = (int) (DB::table(self::CONSERVATION)->max('conservation_id') ?? 0);

        foreach ($lignes->chunk(200) as $lot) {
            DB::table(self::CONSERVATION)->insert($lot->map(fn (object $ligne) => [
                'migration' => self::MARQUE,
                'surface' => $surface,
                'cle' => (string) ($ligne->{$clef} ?? ''),
                'donnees' => json_encode((array) $ligne, JSON_UNESCAPED_UNICODE),
                'archived_at' => now(),
            ])->all());
        }

        $conserve = DB::table(self::CONSERVATION)
            ->where('migration', self::MARQUE)
            ->where('surface', $surface)
            ->where('conservation_id', '>', $borne)
            ->count();

        if ($conserve !== $attendu) {
            throw new RuntimeException(
                "TASK-1711 — ROLLBACK INTERROMPU, aucune donnee detruite.\n"
                ."Surface « {$surface} » : {$attendu} ligne(s) a conserver, {$conserve} relue(s) "
                .'dans « '.self::CONSERVATION." ».\n"
                ."Ce `down()` detruirait des donnees qu'il ne sait pas rendre. Procedure :\n"
                .'  1. verifier que la table « '.self::CONSERVATION." » est accessible en ecriture ;\n"
                ."  2. exporter la surface a la main : pg_dump -Fc -t <table> ;\n"
                ."  3. relancer le rollback, ou arbitrer la perte avec le responsable de la release.\n"
                .'Reference : TASK-1710, runbook de la release 1.709, etape 3.0.'
            );
        }
    }
};
