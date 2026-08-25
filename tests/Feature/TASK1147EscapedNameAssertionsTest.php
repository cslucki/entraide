<?php

namespace Tests\Feature;

use App\Models\Dossier;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Un nom affiche est ECHAPPE : une assertion doit chercher cette forme-la.
 *
 * ## Le flake
 *
 * `TASK1130RealSubfoldersTest::test_the_share_panel_of_a_loop_child_lists_the_loop_members`
 * a rougi trois fois le gate PostgreSQL en suite complete — apres TASK-1211,
 * sur TASK-1144, puis sur TASK-1146 — et redevenait vert a la relance du meme
 * HEAD, sans qu'aucun diff n'en soit la cause.
 *
 * Il n'y avait aucune dependance a l'ordre, aucune pollution d'etat : le test
 * comparait le nom BRUT d'un utilisateur au HTML rendu. Or Blade echappe, et
 * `fake()->lastName()` en `en_US` produit un nom apostrophe — O'Reilly,
 * O'Hara, D'Amore — environ **1,55 %** du temps. Le test asserait deux noms,
 * soit une chance sur trente environ de tomber a cote a chaque execution.
 *
 * ## Ce que ces tests gardent
 *
 * 1. le rendu echappe bien un nom apostrophe — la forme brute n'apparait pas ;
 * 2. l'assertion correcte porte sur la forme echappee ;
 * 3. le defaut ne revient pas : aucune assertion du depot ne compare un nom
 *    brut a du HTML.
 */
class TASK1147EscapedNameAssertionsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $owner;

    private Dossier $enfant;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create(['is_active' => true, 'is_default' => true]);
        $this->org = Organization::factory()->create(['is_active' => true, 'loops_enabled' => true]);

        // Le nom exact que Faker tire 1,55 % du temps, ici fige.
        $this->owner = User::factory()->create([
            'organization_id' => $this->org->id, 'first_name' => 'Maureen', 'name' => "O'Connell",
        ]);

        $loop = Loop::factory()->create([
            'organization_id' => $this->org->id, 'status' => 'active', 'type' => 'general',
            'created_by' => $this->owner->id,
        ]);
        LoopMember::factory()->owner()->create([
            'loop_id' => $loop->id, 'user_id' => $this->owner->id, 'joined_at' => now(),
        ]);

        $racine = Dossier::create([
            'organization_id' => $this->org->id, 'owner_id' => null, 'name' => 'Documents',
            'visibility' => Dossier::VISIBILITY_LOOP, 'loop_id' => $loop->id,
        ]);

        app()->instance('current_organization', $this->org);

        $this->enfant = Dossier::create([
            'organization_id' => $this->org->id, 'parent_id' => $racine->getKey(), 'name' => 'Communication',
        ]);
    }

    private function html(): string
    {
        return $this->actingAs($this->owner)
            ->get(route('organization.dossiers.show', [
                'organization' => $this->org->slug,
                'dossier' => $this->enfant->getKey(),
            ]))
            ->assertOk()
            ->getContent();
    }

    public function test_an_apostrophe_name_is_escaped_in_the_rendered_page(): void
    {
        $html = $this->html();
        $nom = $this->owner->publicDisplayName();

        $this->assertSame("Maureen O'Connell", $nom);

        // Le coeur du flake, rendu explicite : la forme brute n'est PAS dans le
        // HTML, la forme echappee y est.
        $this->assertStringNotContainsString($nom, $html);
        $this->assertStringContainsString(e($nom), $html);
        $this->assertStringContainsString('O&#039;Connell', $html);
    }

    public function test_the_member_is_listed_under_its_escaped_name(): void
    {
        // L'assertion que le test d'origine voulait faire, ecrite correctement.
        $this->assertStringContainsString(e($this->owner->publicDisplayName()), $this->html());
    }

    /**
     * `assertSee()` echappe par defaut : c'est la voie sure.
     *
     * Le piege est `assertSee($nom, false)` et `assertStringContainsString()`,
     * qui comparent la chaine telle quelle.
     */
    public function test_assert_see_escapes_by_default(): void
    {
        $this->actingAs($this->owner)
            ->get(route('organization.dossiers.show', [
                'organization' => $this->org->slug,
                'dossier' => $this->enfant->getKey(),
            ]))
            ->assertOk()
            ->assertSee($this->owner->publicDisplayName());
    }

    /**
     * La garde qui empeche le retour du defaut.
     *
     * Elle lit les fichiers de test : toute assertion portant un nom
     * d'utilisateur contre du HTML doit passer par `e()`, ou par un
     * `assertSee()` sans second argument. Le defaut n'etait pas dans le
     * produit, il etait dans la maniere de l'interroger — c'est donc la
     * qu'on le garde.
     */
    public function test_no_test_compares_a_raw_display_name_against_html(): void
    {
        $fautives = [];

        $iterateur = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(base_path('tests')),
        );

        foreach ($iterateur as $fichier) {
            if (! $fichier->isFile() || ! str_ends_with($fichier->getFilename(), '.php')) {
                continue;
            }

            // Ce fichier-ci cite le motif fautif dans sa documentation.
            if ($fichier->getFilename() === 'TASK1147EscapedNameAssertionsTest.php') {
                continue;
            }

            foreach (file($fichier->getPathname()) as $numero => $ligne) {
                // Un nom compare a du HTML — et non a une chaine quelconque.
                // `ChatLoopAiServiceTest` compare un nom au contexte d'un
                // prompt IA : ce n'est pas du HTML, rien n'y est echappe, et
                // la forme brute y est la bonne. C'est pourquoi la garde
                // regarde la MEULE et pas seulement l'aiguille.
                $compareUnNom = preg_match(
                    '/assert(StringContainsString|StringNotContainsString|Contains|NotContains)\s*\(\s*\$[^,]*(publicDisplayName|fullName)\s*\(\s*\)\s*,\s*[^)]*(html|Html|getContent\(\)|cellules)/',
                    $ligne,
                );
                $voitSansEchappement = preg_match(
                    '/assertSee\s*\(\s*\$[^,]*(publicDisplayName|fullName)\s*\(\s*\)\s*,\s*false/',
                    $ligne,
                );

                if ($compareUnNom || $voitSansEchappement) {
                    $fautives[] = $fichier->getFilename().':'.($numero + 1);
                }
            }
        }

        $this->assertSame([], $fautives,
            "Ces assertions comparent un nom BRUT a du HTML echappe : elles echoueront au hasard\n"
            ."des que Faker tirera un nom apostrophe (1,55 % des noms). Passer par e().\n"
            .implode("\n", $fautives));
    }
}
