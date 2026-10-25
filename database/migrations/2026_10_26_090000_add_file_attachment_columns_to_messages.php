<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
    public function up(): void
    {
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
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['file_path', 'file_disk', 'file_name', 'file_mime', 'file_size']);
        });
    }
};
