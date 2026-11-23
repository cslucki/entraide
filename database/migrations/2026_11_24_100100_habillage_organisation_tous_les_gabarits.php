<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1894 correctif 3 — TOUS les gabarits globaux suivent l'habillage au nom
 * de l'organisation (validation Cyril des 3 exemples, 23/11 : « continuons
 * dans ce sens ») :
 *
 * 1. leur signature (« À bientôt, L'équipe {{ app_name }} », « — {{ app_name }} »…)
 *    disparait : le cadre commun signe « Prénom pour Organisation » ;
 * 2. leur mention « Pour ne plus recevoir… » disparait : le cadre la pose,
 *    seulement sur un email qu'on peut couper ;
 * 3. « sur {{ app_name }} » / « on {{ app_name }} » disparait du texte : aucun
 *    email ne nomme plus la plateforme ;
 * 4. le lien principal (seul dans son paragraphe) devient un BOUTON, au style
 *    unique de `organization_invitation` ; un bouton deja style est aligne.
 *
 * Transformations EXACTES et idempotentes : un texte reecrit par un
 * administrateur qui ne contient plus ces formes n'est pas touche. Le texte
 * d'avant est GARDE (table `system_email_templates_avant_1894`) : `down()` le
 * remet a l'identique.
 */
return new class extends Migration
{
    private const SAUVEGARDE = 'system_email_templates_avant_1894';

    public const BOUTON = 'display: inline-block; padding: 12px 20px; border-radius: 10px; background: #4f46e5; color: #ffffff; text-decoration: none; font-weight: 600;';

    public static function transformer(string $html): string
    {
        // 1. Signatures portees desormais par le cadre.
        $html = preg_replace('#<p>(?:À bientôt|See you soon),<br>(?:L’équipe |The )?\{\{ (?:app_name|organization_name) \}\}(?: team)?</p>#u', '', $html);
        $html = preg_replace('#<p(?: style="[^"]*")?>— \{\{ app_name \}\}</p>#u', '', $html);

        // 2. Mention de reglages portee desormais par le cadre.
        $html = preg_replace('#<hr style="[^"]*"><p style="[^"]*">(?:Pour ne plus recevoir|To stop receiving)[^<]*<a href="\{\{ preferences_url \}\}">[^<]*</a>\.</p>#u', '', $html);

        // 3. Plus aucun nom de plateforme dans le texte.
        $html = str_replace([' sur {{ app_name }}', ' on {{ app_name }}'], '', $html);

        // 4a. Un bouton deja style (meme violet) prend le style unique.
        $html = preg_replace_callback('#<a href="([^"]+)" style="[^"]*background: ?\#4f46e5[^"]*">#u',
            fn ($m) => '<a href="'.$m[1].'" style="'.self::BOUTON.'">', $html);

        // 4b. Le lien principal, SEUL dans son paragraphe, devient un bouton
        //     (jamais le lien de reglages ni celui de desinscription).
        $html = preg_replace_callback('#<p><a href="(\{\{ (?!preferences_url|unsubscribe_url)[a-z_]+ \}\})">([^<]+)</a></p>#u',
            fn ($m) => '<p><a href="'.$m[1].'" style="'.self::BOUTON.'">'.$m[2].'</a></p>', $html);

        return $html;
    }

    public function up(): void
    {
        if (! Schema::hasTable(self::SAUVEGARDE)) {
            Schema::create(self::SAUVEGARDE, function (Blueprint $table) {
                $table->string('template_id')->primary();
                $table->longText('content_html');
                $table->timestamp('saved_at');
            });
        }

        $lignes = DB::table('system_email_templates')->whereNull('organization_id')->get(['id', 'content_html']);

        foreach ($lignes as $ligne) {
            $avant = (string) $ligne->content_html;
            $apres = self::transformer($avant);

            if ($apres === $avant) {
                continue;
            }

            // Le PREMIER texte d'avant est garde : rejouer ne l'ecrase pas.
            DB::table(self::SAUVEGARDE)->insertOrIgnore([
                'template_id' => (string) $ligne->id, 'content_html' => $avant, 'saved_at' => now(),
            ]);
            DB::table('system_email_templates')->where('id', $ligne->id)->update(['content_html' => $apres, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::SAUVEGARDE)) {
            return;
        }

        foreach (DB::table(self::SAUVEGARDE)->get() as $sauvegarde) {
            DB::table('system_email_templates')->where('id', $sauvegarde->template_id)
                ->update(['content_html' => $sauvegarde->content_html, 'updated_at' => now()]);
        }

        Schema::drop(self::SAUVEGARDE);
    }
};
