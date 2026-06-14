<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('point_ledger', function (Blueprint $table) {
            $table->string('reason', 50)->change();
        });
    }

    public function down(): void
    {
        Schema::table('point_ledger', function (Blueprint $table) {
            $table->enum('reason', ['welcome_bonus', 'exchange_earned', 'exchange_spent', 'adjustment'])->change();
        });
    }
};
