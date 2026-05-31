<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('boucle_name');
            $table->string('contact_name');
            $table->string('contact_email');
            $table->text('description')->nullable();
            $table->string('context')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_requests');
    }
};
