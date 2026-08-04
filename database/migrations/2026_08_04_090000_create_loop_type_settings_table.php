<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loop_type_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // A plain string validated against config/loop_types.php. No enum
            // and no foreign key: a fifth type must stay a one-file change, and
            // a key retired from the registry must not break existing rows.
            $table->string('loop_type')->unique();
            // Card preset. Null means "use the configured default" — the row
            // may exist only to carry `available`, and vice versa.
            $table->json('cards')->nullable();
            $table->boolean('available')->nullable();
            $table->timestamps();
        });

        // Sparse, like loop_permission_settings: a row exists only where a
        // super-admin deliberately departed from config/loop_types.php, and
        // returning to the default deletes it rather than writing the default
        // back. Nothing is ever materialised.
        //
        // Deliberately no organization_id and no loop_id. Types are a platform
        // matter: an Organization does not define what a Loop type is, and a
        // per-Loop type preset does not exist by design.
    }

    public function down(): void
    {
        Schema::dropIfExists('loop_type_settings');
    }
};
