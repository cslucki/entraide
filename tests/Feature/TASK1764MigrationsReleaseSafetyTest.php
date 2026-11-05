<?php

namespace Tests\Feature;

use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * TASK-1764 — les deux migrations de la release 1.762 qui n'etaient pas sures.
 *
 * Trouvees a l'audit de preparation (TASK-1763), sur les cinq migrations que
 * `develop` 1.762 apporte a `main` 1.753 :
 *
 * | migration | defaut |
 * |---|---|
 * | `…090000_add_capacity_to_services_table` | aucun des deux sens garde par `Schema::hasColumn` |
 * | `…150000_add_loop_join_request_created_email_template` | `down()` supprimait une ligne que `up()` avait epargnee |
 *
 * ## Pourquoi mesurer ici et pas en relisant le code
 *
 * Un `down()` ne se lit pas, il se joue : `RefreshDatabase` ne joue que
 * l'aller. Le fichier rejoue donc chaque migration a la main, dans les deux
 * sens, comme `TASK1709EmailTemplateRollbackFidelityTest` l'a etabli.
 *
 * Chaque garde porte sur les DEUX moities : le sens ou le correctif doit
 * preserver, et le sens ou la migration doit encore faire son travail. Un
 * `down()` qui ne supprime plus rien passerait la premiere moitie sans rien
 * acheter.
 */
class TASK1764MigrationsReleaseSafetyTest extends TestCase
{
    use RefreshDatabase;

    private const CAPACITE = '2026_11_05_090000_add_capacity_to_services_table';

    private const GABARIT = '2026_11_05_150000_add_loop_join_request_created_email_template';

    private const SLUG = 'loop_join_request_created';

    private function migration(string $nom): object
    {
        return require database_path('migrations/'.$nom.'.php');
    }

    // ── A — `add_capacity_to_services_table` : idempotence des deux sens ────

    /**
     * Le `up()` rejoue sur une base qui porte deja les colonnes ne casse pas.
     *
     * Ce n'est pas une precaution theorique. Le deploiement PROD est un
     * `php artisan migrate --force` en push-to-deploy : une passe interrompue
     * entre le `ALTER TABLE` et l'ecriture de la ligne de batch laisse la
     * colonne posee et la migration reputee non jouee. La relance echouait
     * alors sur `column "capacity" of relation "services" already exists`, et
     * le deploiement restait coince sur une erreur que rien n'explique.
     *
     * `RefreshDatabase` a deja joue l'aller : les colonnes sont la, et
     * rappeler `up()` est exactement le rejeu qu'on veut mesurer.
     */
    public function test_le_up_capacity_est_rejouable_sur_une_base_qui_porte_deja_les_colonnes(): void
    {
        $this->assertTrue(Schema::hasColumn('services', 'capacity'));
        $this->assertTrue(Schema::hasColumn('services', 'min_participants'));

        $this->migration(self::CAPACITE)->up();

        $this->assertTrue(Schema::hasColumn('services', 'capacity'), 'Le rejeu a perdu `capacity`.');
        $this->assertTrue(Schema::hasColumn('services', 'min_participants'), 'Le rejeu a perdu `min_participants`.');
    }

    /** Le `down()` fait toujours son travail : les deux colonnes partent. */
    public function test_le_down_capacity_retire_bien_les_deux_colonnes(): void
    {
        $this->migration(self::CAPACITE)->down();

        $this->assertFalse(Schema::hasColumn('services', 'capacity'), '`capacity` est restee.');
        $this->assertFalse(Schema::hasColumn('services', 'min_participants'), '`min_participants` est restee.');
    }

    /**
     * Le `down()` rejoue sur une base ou les colonnes n'existent plus
     * s'abstient, au lieu d'echouer.
     *
     * C'est le cas d'un `migrate:rollback` relance apres un premier passage
     * partiel, et celui d'une repetition sur `prod_work`.
     */
    public function test_le_down_capacity_est_rejouable_quand_les_colonnes_sont_absentes(): void
    {
        $this->migration(self::CAPACITE)->down();
        $this->migration(self::CAPACITE)->down();

        $this->assertFalse(Schema::hasColumn('services', 'capacity'));
    }

    /** L'aller-retour-aller rend le schema de depart : la garde n'a rien neutralise. */
    public function test_l_aller_retour_aller_de_capacity_rend_les_colonnes(): void
    {
        $this->migration(self::CAPACITE)->down();
        $this->assertFalse(Schema::hasColumn('services', 'capacity'));

        $this->migration(self::CAPACITE)->up();

        $this->assertTrue(Schema::hasColumn('services', 'capacity'), 'Le second aller n\'a pas repose `capacity`.');
        $this->assertTrue(Schema::hasColumn('services', 'min_participants'), 'Le second aller n\'a pas repose `min_participants`.');
    }

    // ── B — le gabarit d'email : un `down()` qui ne detruit pas ─────────────

    /**
     * Repart d'une table sans aucune ligne globale de ce slug.
     *
     * `RefreshDatabase` a joue la migration : les lignes `fr` et `en` sont
     * deja la. Les retirer permet de poser l'etat de depart voulu par chaque
     * garde.
     */
    private function tableNette(): void
    {
        DB::table('system_email_templates')->where('slug', self::SLUG)->delete();
    }

    /** @return array<string, mixed> une ligne globale du slug, texte au choix */
    private function ligne(string $locale, string $sujet, string $html, ?string $organizationId = null): array
    {
        return [
            'slug' => self::SLUG,
            'locale' => $locale,
            'organization_id' => $organizationId,
            'name' => 'Gabarit pose a la main',
            'subject' => $sujet,
            'content_html' => $html,
            'variables' => json_encode(['loop_name']),
            'enabled' => true,
            'created_at' => now()->subYear(),
            'updated_at' => now()->subYear(),
        ];
    }

    private function globales(): \Illuminate\Support\Collection
    {
        return DB::table('system_email_templates')
            ->where('slug', self::SLUG)
            ->whereNull('organization_id')
            ->orderBy('locale')
            ->get();
    }

    /**
     * LA garde du correctif : un gabarit PREEXISTANT survit au rollback.
     *
     * Le `up()` passe son tour quand une ligne globale du slug existe deja —
     * c'est ce qui le rend rejouable. Mais l'ancien `down()` supprimait toute
     * ligne globale du slug : la ligne epargnee a l'aller etait detruite au
     * retour. Deux occurrences reelles sur une base deployee — un gabarit pose
     * par `SystemEmailTemplateSeeder` avant que la migration ne tourne, et une
     * reecriture du SuperAdmin depuis `/admin/email-templates`.
     *
     * Les deux moities sont mesurees : l'aller ne doit PAS ecraser, et le
     * retour ne doit PAS supprimer.
     */
    public function test_un_gabarit_preexistant_survit_a_l_aller_retour(): void
    {
        $this->tableNette();

        DB::table('system_email_templates')->insert([
            $this->ligne('fr', 'Sujet ecrit par le SuperAdmin', '<p>Texte maison FR</p>'),
            $this->ligne('en', 'Subject written by the SuperAdmin', '<p>Custom text EN</p>'),
        ]);

        $this->migration(self::GABARIT)->up();

        // L'aller a epargne les deux lignes — il n'en a pas ajoute une seconde.
        $this->assertSame(2, $this->globales()->count(), 'L\'aller a double les lignes globales du slug.');
        $this->assertSame('Sujet ecrit par le SuperAdmin', $this->globales()->firstWhere('locale', 'fr')->subject);

        $this->migration(self::GABARIT)->down();

        $apres = $this->globales();
        $this->assertSame(2, $apres->count(), 'Le retour a supprime un gabarit que l\'aller n\'avait pas pose.');
        $this->assertSame(
            'Sujet ecrit par le SuperAdmin',
            $apres->firstWhere('locale', 'fr')->subject,
            'Le gabarit francais preexistant a ete detruit par le rollback.'
        );
        $this->assertSame(
            '<p>Custom text EN</p>',
            $apres->firstWhere('locale', 'en')->content_html,
            'Le gabarit anglais preexistant a ete detruit par le rollback.'
        );
    }

    /**
     * Une personnalisation ecrite APRES l'aller survit aussi.
     *
     * Le cas d'exploitation : la migration pose le defaut, le SuperAdmin
     * reecrit le texte depuis `/admin/email-templates`, puis un rollback
     * applicatif a lieu. Le texte reecrit est une decision humaine que le
     * `up()` ne sait pas rendre.
     */
    public function test_une_personnalisation_posterieure_a_l_aller_survit_au_retour(): void
    {
        $this->tableNette();

        $this->migration(self::GABARIT)->up();
        $this->assertSame(2, $this->globales()->count(), 'L\'aller n\'a pas pose les deux langues.');

        DB::table('system_email_templates')
            ->where('slug', self::SLUG)
            ->where('locale', 'fr')
            ->whereNull('organization_id')
            ->update(['content_html' => '<p>Texte reecrit depuis l\'administration</p>']);

        $this->migration(self::GABARIT)->down();

        $restantes = $this->globales();
        $this->assertSame(1, $restantes->count(), 'Le retour n\'a pas garde exactement la ligne personnalisee.');
        $this->assertSame('fr', $restantes->first()->locale, 'Ce n\'est pas la ligne personnalisee qui est restee.');
        $this->assertSame(
            '<p>Texte reecrit depuis l\'administration</p>',
            $restantes->first()->content_html,
            'La reecriture du SuperAdmin a ete detruite par le rollback.'
        );
    }

    /**
     * L'autre moitie, sans laquelle le correctif n'achete rien : une ligne
     * restee IDENTIQUE au defaut repart bien.
     *
     * Un `down()` vide passerait les deux gardes precedentes.
     */
    public function test_le_retour_retire_les_lignes_restees_identiques_au_defaut(): void
    {
        $this->tableNette();

        $this->migration(self::GABARIT)->up();
        $this->assertSame(2, $this->globales()->count());

        $this->migration(self::GABARIT)->down();

        $this->assertSame(0, $this->globales()->count(), 'Le retour n\'a pas retire les lignes qu\'il avait posees.');
    }

    /**
     * La borne est par LANGUE, pas par slug.
     *
     * Personnaliser le francais ne doit pas retenir l'anglais, et retirer
     * l'anglais ne doit pas emporter le francais. C'est precisement ce que
     * l'ancien `down()`, borne sur le seul couple (slug, global), ne savait
     * pas distinguer.
     */
    public function test_le_retour_distingue_les_langues_une_a_une(): void
    {
        $this->tableNette();

        $this->migration(self::GABARIT)->up();

        DB::table('system_email_templates')
            ->where('slug', self::SLUG)
            ->where('locale', 'en')
            ->whereNull('organization_id')
            ->update(['subject' => 'Subject chosen by hand']);

        $this->migration(self::GABARIT)->down();

        $restantes = $this->globales();
        $this->assertSame(1, $restantes->count());
        $this->assertSame('en', $restantes->first()->locale, 'La langue personnalisee n\'est pas celle qui reste.');
        $this->assertSame('Subject chosen by hand', $restantes->first()->subject);
    }

    /**
     * Un gabarit porte par une Organization n'est jamais touche.
     *
     * Organization = Tenant : une surcharge qu'un tenant s'est ecrite pour
     * lui-meme n'est pas du ressort d'une migration globale, dans aucun des
     * deux sens.
     */
    public function test_un_gabarit_porte_par_une_organization_n_est_jamais_touche(): void
    {
        $this->tableNette();

        $organisation = Organization::factory()->create(['slug' => 'org-1764']);

        DB::table('system_email_templates')->insert(
            $this->ligne('fr', 'Sujet du tenant', '<p>Corps du tenant</p>', $organisation->id)
        );

        $this->migration(self::GABARIT)->up();
        $this->migration(self::GABARIT)->down();

        $ligne = DB::table('system_email_templates')
            ->where('slug', self::SLUG)
            ->where('organization_id', $organisation->id)
            ->first();

        $this->assertNotNull($ligne, 'Le gabarit d\'une Organization a ete detruit.');
        $this->assertSame('Sujet du tenant', $ligne->subject);
        $this->assertSame('<p>Corps du tenant</p>', $ligne->content_html);
    }

    /** Deux cycles complets rendent le meme etat : aucune accumulation. */
    public function test_deux_cycles_complets_rendent_le_meme_etat(): void
    {
        $this->tableNette();

        $this->migration(self::GABARIT)->up();
        $premier = $this->globales()->map(fn ($l) => $l->locale.'|'.$l->subject)->all();

        $this->migration(self::GABARIT)->down();
        $this->migration(self::GABARIT)->up();

        $this->assertSame(
            $premier,
            $this->globales()->map(fn ($l) => $l->locale.'|'.$l->subject)->all(),
            'Le second cycle diverge du premier.'
        );
    }
}
