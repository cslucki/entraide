<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TASK-1775 (CDC C3) — le gabarit administrable de « une demande d'aide a
 * ete publiee dans votre Boucle ».
 *
 * ## Meme doctrine que `add_loop_join_request_created_email_template`
 *
 * Migration, pas seulement seeder : un environnement deja deploye n'execute
 * pas le seeder. Additive et idempotente — une ligne deja posee (rejeu, ou
 * reecrite par le SuperAdmin) n'est jamais ecrasee. Le `down()` ne retire que
 * les lignes restees IDENTIQUES au defaut (doctrine T1711/T1764) : une
 * personnalisation du SuperAdmin survit au rollback.
 *
 * ## Ce gabarit ne part QUE pour qui l'a explicitement demande
 *
 * Au moment ou cette migration a ete ecrite, le canal EMAIL etait eteint par
 * defaut et exigeait un opt-in Boucle par Boucle. **L'arbitrage du 07/11 a
 * inverse ce defaut** : l'email est desormais ACTIF par defaut, et se coupe
 * Boucle par Boucle (`LoopNotificationPreference.email_enabled = false`).
 * Poser ce gabarit n'active toujours aucun envoi par lui-meme : il fournit la
 * CAPACITE d'ecrire un email correctement compose.
 */
return new class extends Migration
{
    private const SLUG = 'loop_help_request_created';

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
                    'loop_name', 'requester_name', 'request_url', 'app_name',
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
                'name' => 'Demande d’aide publiée dans une Boucle',
                'subject' => 'Une demande d’aide dans « {{ loop_name }} »',
                'html' => '<p>Bonjour {{ recipient_name }},</p>'
                    .'<p><strong>{{ requester_name }}</strong> a publié une demande d’aide dans la Boucle '
                    .'<strong>{{ loop_name }}</strong>, dont vous êtes membre.</p>'
                    .'<p><a href="{{ request_url }}">Ouvrir la Boucle</a></p>'
                    .'<p>Rien ne presse : la demande vous attend, vous la lirez quand vous voudrez.</p>'
                    .'<p>À bientôt,<br>L’équipe {{ app_name }}</p>',
            ],
            'en' => [
                'name' => 'Help request published in a Loop',
                'subject' => 'A help request in “{{ loop_name }}”',
                'html' => '<p>Hello {{ recipient_name }},</p>'
                    .'<p><strong>{{ requester_name }}</strong> published a help request in the Loop '
                    .'<strong>{{ loop_name }}</strong>, which you are a member of.</p>'
                    .'<p><a href="{{ request_url }}">Open the Loop</a></p>'
                    .'<p>No rush: the request will wait for you.</p>'
                    .'<p>See you soon,<br>The {{ app_name }} team</p>',
            ],
        ];
    }
};
