<?php

use App\Models\SystemEmailTemplate;
use Database\Seeders\SystemEmailTemplateSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * TASK-1734 — les deux gabarits de « Mes organisations » entrent en base.
 *
 * ## Migration ET catalogue, les deux — regle maison
 *
 * Le seeder ne rejoue pas en production : une base EXISTANTE recoit ses
 * lignes par cette migration. Une base NEUVE les recoit par le catalogue du
 * seeder, ou les memes definitions sont inscrites. Un gabarit pose par un
 * seul des deux canaux manque toujours quelque part (lecon T1694 bug N).
 *
 * `rowFor()` est la SEULE traduction definitions -> colonnes : cette
 * migration ne reecrit aucun texte, elle ne peut donc pas diverger du
 * catalogue. Meme patron que `2026_10_24_140000_normalize...`.
 *
 * `firstOrCreate` sur la cle (global, locale, slug) : rejouable, et ne
 * touche jamais une ligne qu'un humain aurait deja modifiee.
 */
return new class extends Migration
{
    private const SLUGS = [
        'liaison-comptes-confirmation',
        'liaison-comptes-creee',
    ];

    public function up(): void
    {
        foreach (self::SLUGS as $slug) {
            foreach (['fr', 'en'] as $locale) {
                SystemEmailTemplate::firstOrCreate(
                    [
                        'organization_id' => null,
                        'locale' => $locale,
                        'slug' => $slug,
                    ],
                    SystemEmailTemplateSeeder::rowFor($slug, $locale) + ['enabled' => true],
                );
            }
        }
    }

    public function down(): void
    {
        // On ne retire que les lignes GLOBALES de ces slugs : une surcharge
        // par Organization, si quelqu'un en creait une, est un travail humain
        // et ne se detruit pas au rollback.
        SystemEmailTemplate::query()
            ->whereNull('organization_id')
            ->whereIn('slug', self::SLUGS)
            ->delete();
    }
};
