<?php

namespace Tests\Feature;

use App\Models\ArticleSeries;
use App\Models\ArticleSeriesItem;
use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierBlogPost;
use App\Models\DossierFile;
use App\Models\DossierMember;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use App\Services\Dossiers\DossierPurgeEligibility;
use App\Services\Dossiers\DossierTreePurger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-1630 — l'outil SuperAdmin « Nettoyage des Dossiers ».
 *
 * Deux choses sont gardees ici, et elles tirent dans des directions
 * opposees — c'est tout l'interet de les tenir ensemble :
 *
 *   · l'outil DOIT pouvoir detruire, physiquement et completement, une
 *     arborescence legacy et tout ce qui y pend ;
 *   · il ne doit JAMAIS detruire ce dont le produit vit encore — la racine
 *     d'une Boucle active, l'espace documentaire d'un compte actif — ni
 *     toucher a une autre Organization.
 *
 * Le scenario du 23514 (suppression d'une Boucle qui traine des
 * sous-dossiers) vit dans `TASK1630LoopDeleteLegacyBranchTest`, marque
 * PostgreSQL : la contrainte `dossiers_holder_xor` n'existe pas en SQLite, et
 * l'y rejouer rendrait un vert qui ne prouve rien.
 */
class TASK1630DossierCleanupToolTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $superAdmin;

    private User $membre;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create(['is_active' => true, 'is_default' => true]);
        $this->orgA = Organization::factory()->create(['is_active' => true, 'loops_enabled' => true, 'slug' => 'org-a-1630']);
        $this->orgB = Organization::factory()->create(['is_active' => true, 'loops_enabled' => true, 'slug' => 'org-b-1630']);

        $this->superAdmin = User::factory()->create(['organization_id' => $this->orgA->id, 'is_admin' => true]);
        $this->membre = User::factory()->create(['organization_id' => $this->orgA->id, 'is_admin' => false]);
    }

    // ── A. Listing et classification ───────────────────────────────────────

    public function test_the_listing_shows_every_organization_with_the_right_classification(): void
    {
        $racineUtilisateur = $this->racineUtilisateur($this->orgA, $this->membre, 'Espace du membre');
        $loop = $this->loop($this->orgA, 'Boucle vivante');
        $racineBoucle = $this->racineBoucle($loop, 'Documents de la Boucle');
        $enfant = $this->enfant($racineBoucle, 'Communication legacy');
        $petitEnfant = $this->enfant($enfant, 'Presse legacy');
        $ailleurs = $this->racineUtilisateur($this->orgB, User::factory()->create(['organization_id' => $this->orgB->id]), 'Espace Org B');

        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.outils.dossiers'))
            ->assertOk()
            ->getContent();

        // Toutes les Organizations, pas seulement celle du SuperAdmin.
        foreach (['Espace du membre', 'Documents de la Boucle', 'Communication legacy', 'Presse legacy', 'Espace Org B'] as $nom) {
            $this->assertStringContainsString($nom, $html);
        }

        // TASK-1635 : comparer au nom ECHAPPE, pas au nom brut. Les noms
        // d'Organization viennent de Faker ; des qu'il en tire un qui porte une
        // apostrophe — « Gulgowski-D'Amore », frequent — Blade rend `&#039;` et
        // l'assertion sur la chaine brute echoue. Le test passait ou echouait
        // selon le tirage, et la redistribution des shards de cette branche l'a
        // fait tomber du mauvais cote. Les noms de Dossiers ci-dessus sont
        // litteraux, donc non concernes.
        $this->assertStringContainsString(e($this->orgA->name), $html);
        $this->assertStringContainsString(e($this->orgB->name), $html);

        // Et la classification, lue par la primitive que le serveur utilise.
        $eligibility = app(DossierPurgeEligibility::class);
        $this->assertSame(DossierPurgeEligibility::TYPE_USER_ROOT, $eligibility->type($racineUtilisateur));
        $this->assertSame(DossierPurgeEligibility::TYPE_LOOP_ROOT, $eligibility->type($racineBoucle));
        $this->assertSame(DossierPurgeEligibility::TYPE_LEGACY_CHILD, $eligibility->type($enfant));
        $this->assertSame(DossierPurgeEligibility::TYPE_LEGACY_CHILD, $eligibility->type($petitEnfant));
        $this->assertSame(DossierPurgeEligibility::TYPE_USER_ROOT, $eligibility->type($ailleurs));
    }

    public function test_the_organization_filter_restricts_the_listing(): void
    {
        $this->racineUtilisateur($this->orgA, $this->membre, 'Visible ici');
        $this->racineUtilisateur($this->orgB, User::factory()->create(['organization_id' => $this->orgB->id]), 'Invisible ici');

        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.outils.dossiers', ['organization' => $this->orgA->slug]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Visible ici', $html);
        $this->assertStringNotContainsString('Invisible ici', $html);
    }

    public function test_an_unknown_organization_slug_is_said_and_never_filters_in_silence(): void
    {
        $this->racineUtilisateur($this->orgA, $this->membre, 'Toujours la');

        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.outils.dossiers', ['organization' => 'nexiste-pas']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('nexiste-pas', $html);
        $this->assertStringContainsString('Toujours la', $html);
    }

    public function test_the_type_filter_isolates_legacy_subfolders(): void
    {
        $loop = $this->loop($this->orgA, 'Boucle');
        $racine = $this->racineBoucle($loop, 'Racine visible partout');
        $this->enfant($racine, 'Enfant legacy');

        $enfant = Dossier::where('name', 'Enfant legacy')->firstOrFail();

        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.outils.dossiers', ['type' => DossierPurgeEligibility::TYPE_LEGACY_CHILD]))
            ->assertOk()
            ->getContent();

        // L'assertion porte sur la CASE A COCHER, pas sur le nom : la racine
        // apparait legitimement dans la colonne « Parent » de son enfant, et
        // chercher son nom dans la page rendrait ce test faux pour la
        // mauvaise raison.
        $this->assertStringContainsString('value="'.$enfant->getKey().'"', $html);
        $this->assertStringNotContainsString('value="'.$racine->getKey().'"', $html);
    }

    // ── B. Protection des racines actives ──────────────────────────────────

    public function test_the_root_of_an_active_loop_is_protected(): void
    {
        $loop = $this->loop($this->orgA, 'Boucle active', 'active');
        $racine = $this->racineBoucle($loop, 'Racine protegee');

        $eligibility = app(DossierPurgeEligibility::class);
        $this->assertSame(DossierPurgeEligibility::REASON_ACTIVE_LOOP, $eligibility->protectionReason($racine->fresh()));
        $this->assertFalse($eligibility->isPurgeable($racine->fresh()));
    }

    public function test_the_root_of_an_active_account_is_protected(): void
    {
        $racine = $this->racineUtilisateur($this->orgA, $this->membre, 'Mes documents');

        $eligibility = app(DossierPurgeEligibility::class);
        $this->assertSame(DossierPurgeEligibility::REASON_ACTIVE_USER, $eligibility->protectionReason($racine->fresh()));
    }

    public function test_an_archived_loop_root_and_a_banned_user_root_become_purgeable(): void
    {
        $archivee = $this->loop($this->orgA, 'Boucle archivee', 'archived');
        $racineArchivee = $this->racineBoucle($archivee, 'Racine archivee');

        $banni = User::factory()->create(['organization_id' => $this->orgA->id, 'banned_at' => now()]);
        $racineBanni = $this->racineUtilisateur($this->orgA, $banni, 'Racine dun compte banni');

        $eligibility = app(DossierPurgeEligibility::class);
        $this->assertTrue($eligibility->isPurgeable($racineArchivee->fresh()));
        $this->assertTrue($eligibility->isPurgeable($racineBanni->fresh()));
    }

    public function test_a_legacy_subfolder_is_always_purgeable_even_under_a_protected_root(): void
    {
        $loop = $this->loop($this->orgA, 'Boucle active', 'active');
        $racine = $this->racineBoucle($loop, 'Racine protegee');
        $enfant = $this->enfant($racine, 'Enfant purgeable');

        // Ce qui est protege, c'est le NOEUD racine — pas la branche qu'il
        // gouverne. Sans cela l'outil ne servirait a rien : presque tous les
        // sous-dossiers legacy pendent sous une racine vivante.
        $this->assertTrue(app(DossierPurgeEligibility::class)->isPurgeable($enfant->fresh()));
    }

    /**
     * La garde est au SERVEUR, pas a l'ecran.
     *
     * Une case desactivee dans le HTML n'empeche rien : ce POST la contourne
     * exactement comme le ferait un operateur presse ou un script.
     */
    public function test_a_forged_post_cannot_purge_a_protected_root(): void
    {
        $loop = $this->loop($this->orgA, 'Boucle active', 'active');
        $racine = $this->racineBoucle($loop, 'Racine protegee');

        $this->actingAs($this->superAdmin)
            ->post(route('admin.outils.dossiers.purge'), [
                'confirm' => '1',
                'dossiers' => [$racine->getKey()],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('dossiers', ['id' => $racine->getKey()]);
    }

    // ── C. Selection multiple et preview ───────────────────────────────────

    public function test_selecting_a_parent_and_its_child_counts_the_branch_once(): void
    {
        $loop = $this->loop($this->orgA, 'Boucle');
        $racine = $this->racineBoucle($loop, 'Racine');
        $enfant = $this->enfant($racine, 'Enfant');
        $petitEnfant = $this->enfant($enfant, 'Petit-enfant');

        $preview = app(DossierTreePurger::class)->preview([$enfant->fresh(), $petitEnfant->fresh()]);

        // Deux coches, mais deux noeuds seulement — pas trois.
        $this->assertCount(2, $preview['nodes']);
        $this->assertSame(2, $preview['dossiers']);
        $this->assertSame(0, $preview['descendants']);
    }

    public function test_the_preview_announces_what_the_purge_will_take_and_writes_nothing(): void
    {
        $loop = $this->loop($this->orgA, 'Boucle');
        $racine = $this->racineBoucle($loop, 'Racine');
        $enfant = $this->enfant($racine, 'Branche a purger');
        $petitEnfant = $this->enfant($enfant, 'Feuille');

        $this->fichier($petitEnfant, 'note.pdf');
        $this->liaisonArticle($enfant, 'Un article classe');

        $avant = Dossier::withTrashed()->count();

        $html = $this->actingAs($this->superAdmin)
            ->post(route('admin.outils.dossiers.preview'), ['dossiers' => [$enfant->getKey()]])
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Branche a purger', $html);
        $this->assertStringContainsString('Feuille', $html);

        // La preview ne touche a RIEN.
        $this->assertSame($avant, Dossier::withTrashed()->count());
        $this->assertDatabaseHas('dossiers', ['id' => $petitEnfant->getKey()]);
        $this->assertDatabaseHas('dossier_files', ['dossier_id' => $petitEnfant->getKey()]);
    }

    public function test_the_purge_refuses_without_an_explicit_confirmation(): void
    {
        $loop = $this->loop($this->orgA, 'Boucle');
        $racine = $this->racineBoucle($loop, 'Racine');
        $enfant = $this->enfant($racine, 'Enfant');

        $this->actingAs($this->superAdmin)
            ->post(route('admin.outils.dossiers.purge'), ['dossiers' => [$enfant->getKey()]])
            ->assertSessionHasErrors('confirm');

        $this->assertDatabaseHas('dossiers', ['id' => $enfant->getKey()]);
    }

    public function test_no_mutation_happens_through_a_get(): void
    {
        $loop = $this->loop($this->orgA, 'Boucle');
        $racine = $this->racineBoucle($loop, 'Racine');
        $enfant = $this->enfant($racine, 'Enfant');

        // Ni la preview ni la purge n'existent en GET.
        $this->actingAs($this->superAdmin)->get('/admin/outils/dossiers/purge')->assertStatus(405);
        $this->actingAs($this->superAdmin)->get('/admin/outils/dossiers/preview')->assertStatus(405);

        $this->assertDatabaseHas('dossiers', ['id' => $enfant->getKey()]);
    }

    // ── D. La purge elle-meme ──────────────────────────────────────────────

    public function test_a_three_level_branch_is_physically_removed_with_all_its_dependencies(): void
    {
        $loop = $this->loop($this->orgA, 'Boucle');
        $racine = $this->racineBoucle($loop, 'Racine conservee');
        $enfant = $this->enfant($racine, 'Niveau 1');
        $petitEnfant = $this->enfant($enfant, 'Niveau 2');

        $fichier = $this->fichier($petitEnfant, 'rapport.pdf');
        $liaison = $this->liaisonArticle($enfant, 'Article classe');
        $serie = $this->serie($enfant, 'Une serie');
        $membre = DossierMember::create([
            'organization_id' => $this->orgA->id,
            'dossier_id' => $enfant->getKey(),
            'user_id' => $this->membre->id,
            'role' => DossierMember::ROLE_READER,
        ]);

        $this->actingAs($this->superAdmin)
            ->post(route('admin.outils.dossiers.purge'), [
                'confirm' => '1',
                'dossiers' => [$enfant->getKey()],
            ])
            ->assertRedirect(route('admin.outils.dossiers'));

        // Les deux niveaux ont disparu PHYSIQUEMENT — pas une pierre tombale.
        $this->assertSame(0, Dossier::withTrashed()->whereKey($enfant->getKey())->count());
        $this->assertSame(0, Dossier::withTrashed()->whereKey($petitEnfant->getKey())->count());

        // Et rien d'orphelin derriere.
        $this->assertSame(0, DossierFile::withTrashed()->whereKey($fichier->getKey())->count());
        $this->assertDatabaseMissing('dossier_blog_posts', ['id' => $liaison->getKey()]);
        $this->assertDatabaseMissing('article_series', ['id' => $serie->getKey()]);
        $this->assertDatabaseMissing('dossier_members', ['id' => $membre->getKey()]);

        // La racine, elle, n'etait pas selectionnee : elle reste.
        $this->assertDatabaseHas('dossiers', ['id' => $racine->getKey()]);
    }

    public function test_articles_survive_the_purge_of_the_folder_that_held_them(): void
    {
        $loop = $this->loop($this->orgA, 'Boucle');
        $racine = $this->racineBoucle($loop, 'Racine');
        $enfant = $this->enfant($racine, 'Enfant');
        $liaison = $this->liaisonArticle($enfant, 'Un article qui doit survivre');

        app(DossierTreePurger::class)->purge([$enfant->fresh()]);

        $this->assertDatabaseMissing('dossier_blog_posts', ['id' => $liaison->getKey()]);
        // Le contenu editorial n'appartient pas au Dossier : seule la liaison
        // disparait.
        $this->assertDatabaseHas('blog_posts', ['id' => $liaison->blog_post_id]);
    }

    /**
     * Un enfant deja soft-delete est le piege de cet outil.
     *
     * Invisible a une requete ordinaire, sa ligne existe pourtant, et le
     * `forceDelete()` de son parent lui mettrait `parent_id` a NULL. L'outil
     * cense reparer le 23514 le rejouerait — precisement sur les donnees
     * legacy qu'il vise.
     */
    public function test_a_soft_deleted_child_is_purged_too_and_never_left_orphaned(): void
    {
        $loop = $this->loop($this->orgA, 'Boucle');
        $racine = $this->racineBoucle($loop, 'Racine');
        $enfant = $this->enfant($racine, 'Enfant');
        $petitEnfant = $this->enfant($enfant, 'Petit-enfant deja supprime');
        $petitEnfant->delete();

        $this->assertSame(1, Dossier::withTrashed()->whereKey($petitEnfant->getKey())->count());

        app(DossierTreePurger::class)->purge([$enfant->fresh()]);

        $this->assertSame(0, Dossier::withTrashed()->whereKey($petitEnfant->getKey())->count());
        $this->assertSame(0, Dossier::withTrashed()->whereKey($enfant->getKey())->count());
        // Aucun residu a `parent_id` NULL sans porteur : la ligne qui violerait
        // `dossiers_holder_xor`.
        $this->assertSame(0, Dossier::withTrashed()
            ->whereNull('parent_id')->whereNull('owner_id')->whereNull('loop_id')->count());
    }

    // ── E. Etancheite entre tenants ────────────────────────────────────────

    public function test_purging_a_branch_of_one_organization_leaves_the_other_untouched(): void
    {
        $loopA = $this->loop($this->orgA, 'Boucle A');
        $racineA = $this->racineBoucle($loopA, 'Racine A');
        $enfantA = $this->enfant($racineA, 'Enfant A');
        $this->fichier($enfantA, 'a.pdf');

        $userB = User::factory()->create(['organization_id' => $this->orgB->id]);
        $racineB = $this->racineUtilisateur($this->orgB, $userB, 'Racine B');
        $enfantB = $this->enfant($racineB, 'Enfant B');
        $fichierB = $this->fichier($enfantB, 'b.pdf');

        $this->actingAs($this->superAdmin)
            ->post(route('admin.outils.dossiers.purge'), [
                'confirm' => '1',
                'dossiers' => [$enfantA->getKey()],
            ])
            ->assertRedirect();

        $this->assertSame(0, Dossier::withTrashed()->whereKey($enfantA->getKey())->count());

        // Org B : pas une ligne touchee.
        $this->assertDatabaseHas('dossiers', ['id' => $racineB->getKey()]);
        $this->assertDatabaseHas('dossiers', ['id' => $enfantB->getKey()]);
        $this->assertDatabaseHas('dossier_files', ['id' => $fichierB->getKey()]);
    }

    // ── F. Autorisation ────────────────────────────────────────────────────

    public function test_a_guest_is_refused(): void
    {
        $this->get(route('admin.outils.dossiers'))->assertRedirect(route('login'));
        $this->post(route('admin.outils.dossiers.purge'), ['confirm' => '1'])->assertRedirect(route('login'));
    }

    public function test_a_plain_member_is_refused(): void
    {
        $this->actingAs($this->membre)->get(route('admin.outils.dossiers'))->assertForbidden();
        $this->actingAs($this->membre)->post(route('admin.outils.dossiers.preview'), ['dossiers' => []])->assertForbidden();
        $this->actingAs($this->membre)->post(route('admin.outils.dossiers.purge'), ['confirm' => '1'])->assertForbidden();
    }

    /**
     * L'OrgAdmin administre SON Organization, pas la plateforme.
     *
     * La distinction est reelle dans l'architecture : `OrgAdminMiddleware`
     * accepte `organizations.admin_id`, le middleware `admin` exige
     * `users.is_admin`. Cet outil liste TOUTES les Organizations — il est
     * derriere le second, et ce test le prouve plutot que de s'en remettre au
     * fait que le lien est cache.
     */
    public function test_an_org_admin_who_is_not_platform_admin_is_refused(): void
    {
        $orgAdmin = User::factory()->create(['organization_id' => $this->orgA->id, 'is_admin' => false]);
        $this->orgA->update(['admin_id' => $orgAdmin->id]);

        $this->actingAs($orgAdmin)->get(route('admin.outils.dossiers'))->assertForbidden();
        $this->actingAs($orgAdmin)->post(route('admin.outils.dossiers.purge'), [
            'confirm' => '1', 'dossiers' => [],
        ])->assertForbidden();
    }

    public function test_the_super_admin_is_allowed(): void
    {
        $this->actingAs($this->superAdmin)->get(route('admin.outils.dossiers'))->assertOk();
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function loop(Organization $org, string $nom, string $status = 'active'): Loop
    {
        $loop = Loop::factory()->create([
            'organization_id' => $org->id, 'status' => $status, 'type' => 'general', 'name' => $nom,
        ]);

        LoopMember::factory()->owner()->create([
            'loop_id' => $loop->id, 'user_id' => $this->superAdmin->id, 'joined_at' => now(),
        ]);

        return $loop;
    }

    private function racineBoucle(Loop $loop, string $nom): Dossier
    {
        return Dossier::create([
            'organization_id' => $loop->organization_id,
            'owner_id' => null,
            'loop_id' => $loop->id,
            'name' => $nom,
            'visibility' => Dossier::VISIBILITY_LOOP,
        ]);
    }

    private function racineUtilisateur(Organization $org, User $owner, string $nom): Dossier
    {
        return Dossier::create([
            'organization_id' => $org->id,
            'owner_id' => $owner->id,
            'name' => $nom,
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);
    }

    private function enfant(Dossier $parent, string $nom): Dossier
    {
        return Dossier::create([
            'organization_id' => $parent->organization_id,
            'parent_id' => $parent->getKey(),
            'name' => $nom,
            'visibility' => $parent->visibility,
        ]);
    }

    private function fichier(Dossier $dossier, string $nom): DossierFile
    {
        return DossierFile::factory()->create([
            'organization_id' => $dossier->organization_id,
            'dossier_id' => $dossier->getKey(),
            'uploaded_by' => $this->membre->id,
            'display_name' => $nom,
        ]);
    }

    private function liaisonArticle(Dossier $dossier, string $titre): DossierBlogPost
    {
        $article = BlogPost::create([
            'user_id' => $this->membre->id,
            'organization_id' => $dossier->organization_id,
            'title' => $titre,
            'slug' => Str::slug($titre).'-'.Str::random(5),
            'content' => '<p>Contenu.</p>',
            'status' => 'draft',
        ]);

        return DossierBlogPost::create([
            'organization_id' => $dossier->organization_id,
            'dossier_id' => $dossier->getKey(),
            'blog_post_id' => $article->id,
            'added_by' => $this->membre->id,
        ]);
    }

    private function serie(Dossier $dossier, string $nom): ArticleSeries
    {
        $serie = ArticleSeries::create([
            'organization_id' => $dossier->organization_id,
            'dossier_id' => $dossier->getKey(),
            'name' => $nom,
        ]);

        ArticleSeriesItem::create([
            'organization_id' => $dossier->organization_id,
            'article_series_id' => $serie->id,
            'blog_post_id' => $this->liaisonArticle($dossier, 'Article de la serie '.$nom)->blog_post_id,
            'position' => 1,
        ]);

        return $serie;
    }
}
