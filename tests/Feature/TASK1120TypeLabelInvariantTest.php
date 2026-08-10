<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\Organization;
use App\Models\User;
use App\Services\Loops\LoopTypeCreationService;
use App\Services\LoopTypeSettingsService;
use App\Support\Loops\LoopTypeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Un type de Boucle affiche ne depend jamais de `label_key`.
 *
 * Un type **cree** depuis l'administration porte un mot ecrit, pas une cle de
 * traduction. Toute vue qui lit `$definition['label_key']` en direct tombe donc
 * en `Undefined array key` — une **500** — des qu'un type cree entre dans son
 * champ. C'est arrive en production sur `/org/main/loops`.
 *
 * Cinquieme a septieme occurrence de la meme cause, apres TASK-1116, 1117, 1118
 * et 1119. D'ou les deux etages de cette classe : des **rendus reels** avec un
 * type cree present, et une **garde transverse** qui refuse qu'une huitieme
 * apparaisse.
 */
class TASK1120TypeLabelInvariantTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Les vues autorisees a lire `label_key` / `description_key` en direct.
     *
     * Toutes lisent le catalogue de **Cards**, qui vient de
     * `config/loop_cards.php` et porte **toujours** une cle de traduction. La
     * regle ne vaut que pour les **types**.
     *
     * **Cette liste ne doit jamais grandir.** Un fichier de plus signifie qu'un
     * type est a nouveau nomme sans passer par le registre.
     *
     * @var array<int, string>
     */
    private const LECTURES_CARTES_LEGITIMES = [
        'admin/loop-types/index.blade.php',
        'admin/loops/show.blade.php',
        'loops/partials/header-actions.blade.php',
    ];

    private Organization $orgA;

    private Organization $orgB;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create([
            'is_active' => true, 'loops_enabled' => true, 'loop_mode' => 'multi',
        ]);
        $this->orgB = Organization::factory()->create([
            'is_active' => true, 'loops_enabled' => true, 'loop_mode' => 'multi',
        ]);

        $this->superAdmin = User::factory()->create([
            'is_admin' => true, 'organization_id' => $this->orgA->id,
        ]);

        app()->instance('current_organization', $this->orgA);
    }

    private function types(): LoopTypeRegistry
    {
        return app(LoopTypeRegistry::class);
    }

    private function membre(Organization $org): User
    {
        return User::factory()->create(['organization_id' => $org->id]);
    }

    private function boucle(Organization $org, string $type, ?User $auteur = null): Loop
    {
        return Loop::factory()->create([
            'organization_id' => $org->id,
            'type' => $type,
            'created_by' => ($auteur ?? $this->membre($org))->id,
        ]);
    }

    /**
     * Une seconde Boucle, pour que la **liste** s'affiche.
     *
     * `LoopController::index()` redirige vers la Boucle elle-meme quand elle est
     * la seule accessible et qu'il n'y a pas d'archive : sans ce remplissage,
     * les tests recevraient une 302 et ne verraient jamais les onglets.
     */
    private function deuxiemeBoucle(Organization $org, User $auteur): Loop
    {
        return $this->boucle($org, 'general', $auteur);
    }

    /** Un type cree pour tout le monde — la forme exacte d'`entraide` en base. */
    private function typePlateforme(string $mot): string
    {
        return app(LoopTypeCreationService::class)->create(
            organization: null,
            label: $mot,
            description: 'Description ecrite, sans cle de traduction.',
            basedOn: null,
            author: $this->superAdmin,
        )->key;
    }

    /** Un type cree pour une seule Organization. */
    private function typeOrganization(Organization $org, string $mot): string
    {
        return app(LoopTypeCreationService::class)->create(
            organization: $org,
            label: $mot,
            description: 'Reserve a cette Organization.',
            basedOn: null,
            author: $this->superAdmin,
        )->key;
    }

    // ── L'invariante, au niveau du registre ─────────────────────────────────

    public function test_a_created_type_has_no_translation_key_at_all(): void
    {
        $cle = $this->typePlateforme('Entraide');

        // La premisse du bug : la definition **n'a pas** de `label_key`. Si un
        // jour elle en gagnait une, ce test le dirait plutot que de laisser
        // croire que les autres tests couvrent encore quelque chose.
        $definition = $this->types()->baseDefinition($cle);

        $this->assertIsArray($definition);
        $this->assertArrayNotHasKey('label_key', $definition);
        $this->assertArrayNotHasKey('description_key', $definition);

        // Et pourtant le registre sait le nommer.
        $this->assertSame('Entraide', $this->types()->label($cle));
        $this->assertNotSame('', $this->types()->description($cle));
    }

    // ── Les trois ecrans, avec un type cree present ─────────────────────────

    public function test_the_member_loops_index_renders_with_a_created_type(): void
    {
        $cle = $this->typePlateforme('Entraide');
        $membre = $this->membre($this->orgA);
        $boucle = $this->boucle($this->orgA, $cle, $membre);
        $this->deuxiemeBoucle($this->orgA, $membre);

        // Reproduction exacte du 500 : une Boucle porte le type cree, la vue
        // lui garde un onglet, et l'onglet doit savoir se nommer.
        $this->actingAs($membre)
            ->get(route('organization.loops.index', ['organization' => $this->orgA->slug]))
            ->assertOk()
            ->assertSee('Entraide')
            ->assertSee($boucle->name);
    }

    public function test_the_org_admin_loop_edit_renders_with_a_created_type(): void
    {
        $cle = $this->typePlateforme('Entraide');
        $boucle = $this->boucle($this->orgA, $cle);

        $this->actingAs($this->superAdmin)
            ->get(route('organization.admin.loops.edit', [
                'organization' => $this->orgA->slug, 'loop' => $boucle->id,
            ]))
            ->assertOk()
            ->assertSee('Entraide')
            ->assertSee('Description ecrite, sans cle de traduction.');
    }

    public function test_the_loop_configurator_renders_with_a_created_type(): void
    {
        $cle = $this->typePlateforme('Entraide');
        $boucle = $this->boucle($this->orgA, $cle);

        $this->actingAs($this->superAdmin)
            ->get(route('admin.loops.configure', $boucle))
            ->assertOk()
            ->assertSee('Entraide');
    }

    /**
     * **La seconde porte du configurateur.**
     *
     * `admin/loops/configure.blade.php` est rendue par deux controleurs — son
     * propre en-tete le dit — et n'en tester qu'un laisse l'autre libre de
     * casser. C'est arrive en ecrivant cette tache : le registre passe par le
     * controleur plateforme rendait `Undefined variable $typeRegistry` sur le
     * chemin Organization. Le registre se resout desormais dans la vue, et ce
     * test garde la porte qui manquait.
     */
    public function test_the_organization_scoped_configurator_renders_too(): void
    {
        $cle = $this->typePlateforme('Entraide');
        $adminOrg = User::factory()->create([
            'organization_id' => $this->orgA->id, 'is_admin' => false,
        ]);
        $this->orgA->forceFill(['admin_id' => $adminOrg->id])->save();

        $boucle = $this->boucle($this->orgA, $cle);

        $this->actingAs($adminOrg)
            ->get(route('organization.admin.loops.configure', [
                'organization' => $this->orgA->slug, 'loop' => $boucle->id,
            ]))
            ->assertOk()
            ->assertSee('Entraide');
    }

    // ── Type natif, non personnalise ────────────────────────────────────────

    public function test_a_native_untouched_type_keeps_its_configured_word(): void
    {
        $membre = $this->membre($this->orgA);
        $this->boucle($this->orgA, 'general', $membre);
        $this->deuxiemeBoucle($this->orgA, $membre);

        $this->actingAs($membre)
            ->get(route('organization.loops.index', ['organization' => $this->orgA->slug]))
            ->assertOk()
            ->assertSee(__('loops.types.general.label'));
    }

    // ── Type natif renomme pour une Organization ────────────────────────────

    public function test_a_native_type_renamed_for_an_organization_shows_that_word(): void
    {
        app(LoopTypeSettingsService::class)
            ->rename('training', 'Parcours interne', null, $this->orgA);

        $membre = $this->membre($this->orgA);
        $this->boucle($this->orgA, 'training', $membre);
        $this->deuxiemeBoucle($this->orgA, $membre);

        $this->actingAs($membre)
            ->get(route('organization.loops.index', ['organization' => $this->orgA->slug]))
            ->assertOk()
            ->assertSee('Parcours interne')
            ->assertDontSee(__('loops.types.training.label'));
    }

    public function test_the_rename_does_not_leak_to_another_organization(): void
    {
        app(LoopTypeSettingsService::class)
            ->rename('training', 'Parcours interne', null, $this->orgA);

        $membreB = $this->membre($this->orgB);
        $this->boucle($this->orgB, 'training', $membreB);
        $this->deuxiemeBoucle($this->orgB, $membreB);

        // Chez la voisine, le mot commun — le renommage de A ne fuit pas.
        $this->actingAs($membreB)
            ->get(route('organization.loops.index', ['organization' => $this->orgB->slug]))
            ->assertOk()
            ->assertSee(__('loops.types.training.label'))
            ->assertDontSee('Parcours interne');
    }

    // ── Type cree pour une seule Organization ───────────────────────────────

    public function test_a_type_created_for_one_organization_is_named_at_home(): void
    {
        $cle = $this->typeOrganization($this->orgA, 'Cercle prive');
        $membre = $this->membre($this->orgA);
        $boucle = $this->boucle($this->orgA, $cle, $membre);
        $this->deuxiemeBoucle($this->orgA, $membre);

        // Chez elle : l'onglet existe et porte le mot. Sans la portee, `all()`
        // ne connaissait pas ce type et la Boucle etait introuvable ailleurs
        // que dans « Toutes ».
        $this->actingAs($membre)
            ->get(route('organization.loops.index', ['organization' => $this->orgA->slug]))
            ->assertOk()
            ->assertSee('Cercle prive')
            ->assertSee($boucle->name);
    }

    public function test_a_type_created_for_one_organization_does_not_leak_to_another(): void
    {
        $this->typeOrganization($this->orgA, 'Cercle prive');

        $membreB = $this->membre($this->orgB);
        $this->boucle($this->orgB, 'general', $membreB);
        $this->deuxiemeBoucle($this->orgB, $membreB);

        $this->actingAs($membreB)
            ->get(route('organization.loops.index', ['organization' => $this->orgB->slug]))
            ->assertOk()
            ->assertDontSee('Cercle prive');
    }

    // ── Organization non par defaut ─────────────────────────────────────────

    public function test_a_non_default_organization_is_served_its_own_scope(): void
    {
        Organization::query()->update(['is_default' => false]);
        $this->orgB->forceFill(['is_default' => false])->save();

        $cle = $this->typeOrganization($this->orgB, 'Atelier B');
        $membreB = $this->membre($this->orgB);
        $boucle = $this->boucle($this->orgB, $cle, $membreB);
        $this->deuxiemeBoucle($this->orgB, $membreB);

        $this->actingAs($membreB)
            ->get(route('organization.loops.index', ['organization' => $this->orgB->slug]))
            ->assertOk()
            ->assertSee('Atelier B')
            ->assertSee($boucle->name);
    }

    // ── Cout : aucun N+1 introduit ──────────────────────────────────────────

    /**
     * Nommer les types ne coute rien de plus quand il y a plus de types.
     *
     * **La mesure porte sur les types, pas sur les Boucles.** C'est le nommage
     * qui a change ici : un onglet par type, chacun demandant son mot au
     * registre. Un N+1 introduit par ce geste se verrait en ajoutant des
     * **types** ; faire varier le nombre de Boucles mesurerait le cout par
     * ligne de la page, qui est anterieur et hors sujet (voir la note du TASK
     * file).
     */
    public function test_naming_more_types_costs_no_extra_query(): void
    {
        $membre = $this->membre($this->orgA);
        $this->boucle($this->orgA, 'general', $membre);
        $this->deuxiemeBoucle($this->orgA, $membre);

        $url = route('organization.loops.index', ['organization' => $this->orgA->slug]);

        $this->typePlateforme('Entraide');
        $avecUnTypeCree = $this->requetesDeCatalogue($url, $membre);

        foreach (['Alpha', 'Beta', 'Gamma', 'Delta', 'Epsilon', 'Zeta'] as $mot) {
            $this->typePlateforme($mot);
        }

        $avecSeptTypesCrees = $this->requetesDeCatalogue($url, $membre);

        // Le registre et le service de reglages lisent leur catalogue une fois
        // chacun, memoise pour la duree de la requete HTTP. Sept fois plus de
        // types a nommer doit couter exactement la meme chose.
        $this->assertSame(
            $avecUnTypeCree,
            $avecSeptTypesCrees,
            "Passer de 1 a 7 types crees a change le nombre de lectures du catalogue "
            ."({$avecUnTypeCree} -> {$avecSeptTypesCrees}) : le nommage des types fait un N+1.",
        );

        // Et ce cout est petit, pas seulement egal a lui-meme : la verification
        // d'existence de `custom_loop_types`, sa lecture, celle de
        // `loop_type_settings`. Trois, quel que soit le nombre de types.
        $this->assertSame(3, $avecSeptTypesCrees);
    }

    /**
     * Les lectures du **catalogue de types** faites par un rendu.
     *
     * On ne compte pas toutes les requetes de la page : elle en fait d'autres
     * qui varient pour des raisons etrangeres a cette tache — un chargement
     * paresseux d'`Organization` dans la navigation, notamment. Compter le
     * total ferait echouer ce test sur du bruit, et le ferait passer pour un
     * N+1 qu'il n'est pas. Ici on mesure **le geste modifie**, et lui seul.
     *
     * Les singletons sont oublies avant chaque mesure : sans cela la seconde
     * lecture profiterait du memo de la premiere, le container etant le meme
     * d'un appel HTTP a l'autre dans un test.
     */
    private function requetesDeCatalogue(string $url, User $membre): int
    {
        $this->app->forgetInstance(LoopTypeRegistry::class);
        $this->app->forgetInstance(LoopTypeSettingsService::class);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($membre)->get($url)->assertOk();

        $journal = DB::getQueryLog();

        DB::disableQueryLog();

        return count(array_filter(
            $journal,
            fn ($q) => str_contains($q['query'], 'custom_loop_types')
                || str_contains($q['query'], 'loop_type_settings'),
        ));
    }

    // ── La garde transverse ─────────────────────────────────────────────────

    /**
     * Aucune vue ne nomme un **type** en lisant sa cle de traduction.
     *
     * Quatre taches de suite ont corrige la meme ligne ailleurs. Cette garde
     * remplace la vigilance : elle echoue si un fichier de plus se met a lire
     * `label_key` ou `description_key`, et la liste d'exceptions ne contient
     * que des lectures du catalogue de **Cards**, qui en portent toujours une.
     */
    public function test_no_view_names_a_loop_type_through_its_translation_key(): void
    {
        $racine = resource_path('views');
        $fautifs = [];

        $fichiers = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($racine, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($fichiers as $fichier) {
            if (! str_ends_with($fichier->getFilename(), '.blade.php')) {
                continue;
            }

            $contenu = file_get_contents($fichier->getPathname());

            // Les commentaires Blade parlent de la regle sans la violer :
            // `chat-tools.blade.php` documente justement le bon reflexe.
            $contenu = preg_replace('/\{\{--.*?--\}\}/s', '', $contenu);

            if (! preg_match("/\\[['\"](?:label_key|description_key)['\"]\\]/", (string) $contenu)) {
                continue;
            }

            $fautifs[] = str_replace($racine.DIRECTORY_SEPARATOR, '', $fichier->getPathname());
        }

        sort($fautifs);
        $attendus = self::LECTURES_CARTES_LEGITIMES;
        sort($attendus);

        $this->assertSame(
            $attendus,
            array_map(fn ($c) => str_replace(DIRECTORY_SEPARATOR, '/', $c), $fautifs),
            "Une vue nomme un type de Boucle par sa cle de traduction. Un type cree n'en a pas : "
            ."passer par LoopTypeRegistry::label(\$key, \$organization) ou ::description(). "
            .'Seules les lectures du catalogue de Cards sont admises dans cette liste, et elle ne grandit pas.',
        );
    }
}
