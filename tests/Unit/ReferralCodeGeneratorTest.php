<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\ReferralCodeGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferralCodeGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_generates_code_from_user_name(): void
    {
        $user = User::factory()->create(['name' => 'Cyril']);
        $generator = new ReferralCodeGenerator;

        $code = $generator->generate($user);

        $this->assertStringStartsWith('cyril', $code);
        $this->assertLessThanOrEqual(50, strlen($code));
    }

    public function test_generates_lowercase_code(): void
    {
        $user = User::factory()->create(['name' => 'Jean-Pierre']);
        $generator = new ReferralCodeGenerator;

        $code = $generator->generate($user);

        $this->assertEquals(strtolower($code), $code);
    }

    public function test_normalize_removes_special_characters(): void
    {
        $generator = new ReferralCodeGenerator;

        $result = $generator->normalize('héllo wörld!');

        $this->assertEquals('hellowor', $result);
    }

    public function test_normalize_truncates_to_eight_characters(): void
    {
        $generator = new ReferralCodeGenerator;

        $result = $generator->normalize('abcdefghijklmnop');

        $this->assertEquals('abcdefgh', $result);
        $this->assertLessThanOrEqual(8, strlen($result));
    }

    public function test_normalize_short_name_falls_back_to_usr(): void
    {
        $generator = new ReferralCodeGenerator;

        $result = $generator->normalize('ä');

        $this->assertEquals('usr', $result);
    }

    public function test_generated_code_is_unique_in_database(): void
    {
        User::factory()->create(['referral_code' => 'cyrilabcd']);
        $user = User::factory()->create(['name' => 'Cyril']);
        $generator = new ReferralCodeGenerator;

        $code = $generator->generate($user);

        $this->assertNotEquals('cyrilabcd', $code);
        $this->assertStringStartsWith('cyril', $code);
    }
}
