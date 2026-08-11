<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierBlogPost;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le Drive de la Boucle : dossiers, Articles et fichiers dans une seule vue.
 *
 * Ce que ces tests gardent :
 *
 * - les dossiers du Drive sont des Dossiers **reellement partages** avec la
 *   Boucle — pas une hierarchie simulee cote frontend ;
 * - le breadcrumb remonte d'un dossier partage vers le Dossier racine ;
 * - creer un dossier depuis le Drive passe par `store()` avec **la regle de
 *   partage d'update()** — meme garde tenant, meme refus ;
 * - les Articles gardent leur identite editoriale dans la meme page ;
 * - rien ne fuit entre Organizations.
 */
class TASK1130DriveTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $owner;

    private Loop $loop;

    private Dossier $racine;

    protected function setUp(): void
    {
        parent::setUp();

        // Organization non-default : rien ne doit dependre de `main`.
        Organization::factory()->create(['is_active' => true, 'is_default' => true]);
        $this->org = Organization::factory()->create(['is_active' => true, 'loops_enabled' => true]);

        $this->owner = User::factory()->create(['organization_id' => $this->org->id]);

        $this->loop = Loop::factory()->create([
            'organization_id' => $this->org->id, 'status' => 'active', 'type' => 'general',
            'created_by' => $this->owner->id,
        ]);
        LoopMember::factory()->owner()->create([
            'loop_id' => $this->loop->id, 'user_id' => $this->owner->id, 'joined_at' => now(),
        ]);

        $this->racine = Dossier::create([
            'organization_id' => $this->org->id,
            'owner_id' => null,
            'name' => 'Documents',
            'visibility' => Dossier::VISIBILITY_LOOP,
            'loop_id' => $this->loop->id,
        ]);

        app()->instance('current_organization', $this->org);
    }

    private function dossierPartage(string $nom = 'Projet Marseille'): Dossier
    {
        return Dossier::create([
            'organization_id' => $this->org->id,
            'owner_id' => $this->owner->id,
            'name' => $nom,
            'visibility' => Dossier::VISIBILITY_LOOP,
            'shared_with_loop_id' => $this->loop->id,
        ]);
    }

    private function page(Dossier $dossier): string
    {
        return $this->actingAs($this->owner)
            ->get(route('organization.dossiers.show', ['organization' => $this->org->slug, 'dossier' => $dossier->getKey()]))
            ->assertOk()
            ->getContent();
    }

    // ── Les dossiers du Drive ───────────────────────────────────────────────

    public function test_the_root_drive_lists_the_folders_shared_with_the_loop(): void
    {
        $this->dossierPartage('Projet Marseille');
        $this->dossierPartage('Budget previsionnel');

        $html = $this->page($this->racine);

        $this->assertStringContainsString('Projet Marseille', $html);
        $this->assertStringContainsString('Budget previsionnel', $html);
    }

    public function test_a_folder_of_another_loop_never_shows_in_this_drive(): void
    {
        $autreLoop = Loop::factory()->create([
            'organization_id' => $this->org->id, 'status' => 'active', 'type' => 'general',
        ]);
        Dossier::create([
            'organization_id' => $this->org->id, 'owner_id' => $this->owner->id,
            'name' => 'Dossier de l autre Boucle', 'visibility' => Dossier::VISIBILITY_LOOP,
            'shared_with_loop_id' => $autreLoop->id,
        ]);

        $this->assertStringNotContainsString('Dossier de l autre Boucle', $this->page($this->racine));
    }

    public function test_the_breadcrumb_climbs_back_from_a_shared_folder_to_the_root(): void
    {
        $partage = $this->dossierPartage('Projet Marseille');

        $html = $this->page($partage);

        // Le fil remonte : « Documents » est un lien vers le racine, le nom
        // du dossier courant est la position.
        $this->assertStringContainsString(__('dossiers.drive_breadcrumb_root'), $html);
        $this->assertStringContainsString(
            route('organization.dossiers.show', ['organization' => $this->org->slug, 'dossier' => $this->racine->getKey()]),
            $html,
        );
        $this->assertStringContainsString('aria-current="page"', $html);
    }

    // ── Creer un dossier depuis le Drive ────────────────────────────────────

    public function test_creating_a_folder_from_the_drive_shares_it_with_the_loop(): void
    {
        $reponse = $this->actingAs($this->owner)->post(
            route('organization.dossiers.store', ['organization' => $this->org->slug]),
            [
                'name' => 'Communication',
                'visibility' => 'loop',
                'shared_with_loop_id' => $this->loop->id,
                'return_to_dossier' => $this->racine->getKey(),
            ],
        );

        // Retour au Drive, la ou le dossier vient d'apparaitre.
        $reponse->assertRedirect(
            route('organization.dossiers.show', ['organization' => $this->org->slug, 'dossier' => $this->racine->getKey()]),
        );

        $this->assertDatabaseHas('dossiers', [
            'name' => 'Communication',
            'visibility' => Dossier::VISIBILITY_LOOP,
            'shared_with_loop_id' => $this->loop->id,
            'organization_id' => $this->org->id,
        ]);
    }

    public function test_sharing_with_a_loop_of_another_organization_is_refused(): void
    {
        // La meme garde qu'update() : une Boucle d'un autre tenant n'existe pas.
        $autreOrg = Organization::factory()->create(['is_active' => true, 'loops_enabled' => true]);
        $loopAilleurs = Loop::factory()->create([
            'organization_id' => $autreOrg->id, 'status' => 'active', 'type' => 'general',
        ]);

        $this->actingAs($this->owner)->post(
            route('organization.dossiers.store', ['organization' => $this->org->slug]),
            ['name' => 'Intrusion', 'visibility' => 'loop', 'shared_with_loop_id' => $loopAilleurs->id],
        )->assertSessionHasErrors('shared_with_loop_id');

        $this->assertDatabaseMissing('dossiers', ['name' => 'Intrusion']);
    }

    public function test_a_plain_private_dossier_still_works_as_before(): void
    {
        // Le chemin historique de store() n'a pas bouge : prive par defaut.
        $this->actingAs($this->owner)->post(
            route('organization.dossiers.store', ['organization' => $this->org->slug]),
            ['name' => 'Mon dossier prive'],
        )->assertRedirect(route('organization.dossiers.index', ['organization' => $this->org->slug]));

        $this->assertDatabaseHas('dossiers', [
            'name' => 'Mon dossier prive',
            'visibility' => Dossier::VISIBILITY_PRIVATE,
            'shared_with_loop_id' => null,
        ]);
    }

    // ── Les Articles dans le Drive ──────────────────────────────────────────

    public function test_articles_keep_their_editorial_identity_in_the_drive(): void
    {
        $article = BlogPost::create([
            'user_id' => $this->owner->id,
            'organization_id' => $this->org->id,
            'title' => 'Compte rendu de la reunion',
            'slug' => 'compte-rendu-reunion',
            'content' => '<p>Contenu.</p>',
            'status' => 'published',
        ]);
        DossierBlogPost::create([
            'dossier_id' => $this->racine->getKey(),
            'blog_post_id' => $article->id,
            'organization_id' => $this->org->id,
            'position' => 1,
        ]);

        $html = $this->page($this->racine);

        $this->assertStringContainsString('Compte rendu de la reunion', $html);
        $this->assertStringContainsString(__('dossiers.drive_article_badge'), $html);
    }

    // ── Tenant ──────────────────────────────────────────────────────────────

    public function test_the_drive_of_another_organization_stays_out_of_reach(): void
    {
        $autreOrg = Organization::factory()->create(['is_active' => true, 'loops_enabled' => true]);
        $intrus = User::factory()->create(['organization_id' => $autreOrg->id]);

        app()->instance('current_organization', $autreOrg);

        $this->actingAs($intrus)
            ->get(route('organization.dossiers.show', ['organization' => $autreOrg->slug, 'dossier' => $this->racine->getKey()]))
            ->assertNotFound();
    }
}
