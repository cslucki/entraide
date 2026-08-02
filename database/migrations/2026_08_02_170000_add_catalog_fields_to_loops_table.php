<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1075 — premium catalog fields.
 *
 * access_mode default 'request' applies to future inserts. Existing rows are
 * backfilled from their current visibility so behavior stays compatible:
 * public (had an immediate "Rejoindre" button) -> open ;
 * private (had no join mechanism at all) -> invitation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loops', function (Blueprint $table) {
            $table->string('tagline', 140)->nullable()->after('description');
            $table->string('cover_image_path')->nullable()->after('tagline');
            $table->string('access_mode', 20)->default('request')->after('visibility');
        });

        DB::table('loops')->where('visibility', 'public')->update(['access_mode' => 'open']);
        DB::table('loops')->where('visibility', 'private')->update(['access_mode' => 'invitation']);
    }

    public function down(): void
    {
        Schema::table('loops', function (Blueprint $table) {
            $table->dropColumn(['tagline', 'cover_image_path', 'access_mode']);
        });
    }
};
