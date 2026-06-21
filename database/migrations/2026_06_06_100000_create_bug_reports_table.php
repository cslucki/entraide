<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bug_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('reporter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason');
            $table->text('details');
            $table->text('page_url')->nullable();
            $table->text('user_agent')->nullable();
            $table->string('status')->default('pending');
            $table->text('resolution_notes')->nullable();
            $table->timestamp('fixed_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bug_reports');
    }
};
