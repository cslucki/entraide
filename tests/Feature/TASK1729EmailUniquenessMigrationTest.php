<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-1729 (A4) — la bascule d'unicite, ses gardes et son refus.
 *
 * ## Pourquoi ces tests refabriquent l'etat anterieur
 *
 * `RefreshDatabase` a deja applique toutes les migrations : la table est donc
 * DEJA dans sa forme cible. Rejouer `up()` ne mesurerait que lui-meme.
 *
 * Ces tests reviennent donc a la forme ANTERIEURE — `UNIQUE(email)` global —,
 * y fabriquent la situation a mesurer, puis exercent la migration REELLE,
 * celle du fichier. C'est la seule facon de prouver ce que MASTER a exige : la
 * conservation integrale des comptes, le refus nomme sur doublon, et un
 * `down()` qui refuse plutot que de perdre.
 */
class TASK1729EmailUniquenessMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_10_30_120000_unique_email_per_organization.php';

    private function migration(): object
    {
        return require base_path(self::MIGRATION);
    }

    /** Revient a la forme anterieure : unique GLOBAL, pas de composite. */
    private function revenirALaFormeAnterieure(): void
    {
        $connexion = Schema::getConnection();

        foreach (['users_organization_email_unique', 'users_email_orphan_unique', 'users_email_lower_index'] as $index) {
            $connexion->statement('DROP INDEX '.($connexion->getDriverName() === 'pgsql' ? '' : 'IF EXISTS ').$index);
        }

        Schema::table('users', fn (Blueprint $table) => $table->unique('email', 'users_email_unique'));
    }

    /** Ecrit un compte SANS passer par le modele, pour franchir les gardes. */
    private function ligneUtilisateur(?string $organizationId, string $email): string
    {
        $id = (string) Str::uuid();

        DB::table('users')->insert([
            'id' => $id,
            'organization_id' => $organizationId,
            'name' => 'Compte '.substr($id, 0, 8),
            'email' => $email,
            'password' => Hash::make('password'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    public function test_la_bascule_conserve_tous_les_comptes(): void
    {
        $orgA = Organization::factory()->create(['slug' => 'mig-a', 'is_active' => true]);
        $orgB = Organization::factory()->create(['slug' => 'mig-b', 'is_active' => true]);

        $this->revenirALaFormeAnterieure();

        $a = $this->ligneUtilisateur($orgA->id, 'garde-a@exemple.test');
        $b = $this->ligneUtilisateur($orgB->id, 'garde-b@exemple.test');
        $orphelin = $this->ligneUtilisateur(null, 'garde-orphelin@exemple.test');
        // Une adresse en MAJUSCULES, heritee de `/admin/users` sans `lowercase`.
        $majuscules = $this->ligneUtilisateur($orgA->id, 'Garde-Majuscules@Exemple.test');

        $avant = DB::table('users')->count();

        $this->migration()->up();

        // AUCUNE ligne perdue, AUCUNE adresse reecrite.
        $this->assertSame($avant, DB::table('users')->count());
        $this->assertSame('garde-a@exemple.test', DB::table('users')->where('id', $a)->value('email'));
        $this->assertSame('garde-b@exemple.test', DB::table('users')->where('id', $b)->value('email'));
        $this->assertNull(DB::table('users')->where('id', $orphelin)->value('organization_id'));
        $this->assertSame(
            'Garde-Majuscules@Exemple.test',
            DB::table('users')->where('id', $majuscules)->value('email'),
            "la casse d'origine est CONSERVEE : on change ce qui est un doublon, pas l'adresse de quelqu'un"
        );

        // Et les trois index sont la.
        $noms = collect(Schema::getIndexes('users'))->pluck('name');
        foreach (['users_organization_email_unique', 'users_email_orphan_unique', 'users_email_lower_index'] as $index) {
            $this->assertTrue($noms->contains($index), "index manquant : {$index}");
        }
        $this->assertFalse($noms->contains('users_email_unique'));
    }

    public function test_la_bascule_REFUSE_et_NOMME_un_doublon_de_casse_intra_organization(): void
    {
        // Le cas que l'unique global NE garantissait PAS : il est sensible a la
        // casse, donc `Alice@x` et `alice@x` pouvaient coexister. Apres A4 elles
        // seraient un doublon — la migration doit le dire AVANT d'agir.
        $org = Organization::factory()->create(['slug' => 'mig-casse', 'is_active' => true]);

        $this->revenirALaFormeAnterieure();

        $this->ligneUtilisateur($org->id, 'doublon@exemple.test');
        $this->ligneUtilisateur($org->id, 'DOUBLON@Exemple.TEST');

        try {
            $this->migration()->up();
            $this->fail('la bascule devait REFUSER');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('doublon@exemple.test', $e->getMessage(),
                "le refus doit NOMMER l'adresse en cause");
            $this->assertStringContainsString("Rien n'a ete modifie", $e->getMessage());
        }

        // Et rien n'a bouge : l'unique global est toujours la, les nouveaux non.
        $noms = collect(Schema::getIndexes('users'))->pluck('name');
        $this->assertTrue($noms->contains('users_email_unique'));
        $this->assertFalse($noms->contains('users_organization_email_unique'));
    }

    public function test_la_bascule_REFUSE_un_doublon_entre_ORPHELINS(): void
    {
        $this->revenirALaFormeAnterieure();

        $this->ligneUtilisateur(null, 'orph-dup@exemple.test');
        $this->ligneUtilisateur(null, 'ORPH-DUP@exemple.test');

        $this->expectException(\RuntimeException::class);

        try {
            $this->migration()->up();
        } finally {
            $this->assertTrue(
                collect(Schema::getIndexes('users'))->pluck('name')->contains('users_email_unique'),
                'la table reste protegee par la forme anterieure'
            );
        }
    }

    public function test_down_restaure_l_unicite_globale_sans_perte(): void
    {
        $org = Organization::factory()->create(['slug' => 'mig-down', 'is_active' => true]);
        $this->ligneUtilisateur($org->id, 'down-a@exemple.test');
        $this->ligneUtilisateur(null, 'down-b@exemple.test');

        $avant = DB::table('users')->count();

        $this->migration()->down();

        $noms = collect(Schema::getIndexes('users'))->pluck('name');
        $this->assertTrue($noms->contains('users_email_unique'));
        $this->assertFalse($noms->contains('users_organization_email_unique'));
        $this->assertFalse($noms->contains('users_email_orphan_unique'));
        $this->assertSame($avant, DB::table('users')->count());
    }

    public function test_down_REFUSE_des_qu_un_compte_FRERE_existe(): void
    {
        // LE point de non-retour, mesure. Des qu'une adresse est portee par deux
        // comptes, la forme anterieure ne peut PAS les contenir : redescendre en
        // silence en perdrait un, et on ne saurait pas lequel.
        $orgA = Organization::factory()->create(['slug' => 'mig-nr-a', 'is_active' => true]);
        $orgB = Organization::factory()->create(['slug' => 'mig-nr-b', 'is_active' => true]);

        // Creables NORMALEMENT depuis A4 — c'est tout l'objet de la TASK.
        User::factory()->create(['organization_id' => $orgA->id, 'email' => 'frere@exemple.test']);
        User::factory()->create(['organization_id' => $orgB->id, 'email' => 'frere@exemple.test']);

        try {
            $this->migration()->down();
            $this->fail('down() devait REFUSER');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('frere@exemple.test', $e->getMessage());
            $this->assertStringContainsString("Rien n'a ete modifie", $e->getMessage());
        }

        // La forme A4 est intacte : le refus n'a rien casse en chemin.
        $noms = collect(Schema::getIndexes('users'))->pluck('name');
        $this->assertTrue($noms->contains('users_organization_email_unique'));
        $this->assertFalse($noms->contains('users_email_unique'));
        $this->assertSame(2, User::query()->whereRaw('LOWER(email) = ?', ['frere@exemple.test'])->count());
    }
}
