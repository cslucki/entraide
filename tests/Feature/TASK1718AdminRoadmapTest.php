<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopRoadmapItem;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1718 volet 2 — la Roadmap de toutes les Organizations.
 *
 * ## Ce que les gardes mesurent, et ce qu'elles ne peuvent PAS mesurer
 *
 * `/admin` est une surface PLATEFORME, gardee par `is_admin`. La lecon de
 * TASK-1678 vaut donc ici : **un test d'isolation de tenant pose sur `/admin`
 * ne mesure rien** — l'ecran est fait pour voir au-dessus des tenants.
 *
 * Les gardes portent donc sur ce qui a un sens :
 *
 *  - le REFUS D'ENTRER pour qui n'est pas SuperAdmin — la seule frontiere
 *    reelle de cet ecran ;
 *  - la LEVEE EXPLICITE du scope de tenant : sans elle, l'ecran ne montrerait
 *    que l'Organization courante, en silence, et le SuperAdmin croirait la
 *    plateforme vide ;
 *  - les FILTRES, qui sont la fonction demandee ;
 *  - le fait qu'on ecrive bien dans `LoopRoadmapItem` — pas dans un second
 *    systeme de taches.
 */
class TASK1718AdminRoadmapTest extends TestCase
{
    use RefreshDatabase;

    private Organization $orgA;

    private Organization $orgB;

    private User $superadmin;

    private Loop $loopA;

    private Loop $loopB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create(['name' => 'Org A', 'is_default' => true]);
        $this->orgB = Organization::factory()->create(['name' => 'Org B']);
        app()->instance('current_organization', $this->orgA);

        $this->superadmin = User::factory()->create([
            'organization_id' => $this->orgA->id, 'is_admin' => true,
            'email_verified_at' => now(), 'approved_at' => now(),
        ]);

        $a = User::factory()->create(['organization_id' => $this->orgA->id]);
        $this->loopA = (new LoopService)->createLoop($a, 'Boucle A');

        $b = User::factory()->create(['organization_id' => $this->orgB->id]);
        $this->loopB = (new LoopService)->createLoopForOrg($b, $this->orgB->id, 'Boucle B');
    }

    private function tache(Loop $loop, string $titre): LoopRoadmapItem
    {
        return LoopRoadmapItem::create([
            'organization_id' => $loop->organization_id,
            'loop_id' => $loop->id,
            'title' => $titre,
            'status' => LoopRoadmapItem::STATUS_TODO,
            'position' => 0,
            'created_by' => $loop->created_by,
        ]);
    }

    // ───────────────────────────────────────────────────────────────────────
    // 1. La seule frontiere reelle : entrer
    // ───────────────────────────────────────────────────────────────────────

    public function test_un_membre_simple_ne_peut_pas_ouvrir_l_ecran(): void
    {
        $simple = User::factory()->create([
            'organization_id' => $this->orgA->id,
            'email_verified_at' => now(), 'approved_at' => now(),
        ]);

        $this->actingAs($simple)->get('/admin/roadmap')->assertStatus(403);
    }

    public function test_un_membre_simple_ne_peut_ni_modifier_ni_supprimer(): void
    {
        $tache = $this->tache($this->loopA, 'Intacte');
        $simple = User::factory()->create([
            'organization_id' => $this->orgA->id,
            'email_verified_at' => now(), 'approved_at' => now(),
        ]);

        $this->actingAs($simple)
            ->put(route('admin.roadmap.update', $tache->id), ['title' => 'Vole', 'status' => 'done'])
            ->assertStatus(403);
        $this->actingAs($simple)
            ->delete(route('admin.roadmap.destroy', $tache->id))
            ->assertStatus(403);

        $this->assertSame('Intacte', $tache->fresh()->title);
    }

    // ───────────────────────────────────────────────────────────────────────
    // 2. La levee du scope : voir AU-DESSUS des tenants
    // ───────────────────────────────────────────────────────────────────────

    public function test_l_ecran_montre_les_taches_des_DEUX_organizations(): void
    {
        $this->tache($this->loopA, 'Tache de A');
        $this->tache($this->loopB, 'Tache de B');

        // `current_organization` est A : sans la levee EXPLICITE du scope, la
        // tache de B serait absente, en silence, et l'ecran mentirait.
        $html = $this->actingAs($this->superadmin)->get('/admin/roadmap')->assertOk()->getContent();

        $this->assertStringContainsString('Tache de A', $html);
        $this->assertStringContainsString('Tache de B', $html);
    }

    // ───────────────────────────────────────────────────────────────────────
    // 3. Les filtres, qui sont la fonction demandee
    // ───────────────────────────────────────────────────────────────────────

    public function test_le_filtre_par_organization_restreint_la_liste(): void
    {
        $deA = $this->tache($this->loopA, 'Tache de A');
        $deB = $this->tache($this->loopB, 'Tache de B');

        // La LIGNE, pas la page : le `<select>` des filtres contient les noms
        // des deux Organizations, donc `assertDontSee('Org A')` serait faux
        // malgre un filtre correct.
        $html = $this->actingAs($this->superadmin)
            ->get('/admin/roadmap?organization_id='.$this->orgB->id)->assertOk()->getContent();

        $this->assertStringContainsString('data-roadmap-row="'.$deB->id.'"', $html);
        $this->assertStringNotContainsString('data-roadmap-row="'.$deA->id.'"', $html);
    }

    public function test_le_filtre_par_boucle_restreint_la_liste(): void
    {
        $uneA = $this->tache($this->loopA, 'Premiere');
        $deB = $this->tache($this->loopB, 'Ailleurs');

        $html = $this->actingAs($this->superadmin)
            ->get('/admin/roadmap?loop_id='.$this->loopA->id)->assertOk()->getContent();

        $this->assertStringContainsString('data-roadmap-row="'.$uneA->id.'"', $html);
        $this->assertStringNotContainsString('data-roadmap-row="'.$deB->id.'"', $html);
    }

    // ───────────────────────────────────────────────────────────────────────
    // 4. On ecrit dans le MEME systeme de taches
    // ───────────────────────────────────────────────────────────────────────

    public function test_le_superadmin_modifie_une_tache_d_une_autre_organization(): void
    {
        $tache = $this->tache($this->loopB, 'Titre initial');

        $this->actingAs($this->superadmin)
            ->put(route('admin.roadmap.update', $tache->id), [
                'title' => 'Titre corrige',
                'status' => LoopRoadmapItem::STATUS_DONE,
            ])->assertRedirect();

        $tache->refresh();
        $this->assertSame('Titre corrige', $tache->title);
        $this->assertSame(LoopRoadmapItem::STATUS_DONE, $tache->status);
        // La tache reste DANS sa Boucle : l'ecran corrige, il ne deplace pas.
        $this->assertSame($this->loopB->id, $tache->loop_id);
        $this->assertSame($this->orgB->id, $tache->organization_id);
    }

    public function test_le_superadmin_supprime_une_tache_d_une_autre_organization(): void
    {
        $tache = $this->tache($this->loopB, 'A supprimer');

        $this->actingAs($this->superadmin)
            ->delete(route('admin.roadmap.destroy', $tache->id))
            ->assertRedirect();

        $this->assertNull(LoopRoadmapItem::withoutGlobalScopes()->find($tache->id));
    }

    public function test_un_titre_vide_est_refuse(): void
    {
        $tache = $this->tache($this->loopB, 'Titre initial');

        $this->actingAs($this->superadmin)
            ->put(route('admin.roadmap.update', $tache->id), ['title' => '', 'status' => 'todo'])
            ->assertSessionHasErrors('title');

        $this->assertSame('Titre initial', $tache->fresh()->title);
    }
}
