<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dossiers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('visibility')->default('private');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'owner_id']);
            $table->index(['organization_id', 'visibility']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dossiers');
    }
};
