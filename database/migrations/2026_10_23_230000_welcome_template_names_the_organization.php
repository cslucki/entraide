<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TASK-1694 bug E — le gabarit « Bienvenue » nomme l'ORGANIZATION, plus la
 * marque.
 *
 * ## Le defaut, signale en recette
 *
 * L'email d'accueil disait « Bienvenue sur Entraide, {{ name }} ! » a quelqu'un
 * qui venait de rejoindre « Organismes de formation solidaires et humanistes ».
 * La marque n'est pas ce que la personne a rejoint.
 *
 * ## La racine : la ligne STOCKEE, pas le code
 *
 * `SystemEmailTemplateSeeder` definit pourtant
 * « Bienvenue sur {{ organization }}, {{ name }} ! ». La ligne globale en base
 * portait, elle, la marque en dur — un texte plus ancien que la definition, ou
 * edite depuis l'administration. Mesure : UNE seule ligne sur 28 citait
 * « Entraide ».
 *
 * ## Ce que cette migration ne fait pas
 *
 * Elle ne touche QUE la chaine exacte « Bienvenue sur Entraide, » et seulement
 * sur la ligne GLOBALE de `welcome`. Un remplacement large aurait reecrit des
 * textes que quelqu'un a pu vouloir ainsi — et une migration qui reecrit une
 * intention humaine detruit du travail sans le dire.
 */
return new class extends Migration
{
    private const AVANT = 'Bienvenue sur Entraide, {{ name }} !';

    private const APRES = 'Bienvenue sur {{ organisation }}, {{ name }} !';

    public function up(): void
    {
        DB::table('system_email_templates')
            ->where('slug', 'welcome')
            ->whereNull('organization_id')
            ->where('subject', self::AVANT)
            ->update(['subject' => self::APRES, 'updated_at' => now()]);

        // Le corps porte la meme phrase en titre.
        $ligne = DB::table('system_email_templates')
            ->where('slug', 'welcome')
            ->whereNull('organization_id')
            ->first();

        // DEUX marques, et c'est la mesure qui l'a dit : le SUJET portait
        // « Entraide », le CORPS « BouclePro ». Chercher la seule marque vue
        // dans la capture aurait laisse l'autre en place — et le titre de
        // l'email aurait continue de nommer la plateforme.
        if ($ligne === null) {
            return;
        }

        $corps = (string) $ligne->content_html;

        foreach (['Bienvenue sur Entraide,', 'Bienvenue sur BouclePro,'] as $marque) {
            $corps = str_replace($marque, 'Bienvenue sur {{ organisation }},', $corps);
        }

        if ($corps !== $ligne->content_html) {
            DB::table('system_email_templates')
                ->where('id', $ligne->id)
                ->update(['content_html' => $corps, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        DB::table('system_email_templates')
            ->where('slug', 'welcome')
            ->whereNull('organization_id')
            ->where('subject', self::APRES)
            ->update(['subject' => self::AVANT, 'updated_at' => now()]);
    }
};
