<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1704 — la messagerie accepte des FICHIERS, pas seulement des images.
 *
 * ## Pourquoi de nouvelles colonnes, et pas `image_path`
 *
 * `image_path` ne porte qu'un chemin. Il suffit a une image parce que le
 * produit la re-encode : toute image de message devient un WebP, dont le nom
 * est un UUID et le type connu d'avance. Un fichier n'a aucune de ces
 * proprietes. On ne le transforme pas, donc il faut retenir ce qu'on en sait :
 *
 * - `file_name` — le nom que l'expediteur lui connait. Sans lui, le
 *   destinataire telechargerait un UUID sans extension. C'est le meme
 *   raisonnement que `dossier_files.original_name`.
 * - `file_mime` — le type DEVINE AU DEPOT, pas celui annonce par le
 *   navigateur. Il sert a decider de l'affichage (apercu ou telechargement) et
 *   a renvoyer un en-tete honnete.
 * - `file_size` — pour afficher le poids avant de telecharger, sur un
 *   telephone ou cela compte.
 * - `file_disk` — le disque qui le detient. Il vaut `message_files`
 *   aujourd'hui ; l'ecrire evite qu'un changement de disque demain rende
 *   illisibles les pieces d'hier.
 *
 * ## Pourquoi `image_path` n'est pas touche
 *
 * Les images continuent leur chemin existant, inchange. Les fusionner aurait
 * demande de reecrire le pipeline WebP et le rendu des bulles, pour un gain
 * nul : deux choses qui se comportent differemment se stockent differemment.
 * Le mandat est clair — corriger le bug demande, sans refactor hors scope.
 *
 * Toutes les colonnes sont NULLABLE : la quasi-totalite des messages n'ont
 * aucune piece jointe, et aucune donnee existante n'est reecrite.
 */
return new class extends Migration
{
    private const MARQUE = '2026_10_26_090000_add_file_attachment_columns_to_messages';

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

        Schema::table('messages', function (Blueprint $table) {
            $table->string('file_path')->nullable()->after('image_path');
            $table->string('file_disk')->nullable()->after('file_path');
            $table->string('file_name')->nullable()->after('file_disk');
            $table->string('file_mime')->nullable()->after('file_name');
            $table->unsignedBigInteger('file_size')->nullable()->after('file_mime');
        });
    }

    public function down(): void
    {
        // TASK-1711 — conserver AVANT de detruire.
        //
        // Les cinq colonnes partent, et avec elles la SEULE reference vers les
        // fichiers deja ecrits sur le disque prive `message_files` : celui-ci
        // n'expose ni route `serve` ni `url` (constat de TASK-1704), donc un
        // fichier dont le `file_path` a disparu est introuvable. Les octets
        // restent sur le disque, orphelins. Conserver les metadonnees est ce
        // qui permet de les retrouver.
        if (Schema::hasColumn('messages', 'file_path')) {
            $this->conserverOuEchouer(
                'messages.file_attachments',
                DB::table('messages')->whereNotNull('file_path')->orderBy('id')
                    ->get(['id', 'file_path', 'file_disk', 'file_name', 'file_mime', 'file_size']),
                'id',
            );
        }

        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['file_path', 'file_disk', 'file_name', 'file_mime', 'file_size']);
        });
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
