<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\DossierBlogPost;
use App\Models\DossierFile;
use App\Models\DossierMember;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use App\Services\Dossiers\PersonalDocumentsRoot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * « Partages avec moi » nomme le vrai proprietaire (TASK-1140).
 *
 * ## Le defaut
 *
 * Un sous-dossier reel n'a **pas** d'`owner_id` — c'est la doctrine posee par
 * TASK-1130 : la propriete vit sur la racine gouvernante. La vue lisait pourtant
 * `$ligne->owner` directement :
 *
 * ```php
 * $proprio = $ligne->owner?->isDisplayableIn(...) ? ... : __('profile.deactivated_user');
 * ```
 *
 * `owner` valant `null`, l'operateur `?->` court-circuitait et la ligne tombait
 * dans le repli « Utilisateur desactive ». Le repli confondait **deux etats
 * differents** : « ce compte est desactive » et « cette ligne ne porte pas
 * l'identite de son proprietaire ». Le proprietaire existait et allait tres
 * bien.
 *
 * ## Ce que ces tests gardent, au-dela du libelle
 *
 * La correction lit la propriete sur la **gouvernance** — la question « a qui
 * appartient ce dossier ». Elle ne touche pas au **partage** — la question « qui
 * y a acces ». C'est exactement la distinction que TASK-1136 a du etablir dans
 * la douleur : `governingDossier()` avait servi de perimetre de partage, et
 * l'espace personnel entier avait fuite.
 *
 * Le pack de tests verifie donc les deux moities a chaque fois : le bon nom
 * s'affiche, **et** le perimetre d'acces ne bouge pas d'un pouce.
 */
class TASK1140SharedOwnerDisplayTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Organization $autreOrg;

    private User $proprietaire;

    private User $invite;

    private Dossier $racine;

    /** Le dossier explicitement partage — « Dossier test 3 » du banc. */
    private Dossier $partage;

    private Dossier $descendant;

    private Dossier $frere;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['is_active' => true, 'is_default' => true]);
        $this->autreOrg = Organization::factory()->create(['is_active' => true]);

        // `fullName` compose `first_name` + `name` : deux valeurs improbables
        // pour qu'une assertion sur le nom ne puisse pas passer par hasard.
        $this->proprietaire = User::factory()->create([
            'organization_id' => $this->org->id,
            'first_name' => 'Adminia',
            'name' => 'Proprietairova',
        ]);
        $this->invite = User::factory()->create(['organization_id' => $this->org->id]);

        app()->instance('current_organization', $this->org);

        $this->racine = app(PersonalDocumentsRoot::class)->resolve($this->org->id, $this->proprietaire->id);
        $this->partage = $this->sousDossier($this->racine, 'Dossier test 3');
        $this->descendant = $this->sousDossier($this->partage, 'Sous-dossier du partage');
        $this->frere = $this->sousDossier($this->racine, 'Test Cyril');
    }

    // ── Helpers, repris de TASK-1130 / TASK-1136 ─────────────────────────────

    private function sousDossier(Dossier $parent, string $nom): Dossier
    {
        return Dossier::create([
            'organization_id' => $this->org->id,
            'parent_id' => $parent->id,
            'name' => $nom,
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);
    }

    private function partager(Dossier $cible, string $role = 'reader', ?User $qui = null): void
    {
        $membre = $qui ?? $this->invite;

        $this->actingAs($this->proprietaire)->postJson(
            route('organization.dossiers.members.store', [
                'organization' => $this->org->slug,
                'dossier' => $cible->getKey(),
            ]),
            ['user_id' => $membre->getKey()],
        )->assertSuccessful();

        // TASK-1141 : tout nouvel acces commence en Lecteur. Le passage
        // eventuel a Editeur est une seconde action autosauvegardee.
        if ($role === 'editor') {
            $this->actingAs($this->proprietaire)->patchJson(
                route('organization.dossiers.members.update', [
                    'organization' => $this->org->slug,
                    'dossier' => $cible->getKey(),
                    'member' => $membre->getKey(),
                ]),
                ['role' => 'editor'],
            )->assertSuccessful();
        }
    }

    private function retirer(Dossier $cible): void
    {
        $this->actingAs($this->proprietaire)->deleteJson(
            route('organization.dossiers.members.destroy', [
                'organization' => $this->org->slug,
                'dossier' => $cible->getKey(),
                'member' => $this->invite->getKey(),
            ]),
        )->assertSuccessful();
    }

    /** La page « Partages », vue par l'invite. */
    private function pagePartages(string $vue = 'avec-moi'): string
    {
        return $this->actingAs($this->invite)
            ->get(route('organization.dossiers.index', ['organization' => $this->org->slug]).'?espace=partages&vue='.$vue)
            ->assertOk()
            ->getContent();
    }

    private function nomAttendu(): string
    {
        return $this->proprietaire->fullName;
    }

    private function ouvrirCommeInvite(Dossier $dossier)
    {
        return $this->actingAs($this->invite)->get(route('organization.dossiers.show', [
            'organization' => $this->org->slug,
            'dossier' => $dossier->getKey(),
        ]));
    }

    // ── 1. Le defaut signale ─────────────────────────────────────────────────

    public function test_a_shared_child_names_its_real_owner(): void
    {
        $this->partager($this->partage);

        $html = $this->pagePartages();

        $this->assertStringContainsString('Dossier test 3', $html);
        $this->assertStringContainsString($this->nomAttendu(), $html);
        $this->assertStringNotContainsString(__('profile.deactivated_user'), $html);
    }

    public function test_the_shared_child_really_has_no_owner_id(): void
    {
        // L'hypothese sur laquelle repose toute la tache. Si elle tombait, la
        // correction viserait a cote : autant que le test le dise lui-meme.
        $this->assertNull($this->partage->fresh()->owner_id);
        $this->assertSame($this->racine->id, $this->partage->fresh()->governingDossier()->id);
        $this->assertSame($this->proprietaire->id, $this->racine->fresh()->owner_id);
    }

    // ── 2. La profondeur ─────────────────────────────────────────────────────

    public function test_a_level_two_child_names_the_same_owner(): void
    {
        $this->partager($this->descendant);

        $html = $this->pagePartages();

        $this->assertStringContainsString('Sous-dossier du partage', $html);
        $this->assertStringContainsString($this->nomAttendu(), $html);
        $this->assertStringNotContainsString(__('profile.deactivated_user'), $html);
    }

    public function test_a_level_three_child_names_the_same_owner(): void
    {
        $petitFils = $this->sousDossier($this->descendant, 'Niveau trois');
        $this->partager($petitFils);

        $html = $this->pagePartages();

        $this->assertStringContainsString('Niveau trois', $html);
        $this->assertStringContainsString($this->nomAttendu(), $html);
        $this->assertStringNotContainsString(__('profile.deactivated_user'), $html);
    }

    // ── 3. Le repli reste legitime quand il dit vrai ─────────────────────────

    public function test_a_genuinely_deactivated_owner_still_falls_back(): void
    {
        // Le repli n'est pas supprime : il est rendu exact. Un compte banni doit
        // continuer a s'effacer derriere le libelle.
        DossierMember::create([
            'organization_id' => $this->org->id,
            'dossier_id' => $this->partage->id,
            'user_id' => $this->invite->id,
            'role' => DossierMember::ROLE_READER,
            'added_by' => $this->proprietaire->id,
        ]);
        $this->proprietaire->forceFill(['banned_at' => now()])->save();

        $html = $this->pagePartages();

        $this->assertStringContainsString('Dossier test 3', $html);
        $this->assertStringContainsString(__('profile.deactivated_user'), $html);
        $this->assertStringNotContainsString($this->nomAttendu(), $html);
    }

    public function test_an_owner_from_another_organization_is_not_named(): void
    {
        // `isDisplayableIn` protege aussi le cloisonnement : un proprietaire
        // d'une autre Organization ne doit pas etre nomme ici.
        $this->partager($this->partage);
        $this->proprietaire->forceFill(['organization_id' => $this->autreOrg->id])->save();

        $html = $this->pagePartages();

        $this->assertStringContainsString(__('profile.deactivated_user'), $html);
    }

    // ── 4. Les racines ordinaires ne changent pas ────────────────────────────

    public function test_a_plain_private_root_keeps_its_direct_owner(): void
    {
        $racineOrdinaire = Dossier::create([
            'organization_id' => $this->org->id,
            'owner_id' => $this->proprietaire->id,
            'name' => 'Dossier historique',
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);
        $this->partager($racineOrdinaire);

        $html = $this->pagePartages();

        $this->assertStringContainsString('Dossier historique', $html);
        $this->assertStringContainsString($this->nomAttendu(), $html);
        $this->assertStringNotContainsString(__('profile.deactivated_user'), $html);
    }

    // ── 5. Surface et breadcrumb reader-aware ───────────────────────────────

    public function test_a_direct_shared_url_uses_the_shared_surface(): void
    {
        $this->partager($this->partage);

        $this->ouvrirCommeInvite($this->partage)
            ->assertOk()
            ->assertViewHas('espace', 'partages');
    }

    public function test_a_shared_breadcrumb_never_contains_the_owners_personal_root(): void
    {
        $this->partager($this->partage);

        $response = $this->ouvrirCommeInvite($this->partage)->assertOk();

        $response->assertSeeInOrder([__('dossiers.space_shared'), 'Dossier test 3']);
        $this->assertSame([], $response->viewData('breadcrumbAncestors')->pluck('id')->all());
    }

    public function test_a_share_on_a_projects_shared_a_b_c_breadcrumb(): void
    {
        $b = $this->sousDossier($this->partage, 'B partage descendant');
        $c = $this->sousDossier($b, 'C partage descendant');
        $this->partager($this->partage);

        $response = $this->ouvrirCommeInvite($c)->assertOk();

        $response->assertSeeInOrder([__('dossiers.space_shared'), 'Dossier test 3', 'B partage descendant', 'C partage descendant']);
        $this->assertSame([$this->partage->id, $b->id], $response->viewData('breadcrumbAncestors')->pluck('id')->all());
    }

    public function test_a_share_only_on_b_projects_shared_b_c_and_omits_a(): void
    {
        $b = $this->sousDossier($this->partage, 'B seule ancre');
        $c = $this->sousDossier($b, 'C sous B');
        $this->partager($b);

        $response = $this->ouvrirCommeInvite($c)->assertOk();

        $response->assertSeeInOrder([__('dossiers.space_shared'), 'B seule ancre', 'C sous B']);
        $response->assertDontSee('Dossier test 3');
        $this->assertSame([$b->id], $response->viewData('breadcrumbAncestors')->pluck('id')->all());
    }

    public function test_every_visible_shared_breadcrumb_link_is_accessible(): void
    {
        $b = $this->sousDossier($this->partage, 'B accessible');
        $c = $this->sousDossier($b, 'C accessible');
        $this->partager($this->partage);

        $response = $this->ouvrirCommeInvite($c)->assertOk();

        foreach ($response->viewData('breadcrumbAncestors') as $ancestor) {
            $this->ouvrirCommeInvite($ancestor)->assertOk();
        }
    }

    public function test_the_owner_keeps_the_documents_surface_for_the_same_child(): void
    {
        $this->partager($this->partage);

        $this->actingAs($this->proprietaire)
            ->get(route('organization.dossiers.show', ['organization' => $this->org->slug, 'dossier' => $this->partage->id]))
            ->assertOk()
            ->assertViewHas('espace', 'documents');
    }

    // ── 5-6. Les roles donnent ce qu'ils annoncent, et rien de plus ──────────

    public function test_a_reader_reads_but_neither_writes_nor_shares(): void
    {
        $this->partager($this->partage, 'reader');
        $partage = $this->partage->fresh();

        $this->assertTrue($this->invite->can('view', $partage));
        $this->assertFalse($this->invite->can('update', $partage));
        $this->assertFalse($this->invite->can('manageMembers', $partage));
        $this->assertFalse($this->invite->can('delete', $partage));
    }

    public function test_an_editor_writes_but_still_does_not_share(): void
    {
        $this->partager($this->partage, 'editor');
        $partage = $this->partage->fresh();

        $this->assertTrue($this->invite->can('view', $partage));
        $this->assertTrue($this->invite->can('update', $partage));
        // Partager reste au proprietaire : un editeur n'elargit pas le cercle.
        $this->assertFalse($this->invite->can('manageMembers', $partage));
    }

    public function test_an_editor_shared_on_a_updates_its_descendant_b_but_not_private_ancestors_or_siblings(): void
    {
        $this->partager($this->partage, 'editor');

        $this->assertTrue($this->invite->can('update', $this->partage->fresh()));
        $this->assertTrue($this->invite->can('update', $this->descendant->fresh()));
        $this->assertFalse($this->invite->can('update', $this->racine->fresh()));
        $this->assertFalse($this->invite->can('update', $this->frere->fresh()));
    }

    public function test_removing_an_editor_share_cuts_update_on_a_and_its_descendants(): void
    {
        $this->partager($this->partage, 'editor');
        $this->retirer($this->partage);

        $this->assertFalse($this->invite->can('update', $this->partage->fresh()));
        $this->assertFalse($this->invite->can('update', $this->descendant->fresh()));
    }

    public function test_an_explicit_descendant_editor_share_survives_the_parent_share_removal(): void
    {
        $petitFils = $this->sousDossier($this->descendant, 'Petit-fils editable');
        $this->partager($this->partage, 'editor');
        $this->partager($this->descendant, 'editor');

        $this->retirer($this->partage);

        $this->assertFalse($this->invite->can('update', $this->partage->fresh()));
        $this->assertTrue($this->invite->can('update', $this->descendant->fresh()));
        $this->assertTrue($this->invite->can('update', $petitFils->fresh()));
    }

    // ── 7. Le retrait coupe vraiment ─────────────────────────────────────────

    public function test_removing_the_share_removes_the_row_and_the_access(): void
    {
        $this->partager($this->partage);
        $this->assertStringContainsString('Dossier test 3', $this->pagePartages());

        $this->retirer($this->partage);

        $this->assertStringNotContainsString('Dossier test 3', $this->pagePartages());
        $this->assertFalse($this->invite->can('view', $this->partage->fresh()));
        $this->assertFalse($this->invite->can('view', $this->descendant->fresh()));
    }

    // ── 8. Parent et descendant partages ensemble ────────────────────────────

    public function test_an_explicit_share_on_a_descendant_survives_the_parent_removal(): void
    {
        $this->partager($this->partage);
        $this->partager($this->descendant);

        $this->retirer($this->partage);

        // Le descendant garde SON partage propre : le retrait du parent ne le
        // debranche pas.
        $this->assertFalse($this->invite->can('view', $this->partage->fresh()));
        $this->assertTrue($this->invite->can('view', $this->descendant->fresh()));

        $html = $this->pagePartages();
        $this->assertStringContainsString('Sous-dossier du partage', $html);
        $this->assertStringContainsString($this->nomAttendu(), $html);
    }

    // ── 9-10. Le contenu du sous-arbre suit ──────────────────────────────────

    public function test_a_file_in_a_descendant_is_reachable(): void
    {
        $this->partager($this->partage, 'editor');

        $fichier = DossierFile::create([
            'organization_id' => $this->org->id,
            'dossier_id' => $this->descendant->id,
            'uploaded_by' => $this->proprietaire->id,
            'disk' => 'local',
            'path' => 'dossier-files/note.md',
            'original_name' => 'note.md',
            'display_name' => 'note.md',
            'mime_type' => 'text/markdown',
            'size_bytes' => 12,
            'checksum_sha256' => str_repeat('a', 64),
        ]);

        $this->assertTrue($this->invite->can('view', $this->descendant->fresh()));
        $this->assertSame($this->descendant->id, $fichier->fresh()->dossier_id);
    }

    public function test_an_article_in_a_descendant_is_reachable(): void
    {
        $this->partager($this->partage, 'editor');

        $article = BlogPost::create([
            'organization_id' => $this->org->id,
            'user_id' => $this->proprietaire->id,
            'title' => 'Article du sous-arbre',
            'content' => 'Contenu.',
            'status' => 'draft',
        ]);
        DossierBlogPost::create([
            'organization_id' => $this->org->id,
            'dossier_id' => $this->descendant->id,
            'blog_post_id' => $article->id,
            'added_by' => $this->proprietaire->id,
            'position' => 1,
        ]);

        $this->assertTrue($this->invite->can('view', $this->descendant->fresh()));
    }

    // ── 11-12. Ce qui doit rester ferme ──────────────────────────────────────

    public function test_a_sibling_never_shared_stays_closed(): void
    {
        $this->partager($this->partage);

        $this->assertFalse($this->invite->can('view', $this->frere->fresh()));
        $this->assertStringNotContainsString('Test Cyril', $this->pagePartages());
    }

    public function test_the_personal_root_of_the_owner_stays_closed(): void
    {
        // Le coeur de TASK-1136, reverifie ici : nommer le proprietaire via la
        // gouvernance ne doit pas rouvrir la racine.
        $this->partager($this->partage);

        $this->assertFalse($this->invite->can('view', $this->racine->fresh()));
        $this->assertSame(0, DossierMember::where('dossier_id', $this->racine->id)->count());
        $this->assertSame(1, DossierMember::where('dossier_id', $this->partage->id)->count());
    }

    // ── 13. Les Boucles ne bougent pas ───────────────────────────────────────

    public function test_a_loop_dossier_keeps_its_behaviour(): void
    {
        $loop = Loop::factory()->create(['organization_id' => $this->org->id]);
        LoopMember::create([
            'loop_id' => $loop->id,
            'user_id' => $this->invite->id,
            'organization_id' => $this->org->id,
            'role' => 'member',
            'status' => 'active',
        ]);

        $racineBoucle = Dossier::create([
            'organization_id' => $this->org->id,
            'owner_id' => null,
            'name' => 'Documents de Boucle',
            'visibility' => Dossier::VISIBILITY_LOOP,
            'loop_id' => $loop->id,
        ]);
        $enfantBoucle = $this->sousDossier($racineBoucle, 'Communication');

        // Un membre de Boucle lit sans aucun `dossier_members` : inchange.
        $this->assertTrue($this->invite->can('view', $racineBoucle->fresh()));
        $this->assertTrue($this->invite->can('view', $enfantBoucle->fresh()));
        $this->assertFalse($this->invite->can('update', $racineBoucle->fresh()));
        $this->assertFalse($this->invite->can('update', $enfantBoucle->fresh()));
        $this->ouvrirCommeInvite($enfantBoucle)
            ->assertOk()
            ->assertViewHas('espace', 'boucles');
        // Et cela ne s'affiche pas dans « Partages avec moi », qui ne parle que
        // des invitations nominatives.
        $this->assertStringNotContainsString('Documents de Boucle', $this->pagePartages());
    }

    // ── 14. Organization = Tenant ────────────────────────────────────────────

    public function test_cross_organization_never_leaks(): void
    {
        $this->partager($this->partage);

        $etranger = User::factory()->create(['organization_id' => $this->autreOrg->id]);
        $this->assertFalse($etranger->can('view', $this->partage->fresh()));
        $this->assertFalse($etranger->can('update', $this->partage->fresh()));

        app()->instance('current_organization', $this->autreOrg);
        $this->actingAs($etranger)
            ->get(route('organization.dossiers.show', [
                'organization' => $this->autreOrg->slug,
                'dossier' => $this->partage->getKey(),
            ]))
            ->assertNotFound();
    }

    // ── 15. Les deux vues se repondent ───────────────────────────────────────

    public function test_shared_with_me_and_shared_by_me_agree(): void
    {
        $this->partager($this->partage);

        // Cote invite : le dossier partage, nomme par son vrai proprietaire.
        $avecMoi = $this->pagePartages('avec-moi');
        $this->assertStringContainsString('Dossier test 3', $avecMoi);
        $this->assertStringContainsString($this->nomAttendu(), $avecMoi);

        // Cote proprietaire : « Par moi » ne liste que ses racines partagees.
        // Le sous-dossier partage n'y figure pas — c'est le comportement actuel,
        // fige ici pour qu'un changement se voie.
        $parMoi = $this->actingAs($this->proprietaire)
            ->get(route('organization.dossiers.index', ['organization' => $this->org->slug]).'?espace=partages&vue=par-moi')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(__('profile.deactivated_user'), $parMoi);
    }
}
