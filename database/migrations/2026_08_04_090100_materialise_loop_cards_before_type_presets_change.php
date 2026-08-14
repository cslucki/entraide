<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Freeze what existing Loops already show, before the type presets change.
 *
 * A Loop with no row in `loop_cards` falls back to its type's preset — that is
 * how Loops predating the table keep a populated workspace. Redefining the
 * presets (Dialogue keeps only Membres) would therefore have silently stripped
 * cards from every one of those Loops, content included.
 *
 * So the current effective composition is written down first. After this, the
 * new presets apply where they should — to Loops created from now on, and to a
 * type change an administrator asks for — and to nobody retroactively.
 *
 * Additive only: rows are inserted, never updated or deleted. Idempotent: a
 * Loop that already has cards is skipped, so running it twice changes nothing.
 */
return new class extends Migration
{
    /**
     * The presets AS THEY WERE, hardcoded on purpose.
     *
     * A migration must describe the past, not read a config file that has
     * already moved on — reading config/loop_types.php here would apply the new
     * presets and defeat the whole point.
     */
    private const PRESETS_BEFORE = [
        'general' => ['core.ai_summary', 'core.manifesto', 'core.members', 'core.roadmap'],
        'custom' => ['core.ai_summary', 'core.manifesto', 'core.members', 'core.roadmap'],
        'project' => ['core.ai_summary', 'core.manifesto', 'core.members', 'core.roadmap'],
        'training' => ['core.manifesto', 'core.members', 'core.roadmap'],
        'peer_support' => ['core.manifesto', 'core.members'],
    ];

    public function up(): void
    {
        $withCards = DB::table('loop_cards')->distinct()->pluck('loop_id')->all();

        $loops = DB::table('loops')
            ->select('id', 'organization_id', 'type')
            ->when($withCards !== [], fn ($q) => $q->whereNotIn('id', $withCards))
            ->get();

        $now = now();
        $rows = [];

        foreach ($loops as $loop) {
            // An unknown value resolved to the default, exactly as the registry
            // did, so a stray type does not lose its Loop's cards.
            $cards = self::PRESETS_BEFORE[$loop->type] ?? self::PRESETS_BEFORE['general'];

            foreach ($cards as $key) {
                $rows[] = [
                    'id' => (string) Str::uuid7(),
                    'organization_id' => $loop->organization_id,
                    'loop_id' => $loop->id,
                    'card_key' => $key,
                    'enabled' => true,
                    'added_by_preset' => $loop->type,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('loop_cards')->insert($chunk);
        }
    }

    /**
     * Not reversible, deliberately.
     *
     * Rolling back would mean deleting `loop_cards` rows, and there is no way
     * to tell the ones written here from the ones a human enabled afterwards.
     * Leaving them costs nothing: a Loop keeps the composition it already had.
     */
    public function down(): void {}
};
