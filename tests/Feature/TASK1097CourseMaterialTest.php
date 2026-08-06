<?php

namespace Tests\Feature;

use App\Models\BlogPost;
use App\Models\CourseModule;
use App\Models\CourseSequence;
use App\Models\Dossier;
use App\Models\DossierFile;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\User;
use App\Services\Loops\CourseMaterialService;
use App\Services\LoopService;
use App\Support\Loops\LoopCardRegistry;
use App\Support\Loops\LoopTypeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Le Support de cours d'une Boucle Formation.
 *
 * Deux choses sont verifiees ici, et la seconde compte autant que la premiere.
 *
 * **Ce que la Card fait** : des Modules, et dans chacun des Sequences, ordonnes,
 * numerotes au calcul, pouvant renvoyer a un Article ou a un fichier existant.
 *
 * **Ce que la Card n'est pas** : Module et Sequence ne sont **pas** des Cards.
 * Ils ne sont declares dans aucun catalogue, ne prennent aucun emplacement de
 * grille, n'ont aucune permission a eux. Et il n'existe **aucune** table
 * d'inscription : l'inscription, c'est `LoopMember`.
 */
class TASK1097CourseMaterialTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $owner;

    private Loop $loop;

    private LoopService $loops;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['is_active' => true]);
        $this->owner = User::factory()->create(['organization_id' => $this->org->id]);

        $this->loops = new LoopService;
        $this->loop = $this->trainingLoop('Formation test');

        app()->instance('current_organization', $this->org);
    }

    /**
     * Une Boucle Formation.
     *
     * Le type n'est pas **offert** — la matrice lui prevoit trois Cards, cette
     * tache en livre une — donc `createLoop()` ne l'accepte pas au formulaire.
     * Le type est pose ensuite, et le preset applique : c'est exactement l'etat
     * d'une Boucle Formation existante, celle que `TASK1079LoopTypeRegistryTest`
     * protege en verifiant qu'elle garde son type indisponible.
     */
    private function trainingLoop(string $nom): Loop
    {
        $loop = $this->loops->createLoop($this->owner, $nom);

        $loop->forceFill(['type' => 'training'])->save();

        // Les Cards du type precedent sont retirees avant d'appliquer celui-ci :
        // `applyPreset()` ajoute ce qui manque sans retirer ce qui reste, et la
        // Boucle aurait sinon compose les deux presets a la fois — un etat qui
        // aurait fait passer les assertions pour la mauvaise raison.
        \App\Models\LoopCard::where('loop_id', $loop->id)->delete();
        app(LoopTypeRegistry::class)->applyPreset($loop->fresh());

        return $loop->fresh();
    }

    private function service(): CourseMaterialService
    {
        return app(CourseMaterialService::class);
    }

    private function module(string $titre = 'Un Module'): CourseModule
    {
        return $this->service()->createModule($this->loop, $this->owner, $titre);
    }

    // ── La Card est une Card, ses contenus n'en sont pas ────────────────────

    public function test_the_course_material_is_declared_as_a_single_card(): void
    {
        $registry = app(LoopCardRegistry::class);

        $this->assertTrue($registry->exists('training.course_material'));
        $this->assertSame('grid', $registry->placementOf('training.course_material'));

        // Ni Module ni Sequence n'est une Card. Les declarer aurait fait trois
        // Cards la ou la matrice canonique n'en prevoit qu'une, et aurait mange
        // deux des trois emplacements de la grille.
        foreach (['training.module', 'training.modules', 'training.sequence', 'training.sequences'] as $inexistant) {
            $this->assertFalse($registry->exists($inexistant), "{$inexistant} ne doit pas exister");
        }
    }

    public function test_a_training_loop_composes_the_course_material(): void
    {
        $cles = app(LoopTypeRegistry::class)->activeCardsFor($this->loop);

        $this->assertContains('training.course_material', $cles);
        // Le cadre permanent est la partout : Manifeste et Membres.
        $this->assertContains('core.manifesto', $cles);
        $this->assertContains('core.members', $cles);
    }

    public function test_the_course_material_never_lands_in_another_type(): void
    {
        $autre = $this->loops->createLoop($this->owner, 'Un projet', type: 'project');

        $this->assertNotContains(
            'training.course_material',
            app(LoopTypeRegistry::class)->activeCardsFor($autre)
        );
    }

    public function test_no_progression_no_assignments_no_quiz_are_promised(): void
    {
        $registry = app(LoopCardRegistry::class);

        // Ces trois Cards existent dans la matrice canonique, mais cette tache
        // ne les livre pas. Les declarer en creux serait promettre.
        foreach (['training.progression', 'training.assignments', 'training.quiz'] as $plusTard) {
            $this->assertFalse($registry->exists($plusTard));
        }
    }

    public function test_membership_is_the_only_enrolment(): void
    {
        // Aucune table d'inscription : une seconde verite sur « qui suit la
        // formation » aurait eu a etre gardee d'accord avec l'adhesion.
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('training_enrollments'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('course_enrollments'));

        $this->assertDatabaseHas('loop_members', [
            'loop_id' => $this->loop->id,
            'user_id' => $this->owner->id,
        ]);
    }

    // ── Modules ─────────────────────────────────────────────────────────────

    public function test_modules_are_created_in_order(): void
    {
        foreach (['Premier', 'Deuxieme', 'Troisieme'] as $titre) {
            $this->module($titre);
        }

        $this->assertSame(
            ['Premier', 'Deuxieme', 'Troisieme'],
            CourseModule::where('loop_id', $this->loop->id)->orderBy('position')->pluck('title')->all()
        );
    }

    public function test_a_module_without_a_title_is_refused(): void
    {
        $this->expectException(ValidationException::class);
        $this->service()->createModule($this->loop, $this->owner, '   ');
    }

    public function test_deleting_a_module_never_deletes_referenced_content(): void
    {
        $module = $this->module();
        $article = $this->article('Un Article de cours');

        $this->service()->addSequence($module, $this->owner, 'Lire ceci', reference: $article);
        $this->service()->deleteModule($module);

        $this->assertDatabaseMissing('course_modules', ['id' => $module->id]);
        $this->assertDatabaseCount('course_sequences', 0);
        // L'Article, lui, est intact : un Support de cours est une mise en
        // ordre, pas un coffre.
        $this->assertDatabaseHas('blog_posts', ['id' => $article->id, 'title' => 'Un Article de cours']);
    }

    public function test_reordering_modules_refuses_a_stale_list(): void
    {
        $a = $this->module('A');
        $b = $this->module('B');
        $this->module('C');

        $this->expectException(ValidationException::class);
        // Liste incomplete : appliquer la moitie d'un ordre est pire que le
        // refuser.
        $this->service()->reorderModules($this->loop, [$b->id, $a->id]);
    }

    // ── Sequences ───────────────────────────────────────────────────────────

    public function test_a_sequence_can_carry_text_an_article_or_a_file(): void
    {
        $module = $this->module();

        $texte = $this->service()->addSequence($module, $this->owner, 'Introduction', body: '<p>Bonjour</p>');
        $article = $this->service()->addSequence($module, $this->owner, 'A lire', reference: $this->article('Support'));
        $fichier = $this->service()->addSequence($module, $this->owner, 'A telecharger', reference: $this->fichier('cours.pdf'));

        $this->assertSame(CourseSequence::TYPE_TEXT, $texte->contentType());
        $this->assertSame(CourseSequence::TYPE_ARTICLE, $article->contentType());
        $this->assertSame(CourseSequence::TYPE_FILE, $fichier->contentType());
    }

    public function test_a_referencing_sequence_never_copies_the_original(): void
    {
        $module = $this->module();
        $article = $this->article('Titre d origine');

        $sequence = $this->service()->addSequence($module, $this->owner, 'Renvoi', reference: $article);

        // Aucun texte recopie : la Sequence pointe, elle ne duplique pas.
        $this->assertNull($sequence->body);

        // Et quand l'original change, le renvoi suit — parce qu'il n'y a rien
        // a garder d'accord.
        $article->update(['title' => 'Titre corrige']);

        $this->assertSame('Titre corrige', $sequence->fresh()->blogPost->title);
    }

    public function test_a_reference_from_another_organization_is_refused(): void
    {
        $module = $this->module();

        $autreOrg = Organization::factory()->create(['is_active' => true]);
        $etranger = BlogPost::create([
            'organization_id' => $autreOrg->id,
            'user_id' => User::factory()->create(['organization_id' => $autreOrg->id])->id,
            'title' => 'Ailleurs',
            'content' => 'c',
            'status' => 'draft',
        ]);

        $this->expectException(ValidationException::class);
        $this->service()->addSequence($module, $this->owner, 'Vol', reference: $etranger);
    }

    public function test_deleting_a_sequence_leaves_the_reference_alone(): void
    {
        $module = $this->module();
        $fichier = $this->fichier('a-garder.pdf');

        $sequence = $this->service()->addSequence($module, $this->owner, 'Renvoi', reference: $fichier);
        $this->service()->deleteSequence($sequence);

        $this->assertDatabaseMissing('course_sequences', ['id' => $sequence->id]);
        $this->assertDatabaseHas('dossier_files', ['id' => $fichier->id]);
    }

    // ── Numerotation calculee ───────────────────────────────────────────────

    public function test_sequence_numbers_are_computed_and_never_written(): void
    {
        $module = $this->module();

        foreach (['Un', 'Deux', 'Trois'] as $titre) {
            $this->service()->addSequence($module, $this->owner, $titre);
        }

        $avant = CourseSequence::orderBy('id')->pluck('title')->all();

        $this->assertSame(
            ['01 Un', '02 Deux', '03 Trois'],
            $module->fresh()->numberedSequences()->map(fn ($e) => $e['number'].' '.$e['sequence']->title)->all()
        );

        $ids = $module->fresh()->sequences->pluck('id')->all();
        $this->service()->reorderSequences($module, array_reverse($ids));

        // Les numeros suivent le nouvel ordre…
        $this->assertSame(
            ['01 Trois', '02 Deux', '03 Un'],
            $module->fresh()->numberedSequences()->map(fn ($e) => $e['number'].' '.$e['sequence']->title)->all()
        );
        // …et pas un seul titre n'a bouge.
        $this->assertSame($avant, CourseSequence::orderBy('id')->pluck('title')->all());
    }

    public function test_removing_a_sequence_renumbers_without_writing(): void
    {
        $module = $this->module();

        foreach (['Un', 'Deux', 'Trois'] as $titre) {
            $this->service()->addSequence($module, $this->owner, $titre);
        }

        $avant = CourseSequence::orderBy('id')->pluck('title')->all();
        $milieu = $module->fresh()->sequences->get(1);

        $this->service()->deleteSequence($milieu);

        $this->assertSame(
            ['01 Un', '02 Trois'],
            $module->fresh()->numberedSequences()->map(fn ($e) => $e['number'].' '.$e['sequence']->title)->all()
        );
        $this->assertSame(
            array_values(array_diff($avant, ['Deux'])),
            CourseSequence::orderBy('id')->pluck('title')->all()
        );
    }

    // ── Cloisonnement ───────────────────────────────────────────────────────

    public function test_every_row_carries_its_organization(): void
    {
        $module = $this->module();
        $sequence = $this->service()->addSequence($module, $this->owner, 'Une Sequence');

        // Le cloisonnement est porte par la ligne, pas deduit de la Boucle :
        // une jointure oubliee ne doit pas ouvrir une autre Organization.
        $this->assertSame($this->org->id, $module->organization_id);
        $this->assertSame($this->org->id, $sequence->organization_id);
    }


    // ── Le rendu ────────────────────────────────────────────────────────────

    public function test_the_card_renders_and_offers_keyboard_reordering(): void
    {
        $module = $this->module('Module rendu');
        $this->service()->addSequence($module, $this->owner, 'Sequence A');
        $this->service()->addSequence($module, $this->owner, 'Sequence B');

        \Livewire\Livewire::actingAs($this->owner)
            ->test(\App\Livewire\LoopCourseMaterialCard::class, ['loop' => $this->loop])
            ->assertOk()
            ->assertSee('Module rendu')
            ->assertSee('Sequence A')
            ->assertSee('Sequence B')
            // Des boutons nommes, atteignables au clavier : c'est le chemin de
            // premiere classe, pas un repli.
            // `e()` : Blade echappe l'apostrophe de « d'un rang » dans
            // l'attribut `aria-label`.
            ->assertSee(e(__('loops.cards.course_material.move_down', ['name' => 'Sequence A'])), false)
            ->assertSee(e(__('loops.cards.course_material.move_up', ['name' => 'Sequence B'])), false);
    }

    public function test_moving_a_sequence_from_the_card_changes_the_order(): void
    {
        $module = $this->module();
        $a = $this->service()->addSequence($module, $this->owner, 'Premiere');
        $this->service()->addSequence($module, $this->owner, 'Seconde');

        \Livewire\Livewire::actingAs($this->owner)
            ->test(\App\Livewire\LoopCourseMaterialCard::class, ['loop' => $this->loop])
            ->call('moveSequence', $a->id, 1);

        $this->assertSame(
            ['Seconde', 'Premiere'],
            $module->fresh()->sequences->pluck('title')->all()
        );
    }

    public function test_a_non_member_can_neither_read_nor_write_through_the_card(): void
    {
        $module = $this->module();
        $etranger = User::factory()->create(['organization_id' => $this->org->id]);

        $this->assertFalse(
            app(\App\Support\Loops\LoopPermissionResolver::class)
                ->can($etranger, $this->loop, 'course_material.manage')
        );

        \Livewire\Livewire::actingAs($etranger)
            ->test(\App\Livewire\LoopCourseMaterialCard::class, ['loop' => $this->loop])
            ->call('deleteModule', $module->id)
            ->assertForbidden();

        $this->assertDatabaseHas('course_modules', ['id' => $module->id]);
    }

    public function test_a_module_of_another_loop_is_never_reachable(): void
    {
        $autre = $this->trainingLoop('Autre formation');
        $moduleVoisin = $this->service()->createModule($autre, $this->owner, 'Chez le voisin');

        // L'identifiant est reel et la personne a tous les droits dans sa
        // Boucle : c'est la contrainte sur `loop_id` qui refuse, pas le droit.
        \Livewire\Livewire::actingAs($this->owner)
            ->test(\App\Livewire\LoopCourseMaterialCard::class, ['loop' => $this->loop])
            ->call('deleteModule', $moduleVoisin->id)
            ->assertNotFound();

        $this->assertDatabaseHas('course_modules', ['id' => $moduleVoisin->id]);
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    private function article(string $titre): BlogPost
    {
        return BlogPost::create([
            'organization_id' => $this->org->id,
            'user_id' => $this->owner->id,
            'title' => $titre,
            'content' => 'Contenu.',
            'status' => 'draft',
        ]);
    }

    private function fichier(string $nom): DossierFile
    {
        $dossier = Dossier::create([
            'organization_id' => $this->org->id,
            'owner_id' => $this->owner->id,
            'name' => 'Dossier de la formation',
            'visibility' => Dossier::VISIBILITY_PRIVATE,
        ]);

        return DossierFile::create([
            'organization_id' => $this->org->id,
            'dossier_id' => $dossier->id,
            'uploaded_by' => $this->owner->id,
            'disk' => 'local',
            'path' => 'dossiers/'.$dossier->id.'/'.$nom,
            'original_name' => $nom,
            'display_name' => $nom,
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'checksum_sha256' => hash('sha256', $nom.Str::random(6)),
            'source' => 'upload',
        ]);
    }
}
