<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            Schema::ensureVectorExtensionExists();
        }

        Schema::create('dossier_chunks', function (Blueprint $table) use ($driver) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('dossier_id')->constrained('dossiers')->cascadeOnDelete();
            $table->foreignUuid('blog_post_id')->constrained('blog_posts')->cascadeOnDelete();
            $table->unsignedInteger('chunk_index');
            $table->text('content');
            $table->char('content_hash', 64);
            $table->unsignedInteger('token_count')->nullable();

            if ($driver === 'pgsql') {
                $table->vector('embedding', dimensions: 1536);
            } else {
                $table->text('embedding');
            }

            $table->string('embedding_provider');
            $table->string('embedding_model');
            $table->timestamp('indexed_at');
            $table->timestamps();

            $table->index('organization_id');
            $table->index('dossier_id');
            $table->index('blog_post_id');
            $table->index(['organization_id', 'dossier_id']);
            $table->unique([
                'dossier_id',
                'blog_post_id',
                'chunk_index',
                'embedding_provider',
                'embedding_model',
            ], 'dossier_chunks_unique_chunk_identity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dossier_chunks');
    }
};
