<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TASK-1891 (demande Cyril, 23/11) — objet par defaut de la newsletter :
 * « [{{ organization_name }}] - notre newsletter du [date du jour] ».
 * `{{ newsletter_date }}` est calcule a l'envoi (BulkEmailSendService).
 *
 * Seul un objet reste IDENTIQUE a celui d'origine (T1883) est remplace : un
 * objet modifie depuis « Templates systeme » est respecte. La variable entre
 * dans la liste documentee de chaque gabarit `weekly_help_digest`.
 */
return new class extends Migration
{
    private const SLUG = 'weekly_help_digest';

    private const ANCIENS = [
        'fr' => 'Les dernières demandes et propositions d’aide — {{ organization_name }}',
        'en' => 'Latest help requests and offers — {{ organization_name }}',
    ];

    private const NOUVEAUX = [
        'fr' => '[{{ organization_name }}] - notre newsletter du {{ newsletter_date }}',
        'en' => '[{{ organization_name }}] - our newsletter of {{ newsletter_date }}',
    ];

    public function up(): void
    {
        foreach (self::ANCIENS as $locale => $ancien) {
            DB::table('system_email_templates')->where('slug', self::SLUG)->where('locale', $locale)
                ->where('subject', $ancien)
                ->update(['subject' => self::NOUVEAUX[$locale], 'updated_at' => now()]);
        }

        DB::table('system_email_templates')->where('slug', self::SLUG)->get(['id', 'variables'])
            ->each(function ($ligne) {
                $variables = json_decode((string) $ligne->variables, true) ?: [];
                if (! in_array('newsletter_date', $variables, true)) {
                    $variables[] = 'newsletter_date';
                    DB::table('system_email_templates')->where('id', $ligne->id)->update(['variables' => json_encode($variables)]);
                }
            });
    }

    public function down(): void
    {
        foreach (self::NOUVEAUX as $locale => $nouveau) {
            DB::table('system_email_templates')->where('slug', self::SLUG)->where('locale', $locale)
                ->where('subject', $nouveau)
                ->update(['subject' => self::ANCIENS[$locale]]);
        }
    }
};
