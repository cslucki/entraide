<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TASK-1880 (demande Cyril, 22/11) — le gabarit « Mention dans une annonce du
 * Flux », Global (sans Organization), FR et EN.
 *
 * Meme forme que `2026_11_12_170000_add_feed_post_comment_created_email_template` :
 * la migration sert les bases DEJA installees, le catalogue du seeder les bases
 * fraiches ; les deux textes sont IDENTIQUES. Idempotente.
 *
 * Sujet demande par Cyril : « Prenom vous a identifie sur le Flux ». Le corps
 * porte la TOTALITE du message de l'annonce (`post_message`).
 */
return new class extends Migration
{
    private const SLUG = 'feed_post_mention_created';

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
                    'organization_name', 'post_message', 'post_url',
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
                'name' => 'Mention dans une annonce du Flux',
                'subject' => '{{ author_first_name }} vous a identifié sur le Flux',
                'html' => '<p>Bonjour {{ recipient_name }},</p><p><strong>{{ author_first_name }}</strong> vous a identifié dans une annonce du Flux :</p><blockquote style="margin:16px 0;padding:12px 16px;border-left:3px solid #ccc;white-space:pre-line">{{ post_message }}</blockquote><p><a href="{{ post_url }}">Voir l\'annonce dans le Flux</a></p><p>À bientôt,<br>L’équipe {{ app_name }}</p><hr style="margin:24px 0;border:0;border-top:1px solid #eee"><p style="font-size:12px;color:#777">Pour ne plus recevoir cet email, allez dans <a href="{{ preferences_url }}">Réglages des notifications</a>.</p>',
            ],
            'en' => [
                'name' => 'Mention in a Feed announcement',
                'subject' => '{{ author_first_name }} tagged you on the Feed',
                'html' => '<p>Hello {{ recipient_name }},</p><p><strong>{{ author_first_name }}</strong> tagged you in a Feed announcement:</p><blockquote style="margin:16px 0;padding:12px 16px;border-left:3px solid #ccc;white-space:pre-line">{{ post_message }}</blockquote><p><a href="{{ post_url }}">View the announcement in the Feed</a></p><p>See you soon,<br>The {{ app_name }} team</p><hr style="margin:24px 0;border:0;border-top:1px solid #eee"><p style="font-size:12px;color:#777">To stop receiving this email, go to <a href="{{ preferences_url }}">Notification settings</a>.</p>',
            ],
        ];
    }
};
