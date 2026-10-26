<?php

namespace Tests\Feature;

use App\Models\Country;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TASK-1707 L1 — les noms de pays francais portent leurs accents.
 *
 * Troisieme cas nomme par la recette OFSH du 25/10, et le seul que la passe sur
 * `lang/fr` ne pouvait pas atteindre : ces noms vivent en BASE, semes par
 * `2026_06_26_200000_create_countries_table`. Mesure avant correction :
 * **58 pays, 0 accentue**.
 *
 * ## Pourquoi une garde, pour ce qui est « juste des libelles »
 *
 * La regle de calibration exclut les gardes sur un libelle, et elle a raison :
 * asserter « Algérie » ne prouverait que sa propre recopie. Ce fichier mesure
 * autre chose — **la conservation** et **la completude** :
 *
 * 1. une migration qui ecrase un libelle edite a la main serait un defaut de
 *    comportement, pas de langue ; c'est le `WHERE name_fr = <ancien>` qui
 *    l'empeche, et c'est testable ;
 * 2. « plus aucun nom francais sans accent requis » est une propriete de
 *    l'ENSEMBLE, pas d'une chaine : c'est exactement ce que ma passe sur
 *    `lang/fr` n'avait pas verifie, et pourquoi elle est restee a moitie faite.
 */
class TASK1707CountryNamesAccentsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Les quinze noms dont l'orthographe francaise EXIGE un accent.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function nomsAccentues(): array
    {
        return [
            'Benin' => ['BJ', 'Bénin'],
            'Bresil' => ['BR', 'Brésil'],
            'Congo RD' => ['CD', 'République démocratique du Congo'],
            'Cote d Ivoire' => ['CI', "Côte d'Ivoire"],
            'Algerie' => ['DZ', 'Algérie'],
            'Guinee' => ['GN', 'Guinée'],
            'Grece' => ['GR', 'Grèce'],
            'Haiti' => ['HT', 'Haïti'],
            'Coree du Sud' => ['KR', 'Corée du Sud'],
            'Ile Maurice' => ['MU', 'Île Maurice'],
            'Norvege' => ['NO', 'Norvège'],
            'Nouvelle-Zelande' => ['NZ', 'Nouvelle-Zélande'],
            'Suede' => ['SE', 'Suède'],
            'Senegal' => ['SN', 'Sénégal'],
            'Etats-Unis' => ['US', 'États-Unis'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nomsAccentues')]
    public function test_le_nom_francais_porte_ses_accents(string $code, string $attendu): void
    {
        $this->assertSame(
            $attendu,
            Country::where('code', $code)->value('name_fr'),
            "Le nom francais de {$code} n'est pas accentue."
        );
    }

    /**
     * Et plus AUCUN nom francais ne porte un mot qui exigeait un accent.
     *
     * C'est la garde de COMPLETUDE — celle qui manquait a ma passe sur
     * `lang/fr`, et qui l'a laissee a moitie faite. Elle balaie les 58 lignes,
     * pas les 15 que je sais avoir corrigees.
     *
     * Le motif ne liste que des formes dont la version accentuee est la SEULE
     * graphie francaise : « Nigeria », « Kenya » ou « Rwanda » n'y figurent pas,
     * parce que choisir entre deux variantes legitimes est une decision
     * editoriale et non la correction d'un defaut.
     */
    public function test_aucun_nom_francais_ne_reste_sans_son_accent(): void
    {
        $fautifs = Country::query()
            ->get(['code', 'name_fr'])
            ->filter(fn ($p) => preg_match(
                '/\b(Algerie|Benin|Bresil|Coree|Cote d |Grece|Guinee|Haiti|Ile |Norvege|Nouvelle-Zelande|Republique|Senegal|Suede|Etats-Unis|democratique)\b/u',
                (string) $p->name_fr
            ) === 1)
            ->map(fn ($p) => $p->code.' = '.$p->name_fr)
            ->values()
            ->all();

        $this->assertSame([], $fautifs, "Des noms de pays sont encore sans accent :\n".implode("\n", $fautifs));
    }

    /**
     * Un nom renomme a la main n'est PAS ecrase.
     *
     * La migration ne met a jour que les lignes portant encore la valeur
     * d'origine. C'est le seul volet de comportement de ce fichier, et le seul
     * dont un sabotage change le resultat.
     */
    public function test_un_nom_deja_modifie_a_la_main_n_est_pas_ecrase(): void
    {
        DB::table('countries')->where('code', 'DZ')->update(['name_fr' => 'Algérie (Nord)']);

        $this->rejouerLaMigration();

        $this->assertSame(
            'Algérie (Nord)',
            Country::where('code', 'DZ')->value('name_fr'),
            'Un libelle edite a la main a ete ecrase par la migration.'
        );
    }

    /**
     * Et rejouer la migration sur une ligne DEJA corrigee ne la casse pas.
     */
    public function test_rejouer_la_migration_est_sans_effet(): void
    {
        $this->rejouerLaMigration();

        $this->assertSame('Algérie', Country::where('code', 'DZ')->value('name_fr'));
        $this->assertSame("Côte d'Ivoire", Country::where('code', 'CI')->value('name_fr'));
    }

    private function rejouerLaMigration(): void
    {
        $chemin = database_path('migrations/2026_10_26_140000_fix_country_names_fr_accents.php');
        $this->assertFileExists($chemin);

        (require $chemin)->up();
    }
}
