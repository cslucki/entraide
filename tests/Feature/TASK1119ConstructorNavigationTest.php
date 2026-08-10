<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\Organization;
use App\Models\User;
use App\Services\Loops\LoopTypeCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Les deux ecrans du Constructeur fonctionnent **ensemble**.
 *
 * `/admin/loop-types` annonce un nombre de Boucles par type ; ce nombre devient
 * un lien vers `/admin/loops`, filtre sur le meme contexte. Le contrat central
 * est donc une **egalite** : la liste ouverte par le lien rend exactement le
 * compte annonce — alias legacy compris (`custom` se lit `general`), portee
 * comprise (globale -> type seul ; Organization -> Organization + type).
 *
 * Le vocabulaire du selecteur de portee change dans **ce contexte UI**
 * seulement : « Plateforme » y devient « Toutes les organisations », l'heritage
 * se dit « reglages communs », et l'etat vierge « Non modifie ». Le concept
 * architectural Platform, lui, ne bouge pas.
 */
class TASK1119ConstructorNavigationTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private Organization $orgA;

    private Organization $orgB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create(['is_active' => true, 'loops_enabled' => true]);
        $this->orgB = Organization::factory()->create(['is_active' => true, 'loops_enabled' => true]);

        $this->superAdmin = User::factory()->create(['is_admin' => true, 'organization_id' => $this->orgA->id]);

        app()->instance('current_organization', $this->orgA);
    }

    private function boucle(Organization $org, string $type): Loop
    {
        return Loop::factory()->create([
            'organization_id' => $org->id,
            'type' => $type,
            'created_by' => User::factory()->create(['organization_id' => $org->id])->id,
        ]);
    }

    // ── Lot A : pliage ──────────────────────────────────────────────────────

    public function test_each_type_is_a_foldable_block(): void
    {
        $reponse = $this->actingAs($this->superAdmin)
            ->get(route('admin.loop-types'))
            ->assertOk();

        // Un bloc `<details>` par type, en plus de celui du formulaire de
        // creation qui existait deja : la page entiere se replie.
        $types = count(app(\App\Support\Loops\LoopTypeRegistry::class)->all());

        $this->assertSame(
            $types + 1,
            substr_count($reponse->getContent(), '<details'),
            'Chaque type doit devenir un bloc pliable, sans toucher au formulaire de creation.',
        );
    }

    // ── Lot A : compteur cliquable ──────────────────────────────────────────

    public function test_the_loop_count_links_to_the_list_filtered_by_type(): void
    {
        $this->boucle($this->orgA, 'general');

        $this->actingAs($this->superAdmin)
            ->get(route('admin.loop-types'))
            ->assertOk()
            // Portee globale : le lien ne porte que le type.
            ->assertSee(route('admin.loops', ['type' => 'general']), false);
    }

    public function test_the_loop_count_link_keeps_the_organization_scope(): void
    {
        $this->boucle($this->orgA, 'general');

        $this->actingAs($this->superAdmin)
            ->get(route('admin.loop-types', ['scope' => $this->orgA->id]))
            ->assertOk()
            // Portee Organization : le lien porte l'Organization ET le type.
            // `&` s'ecrit `&amp;` dans l'attribut : on verifie le HTML reel.
            ->assertSee('organization_id='.$this->orgA->id.'&amp;type=general', false);
    }

    // ── Lot A : vocabulaire du scope ────────────────────────────────────────

    public function test_the_scope_selector_speaks_organizations_not_platform(): void
    {
        $this->withSession(['locale' => 'fr']);

        $this->actingAs($this->superAdmin)
            ->get(route('admin.loop-types'))
            ->assertOk()
            ->assertSee('Toutes les organisations')
            ->assertDontSee('Plateforme (tous les espaces)')
            // L'etat vierge se dit « Non modifie », plus « Reglages d'origine ».
            ->assertSee('Non modifié')
            ->assertDontSee('Réglages d’origine');
    }

    public function test_the_inheritance_badge_speaks_shared_settings(): void
    {
        $this->withSession(['locale' => 'fr']);

        $this->actingAs($this->superAdmin)
            ->get(route('admin.loop-types', ['scope' => $this->orgA->id]))
            ->assertOk()
            ->assertSee('Hérité des réglages communs')
            ->assertDontSee('Hérité de la Plateforme');
    }

    public function test_the_english_vocabulary_follows(): void
    {
        $this->withSession(['locale' => 'en']);

        $this->actingAs($this->superAdmin)
            ->get(route('admin.loop-types'))
            ->assertOk()
            ->assertSee('All organizations')
            ->assertSee('Unmodified')
            ->assertDontSee('Platform (all workspaces)');
    }

    // ── Le compte annonce est celui que le lien ouvre ───────────────────────

    public function test_the_count_folds_legacy_aliases_like_the_list_will(): void
    {
        // `custom` est un alias de `general` : le compteur le replie deja.
        $this->boucle($this->orgA, 'general');
        $this->boucle($this->orgA, 'custom');
        $this->boucle($this->orgB, 'general');

        $this->withSession(['locale' => 'fr']);

        $this->actingAs($this->superAdmin)
            ->get(route('admin.loop-types'))
            ->assertOk()
            ->assertSee('3 Boucles');

        $this->actingAs($this->superAdmin)
            ->get(route('admin.loop-types', ['scope' => $this->orgA->id]))
            ->assertOk()
            ->assertSee('2 Boucles');
    }

    /** Un type cree par une Organization garde un lien coherent chez elle. */
    public function test_a_created_type_gets_a_scoped_link_too(): void
    {
        $type = app(LoopTypeCreationService::class)->create(
            organization: $this->orgA,
            label: 'Parcours interne',
            description: null,
            basedOn: null,
            author: $this->superAdmin,
        );

        $this->actingAs($this->superAdmin)
            ->get(route('admin.loop-types', ['scope' => $this->orgA->id]))
            ->assertOk()
            ->assertSee('organization_id='.$this->orgA->id.'&amp;type='.$type->key, false);
    }

    // ── Lot B : le filtre Type, combinable avec l'Organization ──────────────

    public function test_the_list_filters_by_type(): void
    {
        $formation = $this->boucle($this->orgA, 'training');
        $generale = $this->boucle($this->orgA, 'general');

        $this->actingAs($this->superAdmin)
            ->get(route('admin.loops', ['type' => 'training']))
            ->assertOk()
            ->assertSee($formation->name)
            ->assertDontSee($generale->name);
    }

    /**
     * Le contrat central des deux ecrans : la liste ouverte par le lien rend
     * le compte annonce, alias legacy compris.
     */
    public function test_the_type_filter_folds_legacy_aliases(): void
    {
        $moderne = $this->boucle($this->orgA, 'general');
        $ancienne = $this->boucle($this->orgA, 'custom');

        $this->actingAs($this->superAdmin)
            ->get(route('admin.loops', ['type' => 'general']))
            ->assertOk()
            ->assertSee($moderne->name)
            ->assertSee($ancienne->name);
    }

    public function test_both_filters_combine(): void
    {
        $viseeA = $this->boucle($this->orgA, 'training');
        $autreTypeA = $this->boucle($this->orgA, 'general');
        $autreOrgB = $this->boucle($this->orgB, 'training');

        $this->actingAs($this->superAdmin)
            ->get(route('admin.loops', ['organization_id' => $this->orgA->id, 'type' => 'training']))
            ->assertOk()
            ->assertSee($viseeA->name)
            ->assertDontSee($autreTypeA->name)
            ->assertDontSee($autreOrgB->name);
    }

    public function test_a_forged_organization_id_is_a_404_not_a_500(): void
    {
        // Pas un UUID : PostgreSQL leverait 22P02 sur la colonne `uuid` si la
        // valeur atteignait la requete.
        $this->actingAs($this->superAdmin)
            ->get(route('admin.loops', ['organization_id' => 'forge-pas-un-uuid']))
            ->assertNotFound();

        // UUID bien forme mais inconnu : meme reponse que scope() sur l'ecran
        // des types.
        $this->actingAs($this->superAdmin)
            ->get(route('admin.loops', ['organization_id' => (string) \Illuminate\Support\Str::uuid()]))
            ->assertNotFound();
    }

    public function test_an_unknown_type_is_silently_dropped(): void
    {
        $boucle = $this->boucle($this->orgA, 'general');

        // Un type forge n'est pas un filtre : le selecteur dirait « Tous les
        // types » au-dessus d'une liste qui ne le serait pas.
        $this->actingAs($this->superAdmin)
            ->get(route('admin.loops', ['type' => 'inconnu-forge']))
            ->assertOk()
            ->assertSee($boucle->name);
    }

    public function test_an_organization_private_type_is_not_offered_elsewhere(): void
    {
        $type = app(LoopTypeCreationService::class)->create(
            organization: $this->orgA,
            label: 'Parcours interne',
            description: null,
            basedOn: null,
            author: $this->superAdmin,
        );

        $boucleB = $this->boucle($this->orgB, 'general');

        // Chez l'Organization voisine : le type prive de A n'est pas propose,
        // et le parametre forge est ignore — pas de liste incoherente.
        $this->actingAs($this->superAdmin)
            ->get(route('admin.loops', ['organization_id' => $this->orgB->id, 'type' => $type->key]))
            ->assertOk()
            ->assertDontSee('value="'.$type->key.'"', false)
            ->assertSee($boucleB->name);

        // Chez elle : propose.
        $this->actingAs($this->superAdmin)
            ->get(route('admin.loops', ['organization_id' => $this->orgA->id]))
            ->assertOk()
            ->assertSee('value="'.$type->key.'"', false);

        // « Toutes les organisations » : le SuperAdmin voit le parc entier,
        // types crees compris.
        $this->actingAs($this->superAdmin)
            ->get(route('admin.loops'))
            ->assertOk()
            ->assertSee('value="'.$type->key.'"', false);
    }

    public function test_a_created_type_filters_and_renders_in_the_list(): void
    {
        $type = app(LoopTypeCreationService::class)->create(
            organization: $this->orgA,
            label: 'Parcours interne',
            description: null,
            basedOn: null,
            author: $this->superAdmin,
        );

        $porteuse = $this->boucle($this->orgA, $type->key);
        $autre = $this->boucle($this->orgA, 'general');

        // La ligne rendait `__($definition['label_key'])` : un type cree n'en
        // a pas, la page entiere tombait en 500 des qu'une Boucle en portait
        // un. Le selecteur passe desormais par le registre.
        $this->actingAs($this->superAdmin)
            ->get(route('admin.loops', ['type' => $type->key]))
            ->assertOk()
            ->assertSee($porteuse->name)
            ->assertDontSee($autre->name)
            ->assertSee('Parcours interne');
    }

    // ── Lot C : deux compteurs, pas un dashboard ────────────────────────────

    public function test_the_global_counter_ignores_every_filter(): void
    {
        $this->boucle($this->orgA, 'training');
        $this->boucle($this->orgA, 'general');
        $this->boucle($this->orgB, 'general');

        // Filtres au plus serre : le total du parc reste 3, et le total de
        // l'Organization reste 2 — le filtre Type ne compte pas la page, il
        // ne change pas la signification des deux chiffres.
        $this->actingAs($this->superAdmin)
            ->get(route('admin.loops', ['organization_id' => $this->orgA->id, 'type' => 'training']))
            ->assertOk()
            ->assertViewHas('totalLoops', 3)
            ->assertViewHas('organizationLoops', 2);
    }

    public function test_the_org_counter_follows_the_filter_not_the_request_context(): void
    {
        $this->boucle($this->orgA, 'general');
        $this->boucle($this->orgB, 'general');
        $this->boucle($this->orgB, 'training');

        // `current_organization` est orgA (setUp) : choisir orgB dans le
        // filtre doit compter orgB, pas le contexte de la requete.
        $this->actingAs($this->superAdmin)
            ->get(route('admin.loops', ['organization_id' => $this->orgB->id]))
            ->assertOk()
            ->assertViewHas('organizationLoops', 2);
    }

    public function test_the_org_counter_hides_on_all_organizations(): void
    {
        $this->boucle($this->orgA, 'general');

        $this->withSession(['locale' => 'fr']);

        $this->actingAs($this->superAdmin)
            ->get(route('admin.loops'))
            ->assertOk()
            ->assertViewHas('organizationLoops', null)
            ->assertSee('Boucles au total')
            ->assertDontSee('Dans '.$this->orgA->name);
    }
}
