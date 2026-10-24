<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TASK-1694 bug H — « welcome » devient « affectation-points-suite-inscription ».
 *
 * ## Pourquoi ce nom
 *
 * « welcome » datait d'une epoque ou l'email partait A L'INSCRIPTION. Ce qu'il
 * annonce reellement, c'est l'AFFECTATION DES 100 POINTS — et depuis le bug I
 * il part apres la confirmation d'adresse. Le slug dit desormais ce que l'email
 * ANNONCE, pas un moment qui a change.
 *
 * Nom et slug proposes par Cyril.
 *
 * ## Idempotente, et elle ne touche pas aux textes
 *
 * `update` sur les lignes qui portent encore l'ancien slug. Rejouee, elle ne
 * fait rien. Le contenu reste celui que quelqu'un a pu editer depuis
 * l'administration : seul l'etiquetage change.
 */
return new class extends Migration
{
    private const AVANT = 'welcome';

    private const APRES = 'affectation-points-suite-inscription';

    private const NOMS = [
        'fr' => 'Affectation points suite inscription',
        'en' => 'Points granted after signup',
    ];

    public function up(): void
    {
        foreach (self::NOMS as $locale => $nom) {
            DB::table('system_email_templates')
                ->where('slug', self::AVANT)
                ->where('locale', $locale)
                ->update(['slug' => self::APRES, 'name' => $nom, 'updated_at' => now()]);
        }

        // Les lignes sans locale (cas historique, dont la ligne GLOBALE)
        // gardent le nom francais : les laisser derriere avec l'ancien slug les
        // rendrait orphelines — plus personne ne les lirait.
        DB::table('system_email_templates')
            ->where('slug', self::AVANT)
            ->update(['slug' => self::APRES, 'name' => self::NOMS['fr'], 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('system_email_templates')
            ->where('slug', self::APRES)
            ->update(['slug' => self::AVANT, 'name' => 'Bienvenue', 'updated_at' => now()]);
    }
};
