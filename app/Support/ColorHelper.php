<?php

namespace App\Support;

class ColorHelper
{
    public static function darken(string $hex, int $percent = 25): string
    {
        $hex = ltrim($hex, '#');
        [$r, $g, $b] = array_map('hexdec', str_split($hex, 2));

        [$h, $s, $l] = self::rgbToHsl($r, $g, $b);

        $l = max(0, $l - ($percent / 100));

        [$r, $g, $b] = self::hslToRgb($h, $s, $l);

        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }

    public static function rgbToHsl(int $r, int $g, int $b): array
    {
        $r /= 255;
        $g /= 255;
        $b /= 255;
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;

        if ($max === $min) {
            return [0, 0, $l];
        }

        $d = $max - $min;
        $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);

        switch ($max) {
            case $r: $h = ($g - $b) / $d + ($g < $b ? 6 : 0);
                break;
            case $g: $h = ($b - $r) / $d + 2;
                break;
            case $b: $h = ($r - $g) / $d + 4;
                break;
        }
        $h /= 6;

        return [$h, $s, $l];
    }

    public static function hslToRgb(float $h, float $s, float $l): array
    {
        if ($s === 0) {
            $v = (int) round($l * 255);

            return [$v, $v, $v];
        }

        $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
        $p = 2 * $l - $q;

        $r = self::hueToRgb($p, $q, $h + 1 / 3);
        $g = self::hueToRgb($p, $q, $h);
        $b = self::hueToRgb($p, $q, $h - 1 / 3);

        return [(int) round($r * 255), (int) round($g * 255), (int) round($b * 255)];
    }

    private static function hueToRgb(float $p, float $q, float $t): float
    {
        if ($t < 0) {
            $t += 1;
        }
        if ($t > 1) {
            $t -= 1;
        }
        if ($t < 1 / 6) {
            return $p + ($q - $p) * 6 * $t;
        }
        if ($t < 1 / 2) {
            return $q;
        }
        if ($t < 2 / 3) {
            return $p + ($q - $p) * (2 / 3 - $t) * 6;
        }

        return $p;
    }
}
