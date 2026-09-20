<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1611 — UN visuel facultatif par atelier, et rien de plus.
 *
 * Trois colonnes, pas une table : un atelier porte AU PLUS un flyer, jamais
 * une galerie. `flyer_path` est le chemin RELATIF sur le disque `public`
 * (meme convention que l'image d'un article de blog, `posts.image`) ; l'URL
 * publique se construit par `Storage::disk('public')->url()`, donc sur
 * `APP_URL` et jamais sur l'hote de la requete.
 *
 * `flyer_width` / `flyer_height` sont releves UNE FOIS a l'upload plutot que
 * relus a chaque rendu : ils alimentent `og:image:width` / `og:image:height`,
 * que les reseaux lisent pour reserver la vignette avant d'avoir telecharge
 * l'image. Les relire a chaque affichage couterait un acces disque par page
 * publique et cesserait de fonctionner si le disque `public` passait sur S3.
 *
 * Aucune autre colonne : ni variante, ni recadrage, ni ordre, ni legende.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workshops', function (Blueprint $table) {
            $table->string('flyer_path', 2048)->nullable()->after('locale');
            $table->unsignedSmallInteger('flyer_width')->nullable()->after('flyer_path');
            $table->unsignedSmallInteger('flyer_height')->nullable()->after('flyer_width');
        });
    }

    public function down(): void
    {
        Schema::table('workshops', function (Blueprint $table) {
            $table->dropColumn(['flyer_path', 'flyer_width', 'flyer_height']);
        });
    }
};
