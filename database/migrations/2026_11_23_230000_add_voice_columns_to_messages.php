<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1886 — les messages vocaux ephemeres de la messagerie privee.
 *
 * Un vocal est un `Message` ordinaire dont le fichier vit dans les colonnes
 * `file_*` de TASK-1704. Ces trois colonnes ne disent que ce qui le distingue :
 *
 * - `voice_expires_at` — l'ECHEANCE, posee une fois a l'envoi (arbitrage
 *   Cyril : 5 jours a compter de l'envoi). Sa presence EST le marqueur
 *   « vocal » : un fichier audio JOINT classique ne la porte jamais, et ne
 *   peut donc jamais etre selectionne par le purgeur. Indexee, parce que le
 *   purgeur la balaie par lot.
 * - `voice_duration` — en secondes. Le navigateur ne la connait pas toujours :
 *   un WebM produit par `MediaRecorder` n'ecrit pas sa duree dans l'en-tete,
 *   et Chrome annonce alors `Infinity` jusqu'a la fin de la lecture.
 * - `voice_purged_at` — le fichier a ete supprime. La ligne reste, pour que la
 *   conversation affiche « Message vocal expire » au lieu d'un trou.
 *
 * Toutes nullables, additives : aucune donnee existante n'est reecrite.
 *
 * `down()` : supprimer ces colonnes ne detruit aucun fichier, et les vocaux
 * restent identifiables par leur prefixe de stockage dedie
 * (`message-voice/`, voir `MessageThread::PREFIXE_VOCAL`). Le rollback PROD
 * reste une PR de revert, jamais `migrate:rollback`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->timestamp('voice_expires_at')->nullable()->after('file_size');
            $table->unsignedSmallInteger('voice_duration')->nullable()->after('voice_expires_at');
            $table->timestamp('voice_purged_at')->nullable()->after('voice_duration');

            $table->index('voice_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropIndex(['voice_expires_at']);
            $table->dropColumn(['voice_expires_at', 'voice_duration', 'voice_purged_at']);
        });
    }
};
