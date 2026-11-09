<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TASK-1786 — le gabarit « on a commente votre annonce d'entraide », Global,
 * FR et EN.
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
 * En T1720, la migration avait ete ecrite SEULE : une base reconstruite de zero
 * n'aurait jamais eu le gabarit, et le test du seeder a rougi les deux gates,
 * son attendu etant dynamique (`count(definitions()) * 2`).
 *
 * ## Le texte est DUPLIQUE, et c'est voulu
 *
 * Une migration est un fait historique : son resultat ne doit pas changer parce
 * qu'on a reecrit le seeder apres coup. Ce fichier a donc ete **GENERE depuis le
 * catalogue**, ce qui rend la divergence impossible a la naissance.
 *
 * Idempotente : `up()` n'insere que si la ligne manque, et `down()` ne retire
 * que la ligne restee conforme a ce qu'elle a pose — un texte reecrit par le
 * SuperAdmin depuis `/admin/system-email-templates` survit donc au rollback.
 */
return new class extends Migration
{
    private const SLUG = 'entraide_comment_created';

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
                    'recipient_name', 'recipient_email', 'author_name',
                    'organization_name', 'listing_type', 'listing_title',
                    'comment_url', 'comment_excerpt', 'preferences_url',
                    'app_name',
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
                'name' => 'Commentaire sur votre annonce d\'entraide',
                'subject' => 'Un membre a commenté votre {{ listing_type }}',
                'html' => '<p>Bonjour {{ recipient_name }},</p><p><strong>{{ author_name }}</strong> a laissé un commentaire sur votre {{ listing_type }} <strong>{{ listing_title }}</strong>.</p><blockquote style="margin:16px 0;padding:12px 16px;border-left:3px solid #ccc;white-space:pre-line">{{ comment_excerpt }}</blockquote><p><a href="{{ comment_url }}">Voir le commentaire</a></p><p>Un commentaire n’engage rien : ni échange, ni acceptation d’aide, ni temps promis. Vous répondrez si vous le souhaitez.</p><p>À bientôt,<br>L’équipe {{ app_name }}</p><hr style="margin:24px 0;border:0;border-top:1px solid #eee"><p style="font-size:12px;color:#777">Pour ne plus recevoir cet email, allez dans <a href="{{ preferences_url }}">Réglages des notifications</a>.</p>',
            ],
            'en' => [
                'name' => 'Comment on your mutual aid listing',
                'subject' => 'A member commented on your {{ listing_type }}',
                'html' => '<p>Hello {{ recipient_name }},</p><p><strong>{{ author_name }}</strong> left a comment on your {{ listing_type }} <strong>{{ listing_title }}</strong>.</p><blockquote style="margin:16px 0;padding:12px 16px;border-left:3px solid #ccc;white-space:pre-line">{{ comment_excerpt }}</blockquote><p><a href="{{ comment_url }}">View the comment</a></p><p>A comment commits to nothing: no exchange, no accepted help, no promised time. You may reply if you wish.</p><p>See you soon,<br>The {{ app_name }} team</p><hr style="margin:24px 0;border:0;border-top:1px solid #eee"><p style="font-size:12px;color:#777">To stop receiving this email, go to <a href="{{ preferences_url }}">Notification settings</a>.</p>',
            ],
        ];
    }
};
