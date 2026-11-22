<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TASK-1883 (demande Cyril, 22/11) — gabarit systeme GLOBAL FR/EN de la
 * newsletter hebdomadaire. Memes textes que `SystemEmailTemplateSeeder`.
 *
 * Insertion idempotente (rien si la ligne globale existe deja) ; le `down()`
 * ne retire qu'une ligne restee identique au texte d'origine.
 */
return new class extends Migration
{
    private const SLUG = 'weekly_help_digest';

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
                'variables' => json_encode(['recipient_name', 'organization_name', 'explorer_url', 'unsubscribe_url', 'app_name']),
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
                'name' => 'Newsletter hebdomadaire — demandes et propositions d’aide',
                'subject' => 'Les dernières demandes et propositions d’aide — {{ organization_name }}',
                'html' => '<p>Bonjour {{ recipient_name }},</p><p>Voici les dernières demandes et propositions d’aide de <strong>{{ organization_name }}</strong>. Un coup de main, même petit, fait souvent la différence.</p><h2 style="font-size:18px;margin:24px 0 8px">Ils ont besoin d’aide</h2>{demande_aide;3}<h2 style="font-size:18px;margin:24px 0 8px">Ils proposent leur aide</h2>{proposition_aide;3}{billets_blog;3}<p><a href="{{ explorer_url }}">Voir toutes les demandes et propositions</a></p><p>À bientôt,<br>L’équipe {{ organization_name }}</p><hr style="margin:24px 0;border:0;border-top:1px solid #eee"><p style="font-size:12px;color:#777">Vous recevez cette lettre parce que vous êtes membre de {{ organization_name }}. <a href="{{ unsubscribe_url }}">Se désinscrire de la newsletter</a></p>',
            ],
            'en' => [
                'name' => 'Weekly newsletter — help requests and offers',
                'subject' => 'Latest help requests and offers — {{ organization_name }}',
                'html' => '<p>Hello {{ recipient_name }},</p><p>Here are the latest help requests and offers from <strong>{{ organization_name }}</strong>. A helping hand, however small, often makes the difference.</p><h2 style="font-size:18px;margin:24px 0 8px">They need help</h2>{demande_aide;3}<h2 style="font-size:18px;margin:24px 0 8px">They offer their help</h2>{proposition_aide;3}{billets_blog;3}<p><a href="{{ explorer_url }}">See all requests and offers</a></p><p>See you soon,<br>The {{ organization_name }} team</p><hr style="margin:24px 0;border:0;border-top:1px solid #eee"><p style="font-size:12px;color:#777">You receive this letter because you are a member of {{ organization_name }}. <a href="{{ unsubscribe_url }}">Unsubscribe from the newsletter</a></p>',
            ],
        ];
    }
};
