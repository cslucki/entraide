<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\SystemEmailTemplate;
use Database\Seeders\SystemEmailTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TASK-1694, bug Q — la cible : 9 slugs × 2 langues, en Global.
 *
 * ## Pourquoi une migration et pas le seeder
 *
 * Le bug N a changé ce que le **seeder** produit — mais un seeder ne repasse
 * pas sur une base en service. La migration porte la cible sur les bases déjà
 * installées, la production comprise.
 *
 * ## Elle n'est sûre que depuis le bug P
 *
 * Ces lignes naissent **activées** : c'est tout l'objet, qu'elles soient lues.
 * Avant le bug P, cela aurait déshabillé tous les emails transactionnels d'un
 * coup.
 *
 * ## Le test rejoue la migration sur un état CONSTRUIT
 *
 * La migration a déjà tourné quand la suite démarre : la mesurer sur l'état
 * final ne prouverait rien. Chaque test efface les lignes, fabrique l'état
 * d'avant, puis rappelle la migration — c'est la seule façon de voir le geste
 * agir.
 */
class TASK1694NormalizeTemplatesToGlobalTest extends TestCase
{
    use RefreshDatabase;

    private const FICHIER = __DIR__.'/../../database/migrations/2026_10_24_140000_normalize_system_email_templates_to_global.php';

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create(['slug' => 'org-1694q', 'is_active' => true]);

        SystemEmailTemplate::query()->delete();
    }

    private function rejouer(): object
    {
        $migration = require self::FICHIER;
        $migration->up();

        return $migration;
    }

    private function catalogue(): int
    {
        return count(SystemEmailTemplateSeeder::definitions());
    }

    /** LA cible : chaque slug du catalogue, dans les deux langues, en Global. */
    public function test_every_catalogue_slug_exists_in_both_locales_as_global(): void
    {
        $this->rejouer();

        foreach (array_keys(SystemEmailTemplateSeeder::definitions()) as $slug) {
            foreach (['fr', 'en'] as $langue) {
                $this->assertDatabaseHas('system_email_templates', [
                    'slug' => $slug,
                    'locale' => $langue,
                    'organization_id' => null,
                    'enabled' => true,
                ]);
            }
        }

        $this->assertSame(
            $this->catalogue() * 2,
            SystemEmailTemplate::query()->whereNull('organization_id')->count(),
        );
    }

    /** Plus aucune ligne globale sans langue : « une version EN et FR ». */
    public function test_no_global_row_is_left_without_a_locale(): void
    {
        $this->gabaritSansLangue('new_message', SystemEmailTemplateSeeder::rowFor('new_message', 'fr'));

        $this->rejouer();

        $this->assertSame(
            0,
            SystemEmailTemplate::query()->whereNull('organization_id')->whereNull('locale')->count(),
        );
    }

    /**
     * Une ligne sans langue qui PORTE un texte propre est promue, pas écrasée.
     *
     * Mesure du 23/10 : sur les quatre lignes globales sans langue, **une
     * diffère** du catalogue. Insérer par-dessus l'aurait laissée derrière,
     * inatteignable — c'est-à-dire aurait perdu un texte peut-être écrit par
     * quelqu'un.
     */
    public function test_a_hand_written_row_without_locale_is_promoted(): void
    {
        $this->gabaritSansLangue('new_message', [
            'name' => 'Historique',
            'subject' => 'SUJET_ECRIT_A_LA_MAIN_1694Q',
            'content_html' => '<p>corps ecrit a la main</p>',
            'variables' => ['name'],
        ]);

        $this->rejouer();

        $this->assertDatabaseHas('system_email_templates', [
            'slug' => 'new_message',
            'subject' => 'SUJET_ECRIT_A_LA_MAIN_1694Q',
            'organization_id' => null,
            'locale' => 'fr',
        ]);
    }

    /**
     * Une ligne sans langue qui ne fait que RÉPÉTER le catalogue est retirée.
     *
     * Elle ne porte aucune information que l'insertion ne redonne, et la garder
     * laisserait un cran mort dans l'écran d'administration. Ce tri est aussi
     * ce qui rend le `down()` exact : il ne retire que ce qui est identique au
     * catalogue, c'est-à-dire exactement ce que l'aller a posé.
     */
    public function test_a_row_without_locale_that_merely_repeats_the_catalogue_is_removed(): void
    {
        $this->gabaritSansLangue('new_message', SystemEmailTemplateSeeder::rowFor('new_message', 'fr'));

        $this->rejouer();

        $this->assertSame(
            2,
            SystemEmailTemplate::query()->where('slug', 'new_message')->count(),
            'fr et en, et rien d\'autre',
        );
    }

    /** Rejouée, elle ne fait rien. */
    public function test_it_is_idempotent(): void
    {
        $this->rejouer();
        $compte = SystemEmailTemplate::query()->count();

        $this->rejouer();

        $this->assertSame($compte, SystemEmailTemplate::query()->count());
    }

    /** Elle n'écrase JAMAIS un texte édité depuis l'administration. */
    public function test_it_never_overwrites_an_edited_row(): void
    {
        $this->rejouer();

        SystemEmailTemplate::query()
            ->where('slug', 'new_message')->where('locale', 'fr')->whereNull('organization_id')
            ->update(['subject' => 'EDITE_PAR_UN_HUMAIN_1694Q']);

        $this->rejouer();

        $this->assertDatabaseHas('system_email_templates', [
            'slug' => 'new_message',
            'locale' => 'fr',
            'subject' => 'EDITE_PAR_UN_HUMAIN_1694Q',
        ]);
    }

    /**
     * Elle ne touche à AUCUNE ligne rattachée à une Organization.
     *
     * Leur suppression est un geste à part : on ne détruit pas dans la même
     * migration qui construit.
     */
    public function test_it_leaves_per_organization_rows_alone(): void
    {
        $organization = Organization::query()->where('slug', 'org-1694q')->sole();

        SystemEmailTemplate::query()->create([
            'slug' => 'new_message',
            'locale' => 'fr',
            'organization_id' => $organization->id,
            'name' => 'Tenant',
            'subject' => 'SUJET_TENANT_1694Q',
            'content_html' => '<p>t</p>',
            'variables' => ['name'],
            'enabled' => true,
        ]);

        $this->rejouer();

        $this->assertDatabaseHas('system_email_templates', [
            'organization_id' => $organization->id,
            'subject' => 'SUJET_TENANT_1694Q',
        ]);
    }

    /**
     * Le retour ne détruit pas un texte humain.
     *
     * Un `down()` qui effacerait du travail serait pire que pas de `down()` du
     * tout : il ne retire que les lignes restées identiques au catalogue.
     */
    public function test_the_rollback_keeps_what_someone_edited(): void
    {
        $migration = $this->rejouer();

        SystemEmailTemplate::query()
            ->where('slug', 'new_message')->where('locale', 'fr')->whereNull('organization_id')
            ->update(['subject' => 'EDITE_PAR_UN_HUMAIN_1694Q']);

        $migration->down();

        $this->assertDatabaseHas('system_email_templates', [
            'slug' => 'new_message',
            'locale' => 'fr',
            'subject' => 'EDITE_PAR_UN_HUMAIN_1694Q',
        ]);

        $this->assertSame(
            1,
            SystemEmailTemplate::query()->whereNull('organization_id')->count(),
            'tout le reste, pose par l\'aller et jamais touche, est retire',
        );
    }

    private function gabaritSansLangue(string $slug, array $ligne): void
    {
        DB::table('system_email_templates')->insert([
            'slug' => $slug,
            'locale' => null,
            'organization_id' => null,
            'name' => $ligne['name'],
            'subject' => $ligne['subject'],
            'content_html' => $ligne['content_html'],
            'variables' => json_encode($ligne['variables']),
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
