<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TASK-1694 bug F — le gabarit de confirmation prend un nom et un slug
 * parlants.
 *
 * ## Pourquoi, et ce que la recette a montre
 *
 * Cyril ne le retrouvait pas dans `/admin/system-email-templates` : il
 * cherchait « verification » et la ligne s'appelait « Confirmation d'adresse
 * email ». La liste etant triee par NOM, elle vivait dans les « C ».
 *
 * Le slug technique `email_verification` ne disait rien non plus du MOMENT du
 * parcours. Le nouveau le dit, et il est symetrique de celui des points :
 * « ... suite inscription ».
 *
 * ## Le tiret, et pas l'underscore
 *
 * Les slugs existants emploient l'underscore (`new_message`). Cyril a propose
 * la forme a tirets. On suit sa proposition : c'est un identifiant lu par des
 * humains dans une URL d'administration, pas une clef de code.
 *
 * ## Idempotente, et elle ne cree rien
 *
 * `update` seulement, sur les lignes qui portent encore l'ancien slug. Rejouee,
 * elle ne fait rien. Et elle ne touche pas aux TEXTES : seul l'etiquetage
 * change, le contenu reste celui que quelqu'un a pu editer.
 */
return new class extends Migration
{
    private const AVANT = 'email_verification';

    private const APRES = 'confirmation-email-suite-inscription';

    private const NOMS = [
        'fr' => 'Confirmation email suite inscription',
        'en' => 'Email confirmation after signup',
    ];

    public function up(): void
    {
        foreach (self::NOMS as $locale => $nom) {
            DB::table('system_email_templates')
                ->where('slug', self::AVANT)
                ->where('locale', $locale)
                ->update(['slug' => self::APRES, 'name' => $nom, 'updated_at' => now()]);
        }

        // Une ligne sans locale (cas historique) garde le nom francais : la
        // laisser derriere avec l'ancien slug la rendrait orpheline — plus
        // personne ne la lirait, et personne ne saurait pourquoi.
        DB::table('system_email_templates')
            ->where('slug', self::AVANT)
            ->update(['slug' => self::APRES, 'name' => self::NOMS['fr'], 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('system_email_templates')
            ->where('slug', self::APRES)
            ->update(['slug' => self::AVANT, 'name' => 'Confirmation d\'adresse email', 'updated_at' => now()]);
    }
};
