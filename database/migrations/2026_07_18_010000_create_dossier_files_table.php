<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dossier_files', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('dossier_id')->nullable()->constrained('dossiers')->nullOnDelete();
            $table->foreignUuid('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('disk');
            $table->string('path');
            $table->string('original_name');
            $table->string('display_name');
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes');
            $table->string('checksum_sha256', 64);
            $table->string('source')->default('upload');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'dossier_id']);
            $table->index(['organization_id', 'uploaded_by']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dossier_files');
    }
};
