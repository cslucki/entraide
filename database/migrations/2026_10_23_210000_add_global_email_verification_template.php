<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TASK-1694 bug D — le gabarit de l'email de confirmation, GLOBAL et bilingue.
 *
 * ## Pourquoi une migration et pas le seeder
 *
 * `SystemEmailTemplateSeeder` cree des lignes PAR ORGANIZATION. Ce gabarit-ci
 * est global (arbitrage de Cyril : « on ne va pas creer un email pour chaque
 * organisation »), et il doit exister sur les bases DEJA installees — un
 * seeder ne repasse pas sur une base en service.
 *
 * ## Idempotente, et elle n'ecrase rien
 *
 * `insert` seulement si la ligne manque. Rejouee, elle ne touche pas un texte
 * que quelqu'un aurait modifie depuis `/admin/system-email-templates` : une
 * migration qui ecraserait une edition humaine detruirait du travail sans le
 * dire.
 *
 * ## Les variables
 *
 * `name` et `url` — les memes que le gabarit « Bienvenue ». `url` est l'adresse
 * SIGNEE, construite par la notification ; elle n'est pas stockee ici.
 */
return new class extends Migration
{
    // TASK-1694 bug F — ce slug est renomme par une migration POSTERIEURE
    // (2026_10_24_090000). La valeur reste celle d'ORIGINE ici : une migration
    // raconte ce qui s'est passe a sa date, elle ne se reecrit pas apres coup.
    // Sur une base neuve, la ligne nait avec l'ancien slug puis est renommee —
    // le resultat final est le meme, et l'histoire reste lisible.
    private const SLUG = 'email_verification';

    private const LIGNES = [
        'fr' => [
            'name' => 'Confirmation d\'adresse email',
            'subject' => 'Confirmez votre adresse email',
            'content_html' => '<h1>Bonjour {{ name }},</h1>
<p>Il ne reste qu\'une etape : confirmer votre adresse email.</p>
<p><a href="{{ url }}">Confirmer mon adresse</a></p>
<p>Ce lien est valable <strong>24 heures</strong>. Passe ce delai, vous pourrez en demander un nouveau depuis la page d\'attente.</p>
<p>Si vous n\'etes pas a l\'origine de cette inscription, vous pouvez ignorer ce message.</p>',
        ],
        'en' => [
            'name' => 'Email address confirmation',
            'subject' => 'Confirm your email address',
            'content_html' => '<h1>Hello {{ name }},</h1>
<p>One last step: confirm your email address.</p>
<p><a href="{{ url }}">Confirm my address</a></p>
<p>This link is valid for <strong>24 hours</strong>. After that, you can request a new one from the waiting page.</p>
<p>If you did not create this account, you can ignore this message.</p>',
        ],
    ];

    public function up(): void
    {
        foreach (self::LIGNES as $locale => $ligne) {
            $existe = DB::table('system_email_templates')
                ->where('slug', self::SLUG)
                ->where('locale', $locale)
                ->whereNull('organization_id')
                ->exists();

            if ($existe) {
                continue;
            }

            DB::table('system_email_templates')->insert([
                'slug' => self::SLUG,
                'locale' => $locale,
                'organization_id' => null,
                'name' => $ligne['name'],
                'subject' => $ligne['subject'],
                'content_html' => $ligne['content_html'],
                'variables' => json_encode(['name', 'url']),
                'enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('system_email_templates')
            ->where('slug', self::SLUG)
            ->whereNull('organization_id')
            ->delete();
    }
};
