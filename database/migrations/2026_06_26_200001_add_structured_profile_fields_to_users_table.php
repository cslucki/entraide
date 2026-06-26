<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name')->nullable()->after('name');
            $table->string('address_line1')->nullable()->after('phone');
            $table->string('address_line2')->nullable()->after('address_line1');
            $table->string('postal_code', 20)->nullable()->after('address_line2');
            $table->string('city')->nullable()->after('postal_code');
            $table->string('country_code', 2)->nullable()->after('city');
            $table->string('preferred_locale', 5)->nullable()->after('country_code');
            $table->string('membership_value')->nullable()->after('preferred_locale');

            $table->foreign('country_code')->references('code')->on('countries')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['country_code']);
            $table->dropColumn([
                'first_name',
                'address_line1',
                'address_line2',
                'postal_code',
                'city',
                'country_code',
                'preferred_locale',
                'membership_value',
            ]);
        });
    }
};
