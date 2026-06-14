<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE point_ledger DROP CONSTRAINT IF EXISTS point_ledger_reason_check');
        }
    }

    public function down(): void {}
};
