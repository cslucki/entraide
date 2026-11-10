<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TASK-1797 — le gabarit « mention collective dans une Boucle » (`@tous`).
 *
 * ## Pourquoi une migration EN PLUS du seeder
 *
 * Un gabarit `system_email_templates` a DEUX points d'entree, et il faut les
 * deux :
 *
 *  - `SystemEmailTemplateSeeder::$templateDefinitions` — le CATALOGUE, qui sert
 *    les bases fraiches et fait autorite ;
 *  - cette migration — qui pose les lignes sur les bases DEJA installees, qu'un
 *    seeder ne revisite pas.
 *
 * ## Le texte est DUPLIQUE, et c'est voulu
 *
 * Une migration est un fait historique : son resultat ne doit pas changer parce
 * qu'on a reecrit le seeder apres coup. Ce fichier a ete GENERE depuis le
 * catalogue — la divergence est impossible a la naissance — et
 * `TASK1797MentionAllTest::test_le_catalogue_du_seeder_et_la_migration_portent_le_meme_texte`
 * le compare programmatiquement, pas a l'oeil.
 *
 * Idempotente : `up()` n'insere que si la ligne manque, et `down()` ne retire
 * que la ligne restee conforme a ce qu'elle a pose — un texte reecrit par le
 * SuperAdmin depuis `/admin/system-email-templates` survit donc au rollback.
 */
return new class extends Migration
{
    private const SLUG = 'loop_mention_all_created';

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
                'variables' => json_encode([
                    'recipient_name', 'recipient_email', 'organization_name',
                    'loop_name', 'author_name', 'message_url', 'message_excerpt',
                    'preferences_url', 'app_name',
                ]),
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
    private function gabarits(): array
    {
        return [
            'fr' => [
                'name' => 'Mention collective dans une Boucle',
                'subject' => '{{ author_name }} a mentionné tous les membres de « {{ loop_name }} »',
                'html' => '<p>Bonjour {{ recipient_name }},</p><p><strong>{{ author_name }}</strong> a mentionné <strong>tous les membres</strong> de la Boucle <strong>{{ loop_name }}</strong>, dont vous.</p><blockquote style="margin:16px 0;padding:12px 16px;border-left:3px solid #ccc;white-space:pre-line">{{ message_excerpt }}</blockquote><p><a href="{{ message_url }}">Lire le message dans la Boucle</a></p><p>Rien ne presse : le message vous attend, vous le lirez quand vous voudrez.</p><p>À bientôt,<br>L’équipe {{ app_name }}</p><hr style="margin:24px 0;border:0;border-top:1px solid #eee"><p style="font-size:12px;color:#777">Pour ne plus recevoir cet email, allez dans <a href="{{ preferences_url }}">Réglages des notifications</a>.</p>',
            ],
            'en' => [
                'name' => 'Collective mention in a Loop',
                'subject' => '{{ author_name }} mentioned all the members of “{{ loop_name }}”',
                'html' => '<p>Hello {{ recipient_name }},</p><p><strong>{{ author_name }}</strong> mentioned <strong>all the members</strong> of the Loop <strong>{{ loop_name }}</strong>, including you.</p><blockquote style="margin:16px 0;padding:12px 16px;border-left:3px solid #ccc;white-space:pre-line">{{ message_excerpt }}</blockquote><p><a href="{{ message_url }}">Read the message in the Loop</a></p><p>No rush: the message will wait for you.</p><p>See you soon,<br>The {{ app_name }} team</p><hr style="margin:24px 0;border:0;border-top:1px solid #eee"><p style="font-size:12px;color:#777">To stop receiving this email, go to <a href="{{ preferences_url }}">Notification settings</a>.</p>',
            ],
        ];
    }
};
