<?php

namespace Tests\Feature;

use App\Livewire\LoopRoadmapCard;
use App\Models\Loop;
use App\Models\LoopCard;
use App\Models\LoopRoadmapItem;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1718 volet 1 — modifier le titre d'une tache de Roadmap.
 *
 * ## Ce que l'audit a trouve
 *
 * `startEdit()`, `saveEdit()` et `cancelEdit()` EXISTAIENT dans
 * `LoopRoadmapCard`, avec leur garde `canModify()` — et cette garde portait
 * deja, mot pour mot, la regle demandee par MASTER :
 *
 *     canWrite() && (created_by === moi || isPrivileged())
 *
 * c'est-a-dire l'auteur de la tache OU le proprietaire de la Boucle. Mais ces
 * trois methodes n'avaient AUCUNE interface : du code mort depuis que le
 * panneau de detail a repris le sujet.
 *
 * La TASK rebranche l'existant — une entree de menu et un formulaire en
 * place — plutot que d'ouvrir une seconde voie d'enregistrement. Aucune
 * nouvelle methode, aucune nouvelle clef de traduction : `roadmap_edit`,
 * `roadmap_save` et `cancel` existaient toutes les trois.
 *
 * ## Ce que les gardes mesurent
 *
 * Les DEUX autorisations demandees, et surtout le REFUS qui leur donne leur
 * sens : un membre simple qui n'est pas l'auteur ne doit pas pouvoir
 * renommer. Sans ce refus, ouvrir la modification a tout le monde passerait
 * les deux premieres gardes.
 */
class TASK1718RoadmapItemEditTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organisation;

    private User $proprietaire;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organisation = Organization::factory()->create(['is_default' => true]);
        app()->instance('current_organization', $this->organisation);

        $this->proprietaire = User::factory()->create(['organization_id' => $this->organisation->id]);
        $this->loop = (new LoopService)->createLoop($this->proprietaire, 'Boucle Roadmap');

        // La carte Roadmap est ACTIVEE explicitement, et c'est une correction
        // de banc, pas de produit : le preset du type `general` ne la contient
        // pas (mesure : `core.ai_summary`, `polls`, `events`, `dossiers`,
        // `members`). Or `roadmap.manage` declare `requires_card =>
        // core.roadmap`, donc sans elle le proprietaire n'est pas privilegie
        // et la garde mesurerait l'absence de la carte au lieu de la regle de
        // modification. Une Boucle qui a reellement une Roadmap l'a active.
        LoopCard::updateOrCreate(
            ['loop_id' => $this->loop->id, 'card_key' => 'core.roadmap'],
            ['organization_id' => $this->loop->organization_id, 'enabled' => true],
        );
    }

    private function membre(): User
    {
        $u = User::factory()->create(['organization_id' => $this->organisation->id]);
        app(LoopService::class)->addMember($this->loop, $u, 'member');

        return $u;
    }

    private function tache(User $auteur, string $titre = 'Titre initial'): LoopRoadmapItem
    {
        return LoopRoadmapItem::create([
            'organization_id' => $this->loop->organization_id,
            'loop_id' => $this->loop->id,
            'title' => $titre,
            'status' => LoopRoadmapItem::STATUS_TODO,
            'position' => 0,
            'created_by' => $auteur->id,
        ]);
    }

    private function carte(User $acteur)
    {
        return Livewire::actingAs($acteur)->test(LoopRoadmapCard::class, ['loop' => $this->loop]);
    }

    // ───────────────────────────────────────────────────────────────────────
    // 1. Les deux autorisations demandees
    // ───────────────────────────────────────────────────────────────────────

    public function test_le_proprietaire_de_la_boucle_modifie_le_titre(): void
    {
        $auteur = $this->membre();
        $tache = $this->tache($auteur);

        $this->carte($this->proprietaire)
            ->call('startEdit', $tache->id)
            ->set('editingTitle', 'Titre corrige')
            ->call('saveEdit');

        $this->assertSame('Titre corrige', $tache->fresh()->title);
    }

    public function test_l_auteur_de_la_tache_modifie_son_titre(): void
    {
        $auteur = $this->membre();
        $tache = $this->tache($auteur);

        $this->carte($auteur)
            ->call('startEdit', $tache->id)
            ->set('editingTitle', 'Titre corrige')
            ->call('saveEdit');

        $this->assertSame('Titre corrige', $tache->fresh()->title);
    }

    // ───────────────────────────────────────────────────────────────────────
    // 2. Le refus, qui donne son sens aux deux precedentes
    // ───────────────────────────────────────────────────────────────────────

    public function test_un_membre_qui_n_est_pas_l_auteur_ne_modifie_pas(): void
    {
        $auteur = $this->membre();
        $autre = $this->membre();
        $tache = $this->tache($auteur);

        $this->carte($autre)
            ->call('startEdit', $tache->id)
            ->set('editingTitle', 'Titre vole')
            ->call('saveEdit');

        $this->assertSame('Titre initial', $tache->fresh()->title);
    }

    public function test_un_titre_vide_est_refuse_et_ne_vide_pas_la_tache(): void
    {
        $tache = $this->tache($this->proprietaire);

        $this->carte($this->proprietaire)
            ->call('startEdit', $tache->id)
            ->set('editingTitle', '   ')
            ->call('saveEdit')
            ->assertSet('errorMessage', __('loops.roadmap_title_required'));

        $this->assertSame('Titre initial', $tache->fresh()->title);
    }

    // ───────────────────────────────────────────────────────────────────────
    // 3. L'entree de menu, et les deux vues conservees
    // ───────────────────────────────────────────────────────────────────────

    public function test_l_entree_modifier_n_apparait_que_pour_qui_peut_modifier(): void
    {
        $auteur = $this->membre();
        $autre = $this->membre();
        $tache = $this->tache($auteur);

        // Le marqueur porte l'identifiant : l'assertion est ATTRIBUEE a CETTE
        // tache, et non a la presence du mot « Modifier » quelque part.
        $this->carte($auteur)->assertSee('data-roadmap-edit="'.$tache->id.'"', false);
        $this->carte($autre)->assertDontSee('data-roadmap-edit="'.$tache->id.'"', false);
    }

    public function test_le_formulaire_en_place_s_ouvre_dans_les_deux_vues(): void
    {
        // `roadmap-item.blade.php` est le partiel PARTAGE par la Liste et le
        // Kanban : le formulaire y vit une seule fois, donc les deux vues le
        // recoivent par construction. La garde verifie qu'il apparait bien, et
        // seulement pendant l'edition.
        $tache = $this->tache($this->proprietaire);

        $carte = $this->carte($this->proprietaire);
        $carte->assertDontSee('data-marqueur="roadmap-edition-titre"', false);

        $carte->call('startEdit', $tache->id)
            ->assertSee('data-marqueur="roadmap-edition-titre"', false);
    }
}
