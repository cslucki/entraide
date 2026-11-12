<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TASK-1795 correctif 2 — le gabarit « message prive recu », Global, FR et EN.
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
 * Ecrire la migration SEULE avait fait rougir les deux gates en T1720 : une
 * base reconstruite de zero n'aurait jamais eu le gabarit, et
 * `SystemEmailTemplateTest::test_seeder_creates_global_templates_independent_of_organizations`
 * compte `count(definitions()) * 2`.
 *
 * ## Le texte est DUPLIQUE, et c'est voulu
 *
 * Une migration est un fait historique : son resultat ne doit pas changer parce
 * qu'on a reecrit le seeder apres coup. Ce fichier a ete GENERE depuis le
 * catalogue — la divergence est impossible a la naissance — et un test compare
 * les deux textes programmatiquement, pas a l'oeil.
 *
 * Idempotente : `up()` n'insere que si la ligne manque, et `down()` ne retire
 * que la ligne restee conforme a ce qu'elle a pose — un texte reecrit par le
 * SuperAdmin depuis `/admin/system-email-templates` survit donc au rollback.
 */
return new class extends Migration
{
    private const SLUG = 'private_message_received';

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
                'variables' => json_encode(['sender_first_name', 'sender_name', 'recipient_name', 'organization_name', 'message_preview', 'message_url', 'app_name']),
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
                'name' => 'Message privé reçu',
                'subject' => 'Message privé de {{ sender_first_name }} {{ sender_name }}',
                'html' => '<p>Bonjour {{ recipient_name }},</p><p>Vous avez reçu un message privé de <strong>{{ sender_first_name }} {{ sender_name }}</strong> sur {{ organization_name }}.</p><blockquote style="margin:16px 0;padding:12px 16px;border-left:3px solid #ccc;white-space:pre-line">{{ message_preview }}</blockquote><p><a href="{{ message_url }}" style="display:inline-block;padding:10px 18px;background:#4f46e5;color:#ffffff;text-decoration:none;border-radius:6px">Lire le message et répondre</a></p><p style="font-size:12px;color:#777">Lien : {{ message_url }}</p><p>À bientôt,<br>L’équipe {{ app_name }}</p>',
            ],
            'en' => [
                'name' => 'Private message received',
                'subject' => 'Private message from {{ sender_first_name }} {{ sender_name }}',
                'html' => '<p>Hello {{ recipient_name }},</p><p>You received a private message from <strong>{{ sender_first_name }} {{ sender_name }}</strong> on {{ organization_name }}.</p><blockquote style="margin:16px 0;padding:12px 16px;border-left:3px solid #ccc;white-space:pre-line">{{ message_preview }}</blockquote><p><a href="{{ message_url }}" style="display:inline-block;padding:10px 18px;background:#4f46e5;color:#ffffff;text-decoration:none;border-radius:6px">Read and reply</a></p><p style="font-size:12px;color:#777">Link: {{ message_url }}</p><p>See you soon,<br>The {{ app_name }} team</p>',
            ],
        ];
    }
};
