<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Str;

class ReferralCodeGenerator
{
    public function generate(User $user): string
    {
        $base = $this->normalize($user->name);

        for ($i = 0; $i < 10; $i++) {
            $code = $base.Str::lower(Str::random(4));
            if (! User::where('referral_code', $code)->exists()) {
                return $code;
            }
        }

        return Str::lower(Str::random(12));
    }

    public function normalize(string $name): string
    {
        $clean = preg_replace('/[^a-zA-Z0-9]/', '', Str::slug($name));
        $clean = Str::substr($clean, 0, 8);

        if (strlen($clean) < 3) {
            $clean = 'usr';
        }

        return $clean;
    }
}
