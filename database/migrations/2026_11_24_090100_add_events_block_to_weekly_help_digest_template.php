<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TASK-1891 (demande Cyril, 23/11) — « mettre ceci en premier dans la
 * newsletter » : le bloc `{evenements;3}` entre dans les gabarits
 * `weekly_help_digest` existants (global ET surcharges d'organization).
 *
 * Le texte a pu etre modifie depuis « Templates systeme » : on ne le
 * reecrit pas, on INSERE le bloc avant la premiere section (`<h2`), a defaut
 * avant le premier bloc connu, a defaut apres le premier paragraphe. Un
 * gabarit qui porte deja `{evenements;` n'est pas touche (idempotent).
 */
return new class extends Migration
{
    private const SLUG = 'weekly_help_digest';

    private const BLOC = '{evenements;3}';

    public function up(): void
    {
        DB::table('system_email_templates')->where('slug', self::SLUG)->get(['id', 'content_html'])
            ->each(function ($ligne) {
                $html = (string) $ligne->content_html;
                if (str_contains($html, '{evenements;')) {
                    return;
                }

                DB::table('system_email_templates')->where('id', $ligne->id)
                    ->update(['content_html' => $this->inserer($html), 'updated_at' => now()]);
            });
    }

    public function down(): void
    {
        DB::table('system_email_templates')->where('slug', self::SLUG)->get(['id', 'content_html'])
            ->each(function ($ligne) {
                if (str_contains((string) $ligne->content_html, self::BLOC)) {
                    DB::table('system_email_templates')->where('id', $ligne->id)
                        ->update(['content_html' => str_replace(self::BLOC, '', (string) $ligne->content_html)]);
                }
            });
    }

    private function inserer(string $html): string
    {
        foreach (['<h2', '{demande_aide;', '{proposition_aide;', '{billets_blog;'] as $repere) {
            $position = strpos($html, $repere);
            if ($position !== false) {
                return substr_replace($html, self::BLOC, $position, 0);
            }
        }

        $finParagraphe = strpos($html, '</p>');

        return $finParagraphe !== false
            ? substr_replace($html, self::BLOC, $finParagraphe + 4, 0)
            : self::BLOC.$html;
    }
};
