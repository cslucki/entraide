<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TASK-1810 (demande Cyril, 12/11) — le gabarit « Commentaire sur le flux »,
 * Global, FR et EN.
 *
 * Meme forme que `2026_11_07_230000_add_blog_comment_created_email_template` :
 * deux points d'entree pour un gabarit `system_email_templates` — le
 * catalogue du seeder (bases fraiches) ET cette migration (bases DEJA
 * installees, qu'un seeder ne revisite pas). Les deux textes sont
 * IDENTIQUES, et leur egalite est verifiee programmatiquement avant commit —
 * pas a l'oeil.
 *
 * Idempotente : `up()` n'insere que si la ligne manque, et `down()` ne
 * retire que la ligne restee conforme a ce qu'elle a pose.
 *
 * Le SUJET est VOLONTAIREMENT litteral, sans aucune variable — demande
 * explicite de Cyril : « Sujet : Commentaire sur le flux ».
 */
return new class extends Migration
{
    private const SLUG = 'feed_post_comment_created';

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
                    'organization_name', 'feed_context', 'comment_url',
                    'comment_excerpt', 'preferences_url', 'app_name',
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
                'name' => 'Commentaire sur le flux',
                'subject' => 'Commentaire sur le flux',
                'html' => '<p>Bonjour {{ recipient_name }},</p><p><strong>{{ author_first_name }}</strong> vient de commenter le flux à propos d\'un sujet qui vous concerne : <strong>{{ feed_context }}</strong></p><blockquote style="margin:16px 0;padding:12px 16px;border-left:3px solid #ccc;white-space:pre-line">{{ comment_excerpt }}</blockquote><p><a href="{{ comment_url }}">Voir dans le Flux</a></p><p>Rien ne presse : vous n\'avez pas besoin de vous connecter pour en prendre connaissance.</p><p>À bientôt,<br>L’équipe {{ app_name }}</p><hr style="margin:24px 0;border:0;border-top:1px solid #eee"><p style="font-size:12px;color:#777">Pour ne plus recevoir cet email, allez dans <a href="{{ preferences_url }}">Réglages des notifications</a>.</p>',
            ],
            'en' => [
                'name' => 'Comment on the Feed',
                'subject' => 'Comment on the Feed',
                'html' => '<p>Hello {{ recipient_name }},</p><p><strong>{{ author_first_name }}</strong> just commented on the Feed about something that concerns you: <strong>{{ feed_context }}</strong></p><blockquote style="margin:16px 0;padding:12px 16px;border-left:3px solid #ccc;white-space:pre-line">{{ comment_excerpt }}</blockquote><p><a href="{{ comment_url }}">View in the Feed</a></p><p>No rush: you don\'t need to sign in to read it.</p><p>See you soon,<br>The {{ app_name }} team</p><hr style="margin:24px 0;border:0;border-top:1px solid #eee"><p style="font-size:12px;color:#777">To stop receiving this email, go to <a href="{{ preferences_url }}">Notification settings</a>.</p>',
            ],
        ];
    }
};
