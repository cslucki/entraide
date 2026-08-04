<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loop_cards', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('loop_id')->constrained('loops')->cascadeOnDelete();
            // Plain string, matching a key of config/loop_cards.php. Not a foreign
            // key and not an enum: the catalogue lives in configuration, and a card
            // that disappears from it must not break existing rows.
            $table->string('card_key');
            $table->boolean('enabled')->default(true);
            // Which Loop type preset put it there, or null when a human added it —
            // this is what lets the admin screens tell a type's baseline apart from
            // a Loop's own customisation.
            $table->string('added_by_preset')->nullable();
            $table->timestamps();

            $table->unique(['loop_id', 'card_key']);
            $table->index(['organization_id', 'loop_id']);
        });

        // Until now every Loop showed exactly the same four cards, straight from
        // config/loop_cards.php, with no per-Loop record at all. A Loop type that
        // means anything needs somewhere to record composition, and "Cards liées"
        // in the admin screens needs something to read.
    }

    public function down(): void
    {
        Schema::dropIfExists('loop_cards');
    }
};
