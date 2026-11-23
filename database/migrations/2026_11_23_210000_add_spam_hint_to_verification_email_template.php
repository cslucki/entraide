<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TASK-1885 C10 (demande Cyril, 22/11) — l'email de confirmation d'inscription
 * demande de verifier que les messages envoyes par « relai-smtp.net »
 * n'arrivent pas dans les indesirables.
 *
 * ## Elle COMPLETE, elle n'ecrase pas
 *
 * Le texte est modifiable dans `/admin/system-email-templates`. La migration
 * AJOUTE un paragraphe a la fin du contenu existant, quel qu'il soit, et
 * seulement s'il ne mentionne pas deja « relai-smtp.net » : une edition
 * humaine est conservee telle quelle, et rejouer la migration ne duplique
 * rien. Le seeder, corrige en parallele, sert les bases neuves.
 */
return new class extends Migration
{
    private const SLUG = 'confirmation-email-suite-inscription';

    private const PARAGRAPHE = [
        'fr' => '<p>Pour recevoir nos prochains messages, vérifiez que les emails envoyés par « relai-smtp.net » n\'arrivent pas dans vos indésirables (spam).</p>',
        'en' => '<p>To receive our next messages, make sure emails sent by “relai-smtp.net” do not end up in your junk (spam) folder.</p>',
    ];

    public function up(): void
    {
        foreach (self::PARAGRAPHE as $locale => $paragraphe) {
            $lignes = DB::table('system_email_templates')
                ->where('slug', self::SLUG)
                ->where('locale', $locale)
                ->get(['id', 'content_html']);

            foreach ($lignes as $ligne) {
                $contenu = (string) $ligne->content_html;

                if (str_contains($contenu, 'relai-smtp.net')) {
                    continue;
                }

                DB::table('system_email_templates')->where('id', $ligne->id)->update([
                    'content_html' => rtrim($contenu)."\n".$paragraphe,
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /** Symetrique : on ne retire que le paragraphe exact pose par `up()`. */
    public function down(): void
    {
        foreach (self::PARAGRAPHE as $locale => $paragraphe) {
            $lignes = DB::table('system_email_templates')
                ->where('slug', self::SLUG)
                ->where('locale', $locale)
                ->get(['id', 'content_html']);

            foreach ($lignes as $ligne) {
                $contenu = (string) $ligne->content_html;

                if (! str_contains($contenu, $paragraphe)) {
                    continue;
                }

                DB::table('system_email_templates')->where('id', $ligne->id)->update([
                    'content_html' => str_replace("\n".$paragraphe, '', $contenu),
                    'updated_at' => now(),
                ]);
            }
        }
    }
};
