<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TASK-1802 (demande Cyril) — le gabarit « un nouvel évènement est proposé
 * dans votre Boucle », Global, FR et EN.
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
 * Ce fichier a ete GENERE depuis le catalogue, ce qui rend la divergence
 * impossible a la naissance (meme discipline que
 * `2026_11_08_031000_add_entraide_comment_created_email_template`).
 *
 * Idempotente : `up()` n'insere que si la ligne manque, et `down()` ne retire
 * que la ligne restee conforme a ce qu'elle a pose.
 */
return new class extends Migration
{
    private const SLUG = 'loop_event_created';

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
                    'organization_name', 'loop_name', 'event_title', 'event_date',
                    'event_description', 'event_practical_info', 'event_url',
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
                'name' => 'Nouvel évènement proposé dans une Boucle',
                'subject' => '{{ event_title }} — {{ event_date }}',
                'html' => '<p>Bonjour {{ recipient_name }},</p><p><strong>{{ author_name }}</strong> a proposé un nouvel évènement dans la Boucle <strong>{{ loop_name }}</strong> : <strong>{{ event_title }}</strong>, le {{ event_date }}.</p><p>{{ event_practical_info }}</p><p>{{ event_description }}</p><p><a href="{{ event_url }}">Ouvrir la Boucle</a></p><p>Rien ne presse : l’évènement vous attend, vous y répondrez quand vous voudrez.</p><p>À bientôt,<br>L’équipe {{ app_name }}</p><hr style="margin:24px 0;border:0;border-top:1px solid #eee"><p style="font-size:12px;color:#777">Pour ne plus recevoir cet email, allez dans <a href="{{ preferences_url }}">Réglages des notifications</a>.</p>',
            ],
            'en' => [
                'name' => 'New event proposed in a Loop',
                'subject' => '{{ event_title }} — {{ event_date }}',
                'html' => '<p>Hello {{ recipient_name }},</p><p><strong>{{ author_name }}</strong> proposed a new event in the Loop <strong>{{ loop_name }}</strong>: <strong>{{ event_title }}</strong>, on {{ event_date }}.</p><p>{{ event_practical_info }}</p><p>{{ event_description }}</p><p><a href="{{ event_url }}">Open the Loop</a></p><p>No rush: the event will wait for you.</p><p>See you soon,<br>The {{ app_name }} team</p><hr style="margin:24px 0;border:0;border-top:1px solid #eee"><p style="font-size:12px;color:#777">To stop receiving this email, go to <a href="{{ preferences_url }}">Notification settings</a>.</p>',
            ],
        ];
    }
};
