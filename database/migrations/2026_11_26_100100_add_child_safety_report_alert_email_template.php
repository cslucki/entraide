<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TASK-1905 (decision MASTER D2) — gabarit GLOBAL FR/EN de l'alerte envoyee au
 * contact designe a chaque nouveau signalement « Securite des enfants ».
 * Patron TASK-1809 : idempotent, `down` cible.
 *
 * Decision MASTER D6 : le gabarit ne porte QUE le nom de l'organisation, la
 * date et le lien vers le back-office — jamais la description, le signaleur
 * ni l'URL du contenu. Ces donnees ne lui sont meme pas fournies.
 * Jumeau : `SystemEmailTemplateSeeder`.
 */
return new class extends Migration
{
    private const SLUG = 'child_safety_report_alert';

    public function up(): void
    {
        foreach ($this->gabarits() as $locale => $g) {
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
                'name' => $g['name'],
                'subject' => $g['subject'],
                'content_html' => $g['html'],
                'variables' => json_encode(['organization_name', 'report_date', 'admin_url', 'app_name']),
                'enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        foreach ($this->gabarits() as $locale => $g) {
            DB::table('system_email_templates')
                ->where('slug', self::SLUG)
                ->where('locale', $locale)
                ->whereNull('organization_id')
                ->where('subject', $g['subject'])
                ->where('content_html', $g['html'])
                ->delete();
        }
    }

    /** @return array<string, array{name: string, subject: string, html: string}> */
    public static function gabarits(): array
    {
        return [
            'fr' => [
                'name' => 'Alerte : signalement « Sécurité des enfants »',
                'subject' => '[{{ app_name }}] Signalement prioritaire à examiner',
                'html' => '<p>Bonjour,</p><p>Un nouveau signalement de catégorie « Sécurité des enfants » a été enregistré le {{ report_date }} dans l’organisation <strong>{{ organization_name }}</strong>.</p><p>Il doit être examiné en priorité dans le back-office. Pour des raisons de confidentialité, ce message ne contient ni le contenu du signalement, ni l’identité de son auteur.</p><p><a href="{{ admin_url }}">Ouvrir les signalements en attente</a></p><p>La décision de modération reste humaine.</p>',
            ],
            'en' => [
                'name' => 'Alert: "Child safety" report',
                'subject' => '[{{ app_name }}] Priority report to review',
                'html' => '<p>Hello,</p><p>A new "Child safety" report was recorded on {{ report_date }} in the organization <strong>{{ organization_name }}</strong>.</p><p>It must be reviewed first in the back-office. For confidentiality reasons, this message contains neither the content of the report nor the identity of its author.</p><p><a href="{{ admin_url }}">Open pending reports</a></p><p>The moderation decision remains human.</p>',
            ],
        ];
    }
};
