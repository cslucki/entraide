<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1695 (point 5a de Cyril) — partager un fichier dans une BOUCLE.
 *
 * ## Une table a part, pas une colonne de plus
 *
 * `dossier_file_shares` porte l'unicite `(dossier_file_id, user_id)`. Y poser
 * un `loop_id` nullable aurait rendu `user_id` nullable a son tour — et deux
 * NULL ne collisionnent pas en PostgreSQL : l'unicite serait devenue un voeu.
 * Le modele Dossier fait deja cette separation (`dossier_members` d'un cote,
 * `shared_with_loop_id` de l'autre) : les fichiers la reprennent.
 *
 * ## Le destinataire est la BOUCLE, pas ses membres du moment
 *
 * Aucune ligne par membre : l'acces se resout a la LECTURE (membre actif de la
 * Boucle, policy), jamais a l'ecriture. Copier les membres au moment du
 * partage aurait fige la liste — un arrivant n'aurait rien vu, un partant
 * aurait garde l'acces.
 *
 * ## Memes regles de survie que les partages nominatifs
 *
 * Cascade sur le fichier, la Boucle et l'Organization ; `shared_by` en
 * SET NULL — la trace du geste survit au depart de son auteur, le partage
 * reste valide. Le registre du cycle de vie declare cette colonne DANS LE MEME
 * COMMIT : la CI a deja rejete cette TASK une fois pour deux FK non declarees.
 */
return new class extends Migration
{
    private const MARQUE = '2026_10_24_210000_create_dossier_file_loop_shares_table';

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

        Schema::create('dossier_file_loop_shares', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('dossier_file_id');
            $table->uuid('loop_id');
            $table->uuid('shared_by')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('dossier_file_id')->references('id')->on('dossier_files')->cascadeOnDelete();
            $table->foreign('loop_id')->references('id')->on('loops')->cascadeOnDelete();
            $table->foreign('shared_by')->references('id')->on('users')->nullOnDelete();

            $table->unique(['dossier_file_id', 'loop_id']);
            // « Quels fichiers pour cette Boucle ? » — la question de l'ecran
            // « Partages » d'un membre.
            $table->index(['organization_id', 'loop_id']);
        });
    }

    public function down(): void
    {
        // TASK-1711 — conserver AVANT de detruire. Voir la migration soeur
        // `create_dossier_file_shares_table` : meme geste, autre destinataire —
        // ici le partage vise une Boucle, pas un membre.
        if (Schema::hasTable('dossier_file_loop_shares')) {
            $this->conserverOuEchouer(
                'dossier_file_loop_shares',
                DB::table('dossier_file_loop_shares')->orderBy('id')->get(),
                'id',
            );
        }

        Schema::dropIfExists('dossier_file_loop_shares');
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
