<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TASK-1894 correctif 2 — les TROIS gabarits exemples de l'habillage au nom de
 * l'organisation (Cyril : « cadre + 3 exemples d'abord ») :
 *
 * - leur lien principal devient un BOUTON (meme style partout) ;
 * - `loop_mention_created` perd sa signature « L'equipe {{ app_name }} » et sa
 *   mention de reglages : le cadre commun les porte desormais
 *   (`emails.partials.cloture`), sinon elles apparaitraient deux fois.
 *
 * Remplacements EXACTS, sur les gabarits GLOBAUX seulement : un texte deja
 * reecrit par un administrateur n'est pas touche (la chaine n'y est plus).
 * Idempotente ; `down()` remet les chaines d'origine.
 */
return new class extends Migration
{
    public const BOUTON = 'display:inline-block;background:#4f46e5;color:#ffffff;padding:10px 18px;border-radius:8px;text-decoration:none;font-weight:600';

    /** @return array<string, array<string, list<array{0: string, 1: string}>>> */
    public static function remplacements(): array
    {
        $b = self::BOUTON;

        return [
            'loop_mention_created' => [
                'fr' => [
                    ['<p><a href="{{ message_url }}">Lire le message dans la Boucle</a></p>', '<p><a href="{{ message_url }}" style="'.$b.'">Lire le message dans la Boucle</a></p>'],
                    ['<p>À bientôt,<br>L’équipe {{ app_name }}</p><hr style="margin:24px 0;border:0;border-top:1px solid #eee"><p style="font-size:12px;color:#777">Pour ne plus recevoir cet email, allez dans <a href="{{ preferences_url }}">Réglages des notifications</a>.</p>', ''],
                ],
                'en' => [
                    ['<p><a href="{{ message_url }}">Read the message in the Loop</a></p>', '<p><a href="{{ message_url }}" style="'.$b.'">Read the message in the Loop</a></p>'],
                    ['<p>See you soon,<br>The {{ app_name }} team</p><hr style="margin:24px 0;border:0;border-top:1px solid #eee"><p style="font-size:12px;color:#777">To stop receiving this email, go to <a href="{{ preferences_url }}">Notification settings</a>.</p>', ''],
                ],
            ],
            'new_message' => [
                'fr' => [
                    ['<p><a href="{{ url }}">Voir la conversation</a></p>', '<p><a href="{{ url }}" style="'.$b.'">Voir la conversation</a></p>'],
                    ['<blockquote>{{ message_preview }}</blockquote>', '<blockquote style="margin:16px 0;padding:12px 16px;border-left:3px solid #ccc;white-space:pre-line">{{ message_preview }}</blockquote>'],
                ],
                'en' => [
                    ['<p><a href="{{ url }}">View conversation</a></p>', '<p><a href="{{ url }}" style="'.$b.'">View conversation</a></p>'],
                    ['<blockquote>{{ message_preview }}</blockquote>', '<blockquote style="margin:16px 0;padding:12px 16px;border-left:3px solid #ccc;white-space:pre-line">{{ message_preview }}</blockquote>'],
                ],
            ],
            'confirmation-email-suite-inscription' => [
                'fr' => [
                    ['<p><a href="{{ url }}">Confirmer mon adresse</a></p>', '<p><a href="{{ url }}" style="'.$b.'">Confirmer mon adresse</a></p>'],
                ],
                'en' => [
                    ['<p><a href="{{ url }}">Confirm my address</a></p>', '<p><a href="{{ url }}" style="'.$b.'">Confirm my address</a></p>'],
                ],
            ],
        ];
    }

    public function up(): void
    {
        $this->appliquer(false);
    }

    public function down(): void
    {
        $this->appliquer(true);
    }

    private function appliquer(bool $inverse): void
    {
        foreach (self::remplacements() as $slug => $parLangue) {
            foreach ($parLangue as $langue => $paires) {
                $lignes = DB::table('system_email_templates')
                    ->whereNull('organization_id')
                    ->where('slug', $slug)
                    ->where('locale', $langue)
                    ->get(['id', 'content_html']);

                foreach ($lignes as $ligne) {
                    $html = (string) $ligne->content_html;

                    foreach ($paires as [$avant, $apres]) {
                        [$de, $vers] = $inverse ? [$apres, $avant] : [$avant, $apres];

                        // Une chaine vide ne se « retrouve » pas : au retour, la
                        // signature retiree est recollee a la fin du corps.
                        if ($de === '') {
                            if (! str_contains($html, $vers)) {
                                $html .= $vers;
                            }

                            continue;
                        }

                        if (str_contains($html, $de) && ($vers === '' || ! str_contains($html, $vers))) {
                            $html = str_replace($de, $vers, $html);
                        }
                    }

                    if ($html !== (string) $ligne->content_html) {
                        DB::table('system_email_templates')->where('id', $ligne->id)->update(['content_html' => $html, 'updated_at' => now()]);
                    }
                }
            }
        }
    }
};
