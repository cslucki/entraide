<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TASK-1808 §6 — le gabarit « invitation a evaluer l'aidant », Global, FR et
 * EN.
 *
 * Meme forme que la migration jumelle
 * `2026_11_08_031000_add_entraide_comment_created_email_template.php` (T1786),
 * et pour la meme raison : un gabarit `system_email_templates` a DEUX points
 * d'entree — le catalogue du seeder (bases fraiches) et cette migration
 * (bases deja installees, qu'un seeder ne revisite pas). GENEREE depuis le
 * catalogue : la divergence est donc impossible a la naissance.
 *
 * Idempotente, reversible : `up()` n'insere que si la ligne manque ; `down()`
 * ne retire que la ligne restee conforme a ce qu'elle a pose — un texte
 * reecrit par le SuperAdmin depuis `/admin/system-email-templates` survit au
 * rollback.
 */
return new class extends Migration
{
    private const SLUG = 'entraide_evaluation_invite';

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
                    'recipient_name', 'recipient_email', 'aidant_name',
                    'organization_name', 'evaluation_url', 'app_name',
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
                'name' => 'Invitation à évaluer l’aidant',
                'subject' => 'Un petit retour sur l’aide de {{ aidant_name }} ?',
                'html' => '<p>Bonjour {{ recipient_name }},</p><p>Votre entraide avec <strong>{{ aidant_name }}</strong> est terminée.</p><p>Vous pouvez lui laisser une évaluation pour reconnaître sa contribution et aider les autres membres de {{ organization_name }}.</p><p>Quelques étoiles suffisent. Un commentaire est facultatif.</p><p><a href="{{ evaluation_url }}">Laisser une évaluation</a></p><p>Si le lien ne fonctionne pas, copiez cette adresse dans votre navigateur :<br>{{ evaluation_url }}</p><p>Merci de faire vivre l’entraide sur {{ app_name }}.</p>',
            ],
            'en' => [
                'name' => 'Invitation to rate the helper',
                'subject' => 'A quick word on {{ aidant_name }}’s help?',
                'html' => '<p>Hello {{ recipient_name }},</p><p>Your mutual aid with <strong>{{ aidant_name }}</strong> is complete.</p><p>You can leave them a review to recognize their contribution and help other members of {{ organization_name }}.</p><p>A few stars are enough. A comment is optional.</p><p><a href="{{ evaluation_url }}">Leave a review</a></p><p>If the link does not work, copy this address into your browser:<br>{{ evaluation_url }}</p><p>Thank you for keeping mutual aid alive on {{ app_name }}.</p>',
            ],
        ];
    }
};
