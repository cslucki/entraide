<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TASK-1772 — le gabarit administrable de « on vous a mentionne ».
 *
 * Copie fidele de la doctrine posee par T1711 et durcie par T1764. Les trois
 * regles qui comptent, et chacune a deja ete payee :
 *
 *  - **`up()` idempotent** : une ligne deja posee — par un rejeu, ou reecrite
 *    par le SuperAdmin depuis `/admin/system-email-templates` — n'est JAMAIS
 *    ecrasee ;
 *  - **pas d'`id` explicite** : `system_email_templates.id` est un BIGINT
 *    auto-incremente, pas un UUID. PostgreSQL refuse un UUID avec `22P02`
 *    (defaut paye en T1720) ;
 *  - **`down()` borne sur le CONTENU** : ne repartent que les lignes restees
 *    identiques au defaut (meme `subject` ET meme `content_html`). Une
 *    personnalisation survit au rollback — la detruire serait perdre une
 *    decision humaine que le `up()` ne sait pas rendre. C'est exactement le
 *    defaut que T1764 a corrige sur les deux migrations de la release 1.764.
 *
 * Le seeder porte AUSSI l'entree : un gabarit pose par migration doit entrer
 * au catalogue du seeder, sinon une base reconstruite de zero ne l'a pas.
 */
return new class extends Migration
{
    private const SLUG = 'loop_mention_created';

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
                    'loop_name', 'author_name', 'message_url', 'app_name',
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
                'name' => 'Mention dans une Boucle',
                'subject' => '{{ author_name }} vous a mentionné dans « {{ loop_name }} »',
                'html' => '<p>Bonjour {{ recipient_name }},</p>'
                    .'<p><strong>{{ author_name }}</strong> vous a mentionné dans un message de la Boucle '
                    .'<strong>{{ loop_name }}</strong>.</p>'
                    .'<p><a href="{{ message_url }}">Lire le message</a></p>'
                    .'<p>À bientôt,<br>L’équipe {{ app_name }}</p>',
            ],
            'en' => [
                'name' => 'Mention in a Loop',
                'subject' => '{{ author_name }} mentioned you in “{{ loop_name }}”',
                'html' => '<p>Hello {{ recipient_name }},</p>'
                    .'<p><strong>{{ author_name }}</strong> mentioned you in a message from the Loop '
                    .'<strong>{{ loop_name }}</strong>.</p>'
                    .'<p><a href="{{ message_url }}">Read the message</a></p>'
                    .'<p>See you soon,<br>The {{ app_name }} team</p>',
            ],
        ];
    }
};
