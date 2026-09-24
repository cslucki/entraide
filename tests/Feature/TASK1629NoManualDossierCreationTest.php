<?php

namespace Tests\Feature;

use App\Models\Dossier;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use App\Services\Dossiers\PersonalDocumentsRoot;
use App\Services\Loops\LoopRootDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * BouclePro n'est pas un Drive : l'utilisateur ne fabrique plus de Dossiers
 * (TASK-1629).
 *
 * La decision produit tient en deux moities qui doivent rester vraies
 * ENSEMBLE, et c'est pour ca qu'elles sont gardees dans le meme fichier :
 *
 *   · aucune surface — menu « + Nouveau », Drive d'un dossier, carte Dossier
 *     de l'editeur d'article — n'offre plus de creer un dossier, et aucune
 *     route ne l'accepte, meme en POST direct ;
 *   · les racines que le PRODUIT provisionne — celle d'une Boucle, « Mes
 *     documents » — continuent de naitre exactement comme avant, et les
 *     arborescences legacy deja en base restent lisibles et navigables.
 *
 * Une interdiction qui casserait le provisioning serait un echec, pas un
 * succes : la seconde moitie n'est pas decorative.
 *
 * Les assertions d'absence portent sur le LIBELLE REEL (`__('...')`), pas sur
 * un nom de cle. Les cles de traduction sont donc volontairement conservees :
 * un `assertDontSee` sur une cle supprimee comparerait le HTML a la chaine
 * « dossiers.drive_new_folder », qui n'y est jamais — un vert de complaisance.
 */
class TASK1629NoManualDossierCreationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $owner;

    private User $membre;

    private Loop $loop;

    private Dossier $racineBoucle;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create(['is_active' => true, 'is_default' => true]);
        $this->org = Organization::factory()->create([
            'is_active' => true,
            'loops_enabled' => true,
            'slug' => 'org-1629',
        ]);

        $this->owner = User::factory()->create(['organization_id' => $this->org->id]);
        $this->membre = User::factory()->create(['organization_id' => $this->org->id]);

        $this->loop = Loop::factory()->create([
            'organization_id' => $this->org->id,
            'status' => 'active',
            'type' => 'general',
            'created_by' => $this->owner->id,
        ]);
        LoopMember::factory()->owner()->create([
            'loop_id' => $this->loop->id, 'user_id' => $this->owner->id, 'joined_at' => now(),
        ]);
        LoopMember::factory()->create([
            'loop_id' => $this->loop->id, 'user_id' => $this->membre->id, 'role' => 'member', 'joined_at' => now(),
        ]);

        $this->racineBoucle = Dossier::create([
            'organization_id' => $this->org->id,
            'owner_id' => null,
            'loop_id' => $this->loop->id,
            'name' => 'Documents de la Boucle',
            'visibility' => Dossier::VISIBILITY_LOOP,
        ]);

        app()->instance('current_organization', $this->org);
    }

    // ── A. Le menu « + Nouveau » du module Dossiers ─────────────────────────

    public function test_the_new_menu_no_longer_offers_to_create_a_folder(): void
    {
        $html = $this->actingAs($this->owner)
            ->get(route('organization.dossiers.index', ['organization' => $this->org->slug]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(__('dossiers.drive_new_folder'), $html);
    }

    public function test_the_new_menu_keeps_every_other_action(): void
    {
        $html = $this->actingAs($this->owner)
            ->get(route('organization.dossiers.index', ['organization' => $this->org->slug]))
            ->assertOk()
            ->getContent();

        // La section « Ajouter » est intacte...
        $this->assertStringContainsString(__('dossiers.fab_import_files'), $html);
        $this->assertStringContainsString(__('dossiers.fab_attach_article'), $html);
        // ...et la section « Creer » existe toujours, avec ses deux entrees :
        // retirer le dossier ne devait pas emporter la rubrique.
        $this->assertStringContainsString(__('dossiers.fab_section_create'), $html);
        $this->assertStringContainsString(__('dossiers.fab_new_article'), $html);
        $this->assertStringContainsString(__('dossiers.fab_markdown_note'), $html);
    }

    public function test_the_drive_of_a_loop_dossier_offers_no_folder_creation(): void
    {
        $html = $this->actingAs($this->owner)
            ->get(route('organization.dossiers.show', [
                'organization' => $this->org->slug, 'dossier' => $this->racineBoucle->getKey(),
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(__('dossiers.drive_new_folder'), $html);
        // La modale postait sur `dossiers.store` : l'URL ne doit plus etre
        // fabricable dans la page, meme cachee derriere un `x-if`.
        $this->assertStringNotContainsString('open-new-folder', $html);
        // L'action voisine du meme Drive, elle, est toujours la.
        $this->assertStringContainsString(__('dossiers.fab_import_files'), $html);
    }

    // ── B. L'autre surface : la carte Dossier de l'editeur d'article ────────

    public function test_the_article_editor_no_longer_offers_a_quick_folder_creation(): void
    {
        $post = $this->article('Un brouillon a classer');

        $html = $this->actingAs($this->owner)
            ->get(route('organization.blog.edit', [
                'organization' => $this->org->slug, 'post' => $post->slug,
            ]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(__('blog.dossier_quick_create'), $html);
        $this->assertStringNotContainsString(__('blog.dossier_quick_create_placeholder'), $html);
        // Classer dans un Dossier existant reste la raison d'etre du panneau.
        $this->assertStringContainsString(__('blog.dossier_classify'), $html);
    }

    // ── C. Le backend : aucune route n'accepte plus la creation ────────────

    public function test_no_manual_creation_route_exists_anymore(): void
    {
        $this->assertFalse(Route::has('organization.dossiers.create'));
        $this->assertFalse(Route::has('organization.dossiers.store'));
        $this->assertFalse(Route::has('blog.dossiers.store'));
        $this->assertFalse(Route::has('organization.blog.dossiers.store'));
    }

    /**
     * Le POST direct, par les trois profils que le mandat nomme : un membre
     * ordinaire, le proprietaire de la Boucle, un administrateur.
     *
     * 405, et l'assertion est EXACTE — pas un « moins que 500 » qui resterait
     * vert si la route revenait et repondait 302 apres avoir ecrit la ligne.
     * 405 plutot que 404 parce que l'URL `/org/{org}/dossiers` existe
     * toujours : elle LISTE. C'est la methode POST qui n'a plus de
     * destinataire, et c'est exactement ce que ce code dit.
     */
    public function test_a_direct_post_creates_nothing_for_any_profile(): void
    {
        $admin = User::factory()->create([
            'organization_id' => $this->org->id,
            'is_admin' => true,
        ]);

        $avant = Dossier::count();

        foreach ([$this->membre, $this->owner, $admin] as $acteur) {
            $reponse = $this->actingAs($acteur)->post(
                '/org/'.$this->org->slug.'/dossiers',
                ['name' => 'Dossier force '.$acteur->id],
            );

            $reponse->assertStatus(405);
        }

        $this->assertSame($avant, Dossier::count());
        $this->assertDatabaseMissing('dossiers', ['name' => 'Dossier force '.$this->owner->id]);
    }

    public function test_a_direct_post_with_a_parent_creates_no_subfolder(): void
    {
        $this->actingAs($this->owner)->post(
            '/org/'.$this->org->slug.'/dossiers',
            ['name' => 'Sous-dossier force', 'parent_id' => $this->racineBoucle->getKey()],
        )->assertStatus(405);

        $this->assertDatabaseMissing('dossiers', ['parent_id' => $this->racineBoucle->getKey()]);
    }

    public function test_the_quick_create_endpoints_are_gone(): void
    {
        // Meme raison qu'au-dessus : `/blog/dossiers` reste une URL de
        // LISTE, seul son POST a disparu.
        foreach (['/blog/dossiers', '/org/'.$this->org->slug.'/blog/dossiers'] as $url) {
            $this->actingAs($this->owner)->postJson($url, ['name' => 'Quick force'])->assertStatus(405);
        }

        $this->assertDatabaseMissing('dossiers', ['name' => 'Quick force']);
    }

    /**
     * L'ancien marque-page rend 404, et il le rend sur LES DEUX moteurs.
     *
     * `/dossiers/create` retombe desormais sur `/dossiers/{dossier}`, donc sur
     * une comparaison entre le mot « create » et une colonne `uuid`. SQLite
     * l'avale et ne trouve rien — 404 par accident. PostgreSQL refuse la
     * comparaison (`22P02`) et rendait 500 : une erreur serveur en production
     * que le vert local n'aurait jamais montree. `resolveDossier()` verifie
     * donc la FORME avant d'interroger la base.
     */
    public function test_the_create_form_page_is_gone(): void
    {
        $this->actingAs($this->owner)
            ->get('/org/'.$this->org->slug.'/dossiers/create')
            ->assertNotFound();
    }

    public function test_any_non_uuid_dossier_segment_is_a_clean_404(): void
    {
        foreach (['create', 'nouveau', '12345'] as $segment) {
            $this->actingAs($this->owner)
                ->get('/org/'.$this->org->slug.'/dossiers/'.$segment)
                ->assertNotFound();
        }
    }

    public function test_the_policy_refuses_creation_to_everyone(): void
    {
        // La garde de fond : meme si une route de creation revenait sans
        // repasser par la decision produit, l'autorisation la refuserait.
        $this->assertFalse($this->owner->can('create', Dossier::class));
        $this->assertFalse($this->membre->can('create', Dossier::class));
    }

    // ── D. Le provisioning automatique continue ────────────────────────────

    public function test_the_root_dossier_of_a_loop_is_still_provisioned(): void
    {
        $nouvelle = Loop::factory()->create([
            'organization_id' => $this->org->id,
            'status' => 'active',
            'type' => 'general',
            'created_by' => $this->owner->id,
        ]);

        $racine = app(LoopRootDocumentService::class)->ensureRootDossier($nouvelle);

        $this->assertSame($nouvelle->id, $racine->loop_id);
        $this->assertNull($racine->owner_id);
        $this->assertDatabaseHas('dossiers', ['loop_id' => $nouvelle->id]);

        // Idempotent : le second appel rend la meme ligne, il n'en cree pas
        // une seconde.
        $this->assertTrue($racine->is(app(LoopRootDocumentService::class)->ensureRootDossier($nouvelle)));
        $this->assertSame(1, Dossier::where('loop_id', $nouvelle->id)->count());
    }

    public function test_the_personal_documents_root_is_still_provisioned_on_first_visit(): void
    {
        $nouveau = User::factory()->create(['organization_id' => $this->org->id]);

        $this->assertSame(
            0,
            Dossier::where('owner_id', $nouveau->id)
                ->where('system_role', Dossier::SYSTEM_ROLE_PERSONAL_DOCUMENTS)
                ->count(),
        );

        $this->actingAs($nouveau)
            ->get(route('organization.dossiers.index', ['organization' => $this->org->slug]))
            ->assertOk();

        $racine = app(PersonalDocumentsRoot::class)->find($this->org->id, $nouveau->id);

        $this->assertNotNull($racine);
        $this->assertNull($racine->loop_id);
        $this->assertNull($racine->parent_id);
    }

    // ── E. Le legacy reste lisible, et rien n'est efface ───────────────────

    public function test_an_existing_subfolder_tree_stays_readable_and_navigable(): void
    {
        $enfant = Dossier::create([
            'organization_id' => $this->org->id,
            'parent_id' => $this->racineBoucle->getKey(),
            'name' => 'Communication legacy',
        ]);
        $petitEnfant = Dossier::create([
            'organization_id' => $this->org->id,
            'parent_id' => $enfant->getKey(),
            'name' => 'Presse legacy',
        ]);

        // Le parent liste toujours son enfant...
        $this->assertStringContainsString(
            'Communication legacy',
            $this->actingAs($this->owner)->get(route('organization.dossiers.show', [
                'organization' => $this->org->slug, 'dossier' => $this->racineBoucle->getKey(),
            ]))->assertOk()->getContent(),
        );

        // ...et la profondeur 2 s'ouvre, avec son fil d'Ariane complet.
        $html = $this->actingAs($this->owner)->get(route('organization.dossiers.show', [
            'organization' => $this->org->slug, 'dossier' => $petitEnfant->getKey(),
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('Presse legacy', $html);
        $this->assertStringContainsString('Communication legacy', $html);

        // Rien n'a ete supprime en chemin : TASK-1630 s'en chargera, pas celle-ci.
        $this->assertDatabaseHas('dossiers', ['id' => $enfant->getKey()]);
        $this->assertDatabaseHas('dossiers', ['id' => $petitEnfant->getKey()]);
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function article(string $titre): \App\Models\BlogPost
    {
        return \App\Models\BlogPost::create([
            'user_id' => $this->owner->id,
            'organization_id' => $this->org->id,
            'title' => $titre,
            'slug' => \Illuminate\Support\Str::slug($titre),
            'content' => '<p>Contenu.</p>',
            'status' => 'draft',
        ]);
    }
}
