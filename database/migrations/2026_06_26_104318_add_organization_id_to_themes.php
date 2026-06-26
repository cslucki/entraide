<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('themes', function (Blueprint $table) {
            $table->foreignUuid('organization_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        $mainOrg = DB::table('organizations')->orderBy('created_at')->first();
        if ($mainOrg) {
            DB::table('themes')->whereNull('organization_id')->update(['organization_id' => $mainOrg->id]);
        }
    }

    public function down(): void
    {
        Schema::table('themes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('organization_id');
        });
    }
};
