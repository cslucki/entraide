<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TASK-1884 #1 (demande Cyril, 22/11) — le gabarit « Nomination comme co-auteur
 * d'un article », Global (sans Organization), FR et EN.
 *
 * Meme forme que `2026_11_22_200000_add_feed_post_mention_created_email_template` :
 * la migration sert les bases DEJA installees, le catalogue du seeder les bases
 * fraiches ; les deux textes sont IDENTIQUES. Idempotente.
 */
return new class extends Migration
{
    private const SLUG = 'blog_co_author_added';

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
                    'recipient_name', 'recipient_email', 'author_first_name',
                    'organization_name', 'post_title', 'post_url',
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
                'name' => 'Nomination comme co-auteur d’un article',
                'subject' => '{{ author_first_name }} vous a nommé co-auteur d’un article',
                'html' => '<p>Bonjour {{ recipient_name }},</p><p><strong>{{ author_first_name }}</strong> vous a nommé co-auteur d’un article : <strong>{{ post_title }}</strong>.</p><p>Vous pouvez désormais le modifier vous aussi. Voici le lien de cet article :</p><p><a href="{{ post_url }}">Ouvrir l’article</a></p><p>À bientôt,<br>L’équipe {{ organization_name }}</p><hr style="margin:24px 0;border:0;border-top:1px solid #eee"><p style="font-size:12px;color:#777">Pour ne plus recevoir cet email, allez dans <a href="{{ preferences_url }}">Réglages des notifications</a>.</p>',
            ],
            'en' => [
                'name' => 'Named co-author of an article',
                'subject' => '{{ author_first_name }} made you co-author of an article',
                'html' => '<p>Hello {{ recipient_name }},</p><p><strong>{{ author_first_name }}</strong> made you co-author of an article: <strong>{{ post_title }}</strong>.</p><p>You can now edit it together. Here is the link to this article:</p><p><a href="{{ post_url }}">Open the article</a></p><p>See you soon,<br>The {{ organization_name }} team</p><hr style="margin:24px 0;border:0;border-top:1px solid #eee"><p style="font-size:12px;color:#777">To stop receiving this email, go to <a href="{{ preferences_url }}">Notification settings</a>.</p>',
            ],
        ];
    }
};
