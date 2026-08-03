<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loop_manifesto_sources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('loop_id')->constrained('loops')->cascadeOnDelete();
            $table->foreignUuid('dossier_file_id')->constrained('dossier_files')->cascadeOnDelete();
            $table->foreignUuid('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // One link per document per Loop: attaching the same source twice is a
            // no-op rather than a duplicate row.
            $table->unique(['loop_id', 'dossier_file_id']);
            $table->index(['organization_id', 'loop_id']);
        });

        // Anchored on loop_id rather than on the designated manifesto article:
        // the designation can be changed or cleared (LoopManifestoCard::designate
        // and ::removeManifesto), and the sources gathered for a Loop's manifesto
        // must survive that. cascadeOnDelete on dossier_file_id means a document
        // removed from Dossiers takes its links with it — no dangling reference,
        // and no copy of the file is ever made here.
    }

    public function down(): void
    {
        Schema::dropIfExists('loop_manifesto_sources');
    }
};
