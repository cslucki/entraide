<?php

namespace App\Support\Entraide;

/**
 * TASK-1757 (§9.7 du CDC entraide) — le catalogue de badges, en UN endroit.
 *
 * ## Le trou que cette classe ferme
 *
 * `BadgeService::maybeAward()` faisait `Badge::where('key', $key)->first()` et,
 * si la ligne manquait, ne faisait **rien en silence**. Les badges n'existent
 * que parce que `BadgeSeeder` les a semes — aucune migration ne les pose. Un
 * environnement deja deploye (la PROD) n'aurait donc JAMAIS attribue les trois
 * nouveaux badges, sans qu'aucun test ni aucun journal ne le signale : la
 * condition serait remplie, la ligne absente, et la methode rendrait la main.
 *
 * Le catalogue vit donc ici, le seeder le lit, et l'attribution cree la ligne
 * si elle manque. Aucun environnement ne peut rater un badge, et il n'y a plus
 * deux listes a tenir d'accord.
 *
 * ## Les libelles d'ici sont des REPLIS, pas l'affichage
 *
 * Le rendu passe par `lang/{fr,en}/badges.php` (voir `Badge::libelle()`).
 * `name` et `description` sont NOT NULL en base : il faut bien ecrire quelque
 * chose, et c'est ce quelque chose qui s'affichera le jour ou une cle de langue
 * manquera. Meme doctrine que `MessageSysteme::rendre()` en T1754.
 */
final class CatalogueDeBadges
{
    /** TASK-1757 §9.7 — les trois badges d'entraide. */
    public const PREMIER_COUP_DE_MAIN = 'first_help';

    public const CINQ_PERSONNES_AIDEES = 'five_people_helped';

    public const AIDE_UN_NOUVEAU = 'helped_newcomer';

    /**
     * @return array<string, array{name: string, description: string, icon: string, color: string}>
     */
    public static function tous(): array
    {
        return [
            'first_exchange' => [
                'name' => 'Première entraide',
                'description' => 'A participé à sa première entraide.',
                'icon' => '🤝',
                'color' => '#10b981',
            ],
            'five_exchanges' => [
                'name' => 'Habitué de l’entraide',
                'description' => 'A participé à 5 entraides.',
                'icon' => '⭐',
                'color' => '#f59e0b',
            ],
            'ten_exchanges' => [
                'name' => 'Pilier de la communauté',
                'description' => 'A participé à 10 entraides.',
                'icon' => '🏆',
                'color' => '#6366f1',
            ],
            'first_service' => [
                'name' => 'Première annonce',
                'description' => 'A publié sa première annonce.',
                'icon' => '📋',
                'color' => '#3b82f6',
            ],
            'five_services' => [
                'name' => 'Cinq annonces publiées',
                'description' => 'A publié 5 annonces ou plus.',
                'icon' => '🎯',
                'color' => '#8b5cf6',
            ],
            'top_rated' => [
                'name' => 'Très apprécié',
                'description' => 'A une note moyenne de 4,5 ou plus (minimum 3 avis).',
                'icon' => '💎',
                'color' => '#ec4899',
            ],
            'generous' => [
                'name' => 'Dit merci',
                'description' => 'A remercié 3 personnes qui l’avaient aidé.',
                'icon' => '💬',
                'color' => '#14b8a6',
            ],

            self::PREMIER_COUP_DE_MAIN => [
                'name' => 'Premier coup de main',
                'description' => 'A donné son premier coup de main.',
                'icon' => '🥇',
                'color' => '#eab308',
            ],
            self::CINQ_PERSONNES_AIDEES => [
                'name' => '5 personnes aidées',
                'description' => 'A aidé 5 personnes différentes.',
                'icon' => '🤝',
                'color' => '#0ea5e9',
            ],
            self::AIDE_UN_NOUVEAU => [
                'name' => 'A aidé un nouveau membre',
                'description' => 'A aidé quelqu’un pour sa toute première entraide.',
                'icon' => '🌱',
                'color' => '#22c55e',
            ],
        ];
    }

    /** @return array{name: string, description: string, icon: string, color: string}|null */
    public static function definition(string $cle): ?array
    {
        return self::tous()[$cle] ?? null;
    }

    /** @return list<string> */
    public static function clefs(): array
    {
        return array_keys(self::tous());
    }
}
