<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\SystemEmailTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * TASK-1694, bug S — les gabarits rattachés à une Organization sont ARCHIVÉS
 * puis retirés.
 *
 * ## Pourquoi une table d'archive, et pas un simple `delete()`
 *
 * Une migration qui supprime n'a **pas de `down()` possible** : les lignes sont
 * parties, et le retour ne peut plus que mentir. L'archive rend la suppression
 * **réversible** — la seule façon de détruire des données de production sans
 * paris. Elle sert aussi de **preuve** : ce qui a été retiré reste lisible,
 * avec sa date.
 *
 * ## Mesure avant de détruire
 *
 * Local, le 23/10 : **22 lignes rattachées, 22 identiques au catalogue, 0
 * modifiée**. Aucune personnalisation humaine. Cette mesure ne vaut que pour la
 * base où elle a été faite — elle doit être **refaite en production**.
 *
 * ## Elle n'est sûre que depuis M, P et Q
 *
 * **M** : une ligne Global est enfin lue. **Q** : les 18 lignes Global existent
 * et sont actives. **P** : un gabarit de base part habillé. Jouée avant ces
 * trois-là, elle aurait fait taire toute la base.
 */
class TASK1694ArchivePerOrganizationTemplatesTest extends TestCase
{
    use RefreshDatabase;

    private const FICHIER = __DIR__.'/../../database/migrations/2026_10_24_160000_archive_and_remove_per_organization_system_email_templates.php';

    private const ARCHIVE = 'system_email_templates_archive';

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1694s',
            'is_active' => true,
        ]);
    }

    private function migration(): object
    {
        return require self::FICHIER;
    }

    private function ligneTenant(string $sujet = 'SUJET_TENANT_1694S'): SystemEmailTemplate
    {
        return SystemEmailTemplate::query()->create([
            'slug' => 'new_message',
            'locale' => 'fr',
            'organization_id' => $this->organization->id,
            'name' => 'Tenant 1694S',
            'subject' => $sujet,
            'content_html' => '<p>corps</p>',
            'variables' => ['name'],
            'enabled' => true,
        ]);
    }

    /** LE geste : la ligne part de la table vivante, et se retrouve en archive. */
    public function test_a_per_organization_row_is_archived_then_removed(): void
    {
        $ligne = $this->ligneTenant();

        $this->migration()->up();

        $this->assertDatabaseMissing('system_email_templates', ['id' => $ligne->id]);
        $this->assertDatabaseHas(self::ARCHIVE, [
            'id' => $ligne->id,
            'subject' => 'SUJET_TENANT_1694S',
            'organization_id' => $this->organization->id,
        ]);
    }

    /** L'archive date ce qu'elle garde : sans quoi ce n'est pas une preuve. */
    public function test_the_archive_records_when(): void
    {
        $this->ligneTenant();

        $this->migration()->up();

        $this->assertNotNull(DB::table(self::ARCHIVE)->value('archived_at'));
    }

    /** Les lignes GLOBALES ne sont jamais touchées — ce sont elles, la cible. */
    public function test_global_rows_are_left_alone(): void
    {
        $avant = SystemEmailTemplate::query()->whereNull('organization_id')->count();

        $this->assertGreaterThan(0, $avant, 'premisse : la normalisation a bien pose des lignes');

        $this->ligneTenant();
        $this->migration()->up();

        $this->assertSame($avant, SystemEmailTemplate::query()->whereNull('organization_id')->count());
        $this->assertSame(0, DB::table(self::ARCHIVE)->whereNull('organization_id')->count());
    }

    /** Le retour remet tout en place, et GARDE l'archive. */
    public function test_the_rollback_restores_and_keeps_the_archive(): void
    {
        $ligne = $this->ligneTenant();
        $migration = $this->migration();

        $migration->up();
        $migration->down();

        $this->assertDatabaseHas('system_email_templates', [
            'id' => $ligne->id,
            'subject' => 'SUJET_TENANT_1694S',
        ]);
        $this->assertDatabaseHas(self::ARCHIVE, ['id' => $ligne->id]);
    }

    /**
     * Et le retour n'écrase pas une ligne recréée à la main.
     *
     * Un retour qui effacerait une édition humaine serait pire que pas de
     * retour du tout.
     */
    public function test_the_rollback_does_not_overwrite_a_hand_made_row(): void
    {
        $ligne = $this->ligneTenant();
        $migration = $this->migration();

        $migration->up();

        DB::table('system_email_templates')->insert([
            'id' => $ligne->id,
            'slug' => 'new_message',
            'locale' => 'fr',
            'organization_id' => $this->organization->id,
            'name' => 'Recree a la main',
            'subject' => 'RECREE_A_LA_MAIN_1694S',
            'content_html' => '<p>x</p>',
            'variables' => json_encode(['name']),
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration->down();

        $this->assertSame(
            'RECREE_A_LA_MAIN_1694S',
            DB::table('system_email_templates')->where('id', $ligne->id)->value('subject'),
        );
    }

    /** Rejouée, elle ne fait rien de plus. */
    public function test_it_is_idempotent(): void
    {
        $this->ligneTenant();
        $migration = $this->migration();

        $migration->up();
        $migration->up();

        $this->assertSame(1, DB::table(self::ARCHIVE)->count());
        $this->assertSame(0, SystemEmailTemplate::query()->whereNotNull('organization_id')->count());
    }

    /**
     * L'archive n'a PAS de contrainte étrangère sur `organization_id`.
     *
     * Une archive doit survivre à la suppression de l'Organization dont elle
     * garde la trace : une clé étrangère la ferait disparaître au moment même
     * où elle devient le seul témoin.
     */
    public function test_the_archive_survives_the_organization_it_remembers(): void
    {
        $this->ligneTenant();
        $this->migration()->up();

        DB::table('organizations')->where('id', $this->organization->id)->delete();

        $this->assertSame(1, DB::table(self::ARCHIVE)->count());
    }

    /**
     * Et l'archive porte le MEME type de clef que la table d'origine.
     *
     * Première version écrite en `uuid` : PostgreSQL l'a refusée — « invalid
     * input syntax for type uuid: "18" ». `system_email_templates.id` est un
     * **bigint** (ce modèle n'a pas `HasUuids`, contrairement à
     * `EmailTemplate` qui lui ressemble de nom). SQLite l'aurait avalée en
     * silence, et l'erreur ne serait apparue qu'en production.
     */
    public function test_the_archive_key_has_the_same_type_as_the_source(): void
    {
        $this->assertTrue(Schema::hasTable(self::ARCHIVE));

        $ligne = $this->ligneTenant();

        $this->assertIsInt($ligne->id + 0, 'la clef source est numerique');

        $this->migration()->up();

        $this->assertSame(
            (int) $ligne->id,
            (int) DB::table(self::ARCHIVE)->value('id'),
        );
    }
}
