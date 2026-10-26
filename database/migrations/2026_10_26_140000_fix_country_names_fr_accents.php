<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TASK-1707 L1 — les noms de pays francais n'avaient AUCUN accent.
 *
 * Recette OFSH du 25/10 : « Algerie », « Benin », « Bresil », « Coree du Sud »,
 * « Cote d Ivoire », « Grece », « Guinee », « Haiti », « Norvege », « Senegal »,
 * « Suede »… C'etait le TROISIEME cas que la recette nommait, et le seul que la
 * passe sur `lang/fr` ne pouvait pas atteindre : ces noms vivent en BASE, semes
 * par `2026_06_26_200000_create_countries_table`.
 *
 * Mesure avant correction, sur la base de developpement :
 *
 *     SELECT COUNT(*) FILTER (WHERE name_fr ~ '[éèêàçîôûùëïâ]') FROM countries
 *     -> 58 pays, 0 accentue
 *
 * ## Ce que cette migration ne fait PAS
 *
 * Elle ne touche que **15** lignes : celles dont l'orthographe francaise EXIGE
 * un accent ou une apostrophe. Les 43 autres — France, Canada, Mali, Togo,
 * Liban, Comores, Seychelles… — sont deja justes sans accent, et les renommer
 * serait une correction inventee.
 *
 * `Nigeria`, `Kenya` et `Rwanda` restent tels quels a dessein : « Nigeria » est
 * une graphie francaise attestee a cote de « Nigéria ». Choisir entre deux
 * variantes legitimes est une decision editoriale, pas la correction d'un
 * defaut — et ce n'est pas ce que la recette signalait.
 *
 * ## Conservatrice par construction
 *
 * Le `WHERE name_fr = <ancienne valeur>` fait que la ligne n'est mise a jour
 * que si elle porte ENCORE le nom d'origine. Une Organization qui aurait
 * renomme un pays a la main garde son libelle — meme regle que la migration du
 * gabarit d'email de cette TASK.
 *
 * `down()` est l'exact symetrique, pour la meme raison.
 */
return new class extends Migration
{
    /**
     * code ISO => [ancien nom, nom accentue]
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const NOMS = [
        'BJ' => ['Benin', 'Bénin'],
        'BR' => ['Bresil', 'Brésil'],
        'CD' => ['Republique democratique du Congo', 'République démocratique du Congo'],
        'CI' => ['Cote d Ivoire', "Côte d'Ivoire"],
        'DZ' => ['Algerie', 'Algérie'],
        'GN' => ['Guinee', 'Guinée'],
        'GR' => ['Grece', 'Grèce'],
        'HT' => ['Haiti', 'Haïti'],
        'KR' => ['Coree du Sud', 'Corée du Sud'],
        'MU' => ['Ile Maurice', 'Île Maurice'],
        'NO' => ['Norvege', 'Norvège'],
        'NZ' => ['Nouvelle-Zelande', 'Nouvelle-Zélande'],
        'SE' => ['Suede', 'Suède'],
        'SN' => ['Senegal', 'Sénégal'],
        'US' => ['Etats-Unis', 'États-Unis'],
    ];

    public function up(): void
    {
        foreach (self::NOMS as $code => [$avant, $apres]) {
            DB::table('countries')
                ->where('code', $code)
                ->where('name_fr', $avant)
                ->update(['name_fr' => $apres, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        foreach (self::NOMS as $code => [$avant, $apres]) {
            DB::table('countries')
                ->where('code', $code)
                ->where('name_fr', $apres)
                ->update(['name_fr' => $avant, 'updated_at' => now()]);
        }
    }
};
