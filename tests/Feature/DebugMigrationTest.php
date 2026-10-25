<?php

namespace Tests\Feature;

use App\Models\Theme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DebugMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_etat(): void
    {
        $t = Theme::where('key', 'ofsh')->first();
        fwrite(STDERR, 'ligne ofsh : '.($t ? 'EXISTE' : 'ABSENTE').PHP_EOL);
        if ($t) {
            fwrite(STDERR, sprintf("tokens=%d dark=%d\n", count($t->tokens ?? []), count($t->dark_tokens ?? [])));
        }
        $m = DB::table('migrations')->where('migration', 'like', '%ofsh%')->pluck('migration')->all();
        fwrite(STDERR, "migrations ofsh jouees : ".(count($m) ? implode(', ', $m) : 'AUCUNE').PHP_EOL);
        fwrite(STDERR, 'total migrations : '.DB::table('migrations')->count().PHP_EOL);
        $this->assertTrue(true);
    }
}
