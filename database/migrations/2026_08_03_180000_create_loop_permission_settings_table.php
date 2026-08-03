<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loop_permission_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Plain strings validated against config/loop_types.php and
            // config/loop_permissions.php. No enum, no foreign key: a fifth type
            // must stay a one-file change, and a key retired from the registry
            // must not break existing rows.
            $table->string('loop_type');
            $table->string('loop_role');
            $table->string('permission');
            $table->boolean('allowed');
            $table->timestamps();

            $table->unique(['loop_type', 'loop_role', 'permission']);
        });

        // Deliberately no organization_id and no loop_id. This table holds the
        // super-admin's *global* settings only: Organization overrides live in
        // organizations.loop_permissions, and per-Loop configuration does not
        // exist by design.
        //
        // A row is an explicit override, nothing else. Deleting it returns to
        // the registry default; the matrix is never materialised.
    }

    public function down(): void
    {
        Schema::dropIfExists('loop_permission_settings');
    }
};
