<?php

namespace Database\Seeders;

use App\Models\Badge;
use Illuminate\Database\Seeder;

class BadgeSeeder extends Seeder
{
    public function run(): void
    {
        $badges = [
            [
                'key' => 'first_exchange',
                'name' => 'Premier échange',
                'description' => 'A complété son premier échange de service.',
                'icon' => '🤝',
                'color' => '#10b981',
            ],
            [
                'key' => 'five_exchanges',
                'name' => 'Habitué',
                'description' => 'A complété 5 échanges de service.',
                'icon' => '⭐',
                'color' => '#f59e0b',
            ],
            [
                'key' => 'ten_exchanges',
                'name' => 'Expert',
                'description' => 'A complété 10 échanges de service.',
                'icon' => '🏆',
                'color' => '#6366f1',
            ],
            [
                'key' => 'first_service',
                'name' => 'Prestataire',
                'description' => 'A publié son premier service.',
                'icon' => '📋',
                'color' => '#3b82f6',
            ],
            [
                'key' => 'five_services',
                'name' => 'Multi-prestataire',
                'description' => 'A publié 5 services ou plus.',
                'icon' => '🎯',
                'color' => '#8b5cf6',
            ],
            [
                'key' => 'top_rated',
                'name' => 'Très bien noté',
                'description' => 'A une note moyenne de 4.5 ou plus (minimum 3 avis).',
                'icon' => '💎',
                'color' => '#ec4899',
            ],
            [
                'key' => 'generous',
                'name' => 'Évaluateur',
                'description' => 'A donné 3 avis positifs (4 étoiles ou plus).',
                'icon' => '💬',
                'color' => '#14b8a6',
            ],
        ];

        foreach ($badges as $badge) {
            Badge::firstOrCreate(['key' => $badge['key']], $badge);
        }
    }
}
