<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TASK-1809 §4 — le gabarit « vous avez recu une evaluation », Global, FR et
 * EN.
 *
 * Meme forme que `2026_11_12_110000_add_entraide_evaluation_invite_email_template`
 * (T1808 §6) : un gabarit `system_email_templates` a DEUX points d'entree —
 * le catalogue du seeder (bases fraiches) et cette migration (bases deja
 * installees, qu'un seeder ne revisite pas). GENEREE depuis le catalogue.
 *
 * Idempotente, reversible : `up()` n'insere que si la ligne manque ; `down()`
 * ne retire que la ligne restee conforme a ce qu'elle a pose — un texte
 * reecrit par le SuperAdmin depuis `/admin/system-email-templates` survit au
 * rollback.
 *
 * Destinataire UNIQUE : l'aidant (D2, `reviewed_id`). Declenche par
 * `EvaluationEntraide::enregistrer()` uniquement — jamais par une action de
 * moderation SuperAdmin (`ReviewModeration`).
 */
return new class extends Migration
{
    private const SLUG = 'entraide_review_received';

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
                    'recipient_name', 'recipient_email', 'aide_name', 'aide_first_name',
                    'rating_stars', 'rating_fraction', 'comment_text', 'conversation_url',
                    'organization_name', 'app_name',
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
                'name' => 'Vous avez reçu une évaluation',
                'subject' => '{{ aide_first_name }} vous a laissé une évaluation !',
                'html' => '<p>Bonjour {{ recipient_name }},</p><p>{{ aide_first_name }} vous a laissé une évaluation pour votre entraide.</p><p style="font-size:20px;letter-spacing:2px">{{ rating_stars }} <span style="font-size:14px;color:#777">({{ rating_fraction }})</span></p><p>{{ comment_text }}</p><p><a href="{{ conversation_url }}">Voir la conversation</a></p><p>Merci de faire vivre l’entraide sur {{ app_name }}.</p>',
            ],
            'en' => [
                'name' => 'You received a review',
                'subject' => '{{ aide_first_name }} left you a review!',
                'html' => '<p>Hello {{ recipient_name }},</p><p>{{ aide_first_name }} left you a review for your mutual aid.</p><p style="font-size:20px;letter-spacing:2px">{{ rating_stars }} <span style="font-size:14px;color:#777">({{ rating_fraction }})</span></p><p>{{ comment_text }}</p><p><a href="{{ conversation_url }}">View the conversation</a></p><p>Thank you for keeping mutual aid alive on {{ app_name }}.</p>',
            ],
        ];
    }
};
