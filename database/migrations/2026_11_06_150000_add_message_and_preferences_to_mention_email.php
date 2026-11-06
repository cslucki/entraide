<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TASK-1772 (recette Cyril, 06/11) — le courriel de mention porte desormais
 * LE MESSAGE et un lien de desabonnement.
 *
 * ## La demande, et sa raison
 *
 * « Cela reduit le FOMO, et on ne stresse pas les membres. Ils peuvent lire le
 * message sur la Boucle, mais ils ont le temps de le faire quand ils veulent
 * car ils ont eu l'info dans l'email. »
 *
 * ## Pourquoi une SECONDE migration et pas une reecriture de la premiere
 *
 * La premiere est deja appliquee — en local, et elle le sera en PROD a la
 * prochaine release. Une migration appliquee ne se reecrit pas : on en ajoute
 * une qui porte le changement.
 *
 * ## Et pourquoi elle ne met a jour QUE les lignes non personnalisees
 *
 * Doctrine T1711 durcie par T1764, appliquee ici a une MISE A JOUR et plus
 * seulement a un `down()` : la borne porte sur le CONTENU. Seules repartent —
 * ou ici ne changent — les lignes restees identiques au defaut precedent. Si
 * le SuperAdmin a reecrit le gabarit depuis `/admin/system-email-templates`,
 * sa version survit : ecraser une decision humaine que le `up()` ne sait pas
 * rendre serait une perte seche.
 */
return new class extends Migration
{
    private const SLUG = 'loop_mention_created';

    public function up(): void
    {
        foreach ($this->versions() as $locale => $v) {
            DB::table('system_email_templates')
                ->where('slug', self::SLUG)
                ->where('locale', $locale)
                ->whereNull('organization_id')
                ->where('content_html', $v['avant'])
                ->update([
                    'content_html' => $v['apres'],
                    'variables' => json_encode([
                        'recipient_name', 'recipient_email', 'organization_name',
                        'loop_name', 'author_name', 'message_url', 'message_excerpt',
                        'preferences_url', 'app_name',
                    ]),
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        foreach ($this->versions() as $locale => $v) {
            DB::table('system_email_templates')
                ->where('slug', self::SLUG)
                ->where('locale', $locale)
                ->whereNull('organization_id')
                ->where('content_html', $v['apres'])
                ->update([
                    'content_html' => $v['avant'],
                    'variables' => json_encode([
                        'recipient_name', 'recipient_email', 'organization_name',
                        'loop_name', 'author_name', 'message_url', 'app_name',
                    ]),
                    'updated_at' => now(),
                ]);
        }
    }

    /** @return array<string, array{avant: string, apres: string}> */
    private function versions(): array
    {
        return [
            'fr' => [
                'avant' => '<p>Bonjour {{ recipient_name }},</p>'
                    .'<p><strong>{{ author_name }}</strong> vous a mentionné dans un message de la Boucle '
                    .'<strong>{{ loop_name }}</strong>.</p>'
                    .'<p><a href="{{ message_url }}">Lire le message</a></p>'
                    .'<p>À bientôt,<br>L’équipe {{ app_name }}</p>',
                'apres' => '<p>Bonjour {{ recipient_name }},</p>'
                    .'<p><strong>{{ author_name }}</strong> vous a mentionné dans un message de la Boucle '
                    .'<strong>{{ loop_name }}</strong>.</p>'
                    // `white-space: pre-line` : le corps est echappe par
                    // `interpolate()`, on ne peut donc pas y injecter de `<br>`.
                    // Le style fait le travail, et l'echappement reste entier.
                    .'<blockquote style="margin:16px 0;padding:12px 16px;border-left:3px solid #ccc;white-space:pre-line">{{ message_excerpt }}</blockquote>'
                    .'<p><a href="{{ message_url }}">Lire le message dans la Boucle</a></p>'
                    .'<p>Rien ne presse : le message vous attend, vous le lirez quand vous voudrez.</p>'
                    .'<p>À bientôt,<br>L’équipe {{ app_name }}</p>'
                    .'<hr style="margin:24px 0;border:0;border-top:1px solid #eee">'
                    .'<p style="font-size:12px;color:#777">Pour ne plus recevoir cet email, allez dans '
                    .'<a href="{{ preferences_url }}">Réglages des notifications</a>.</p>',
            ],
            'en' => [
                'avant' => '<p>Hello {{ recipient_name }},</p>'
                    .'<p><strong>{{ author_name }}</strong> mentioned you in a message from the Loop '
                    .'<strong>{{ loop_name }}</strong>.</p>'
                    .'<p><a href="{{ message_url }}">Read the message</a></p>'
                    .'<p>See you soon,<br>The {{ app_name }} team</p>',
                'apres' => '<p>Hello {{ recipient_name }},</p>'
                    .'<p><strong>{{ author_name }}</strong> mentioned you in a message from the Loop '
                    .'<strong>{{ loop_name }}</strong>.</p>'
                    .'<blockquote style="margin:16px 0;padding:12px 16px;border-left:3px solid #ccc;white-space:pre-line">{{ message_excerpt }}</blockquote>'
                    .'<p><a href="{{ message_url }}">Read the message in the Loop</a></p>'
                    .'<p>No rush: the message will wait for you.</p>'
                    .'<p>See you soon,<br>The {{ app_name }} team</p>'
                    .'<hr style="margin:24px 0;border:0;border-top:1px solid #eee">'
                    .'<p style="font-size:12px;color:#777">To stop receiving this email, go to '
                    .'<a href="{{ preferences_url }}">Notification settings</a>.</p>',
            ],
        ];
    }
};
