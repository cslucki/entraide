<?php

namespace Tests\Feature;

use App\Livewire\LoopRoadmapCard;
use App\Models\Loop;
use App\Models\LoopCard;
use App\Models\LoopMember;
use App\Models\LoopRoadmapItem;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * La Roadmap interrogeait `loop_members` **une fois par item**.
 *
 * Mesure d'origine : 21 requetes pour 2 items, 59 pour 40. La forme est celle
 * que l'invariant de performance interdit — un cout qui suit le nombre
 * d'elements a l'ecran.
 *
 * La cause : `render()` calcule `canModify()` pour chaque item, et `canModify()`
 * appelle `activeMembership()` **deux fois** — une par `canWrite()`, une par
 * `isPrivileged()`.
 *
 * **La memoisation ne doit pas devenir une capacite durable.** Elle vit dans une
 * propriete privee : Livewire ne serialise que les proprietes publiques, donc
 * elle ne voyage pas dans le snapshot et se reconstruit a chaque requete. Un
 * changement d'adhesion est visible au rendu suivant, et le seul geste de ce
 * composant qui touche une adhesion l'invalide explicitement.
 */
class TASK1109RoadmapMembershipCostTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $animateur;

    private User $membre;

    private Loop $loop;

    private LoopService $loops;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['is_active' => true, 'loops_enabled' => true]);
        $this->animateur = User::factory()->create(['organization_id' => $this->org->id]);
        $this->membre = User::factory()->create(['organization_id' => $this->org->id]);

        app()->instance('current_organization', $this->org);

        $this->loops = new LoopService;
        $this->loop = $this->loops->createLoop($this->animateur, 'Ma Boucle')->fresh();
        $this->loops->addMember($this->loop, $this->membre, 'member');

        LoopCard::firstOrCreate(
            ['loop_id' => $this->loop->id, 'card_key' => 'core.roadmap'],
            ['organization_id' => $this->org->id, 'enabled' => true],
        );
    }

    private function items(int $combien): void
    {
        LoopRoadmapItem::where('loop_id', $this->loop->id)->forceDelete();

        for ($i = 0; $i < $combien; $i++) {
            LoopRoadmapItem::create([
                'organization_id' => $this->org->id,
                'loop_id' => $this->loop->id,
                'title' => "action {$i}",
                'status' => [LoopRoadmapItem::STATUS_TODO, LoopRoadmapItem::STATUS_IN_PROGRESS, LoopRoadmapItem::STATUS_DONE][$i % 3],
                'position' => $i,
                'created_by' => $i % 2 === 0 ? $this->animateur->id : $this->membre->id,
            ]);
        }
    }

    /** Le rendu **complet** du composant, et les requetes qu'il coute. */
    private function coutDuRendu(int $items, ?User $qui = null): int
    {
        $this->items($items);

        DB::enableQueryLog();
        // `flushQueryLog` et non `disableQueryLog` : celui-ci ne vide pas le
        // journal, et la seconde mesure compterait les requetes de la premiere.
        DB::flushQueryLog();

        Livewire::actingAs($qui ?? $this->animateur)
            ->test(LoopRoadmapCard::class, ['loop' => $this->loop])
            ->html();

        $n = count(DB::getQueryLog());
        DB::flushQueryLog();

        return $n;
    }

    /** Combien de fois `loop_members` est interroge pendant un rendu. */
    private function requetesMembres(int $items, ?User $qui = null): int
    {
        $this->items($items);

        DB::enableQueryLog();
        DB::flushQueryLog();

        Livewire::actingAs($qui ?? $this->animateur)
            ->test(LoopRoadmapCard::class, ['loop' => $this->loop])
            ->html();

        $n = collect(DB::getQueryLog())
            ->filter(fn ($r) => str_contains($r['query'], 'loop_members'))
            ->count();

        DB::flushQueryLog();

        return $n;
    }

    // ── La sonde de croissance ──────────────────────────────────────────────

    public function test_the_cost_does_not_follow_the_number_of_items(): void
    {
        $petite = $this->coutDuRendu(2);
        $grande = $this->coutDuRendu(40);

        $this->assertLessThanOrEqual(
            $petite + 3,
            $grande,
            "le cout suit le nombre d'items : {$petite} requetes pour 2, {$grande} pour 40",
        );
    }

    public function test_the_membership_is_read_a_bounded_number_of_times(): void
    {
        // C'est la requete nommee dans la dette : une par item, deux fois meme,
        // `canModify()` passant par `canWrite()` **et** `isPrivileged()`.
        $petite = $this->requetesMembres(2);
        $grande = $this->requetesMembres(40);

        $this->assertSame(
            $petite,
            $grande,
            "`loop_members` est lu {$petite} fois pour 2 items et {$grande} fois pour 40",
        );
    }

    public function test_a_plain_member_pays_no_more_than_the_animator(): void
    {
        // Un membre ordinaire ne peut modifier que les siens : `canModify()`
        // appelle alors les deux gardes pour chaque item.
        $petite = $this->requetesMembres(2, $this->membre);
        $grande = $this->requetesMembres(40, $this->membre);

        $this->assertSame($petite, $grande);
    }

    // ── La memoisation ne devient pas une capacite durable ──────────────────

    public function test_the_cache_never_travels_in_the_livewire_snapshot(): void
    {
        // Une propriete publique voyage dans le snapshot, qui n'a ni nonce ni
        // expiration : une adhesion memoisee y deviendrait un droit durable,
        // rejouable apres un retrait.
        $reflet = new \ReflectionClass(LoopRoadmapCard::class);

        foreach ($reflet->getProperties(\ReflectionProperty::IS_PUBLIC) as $propriete) {
            $this->assertStringNotContainsStringIgnoringCase(
                'membership',
                $propriete->getName(),
                "la propriete publique « {$propriete->getName()} » porterait l'adhesion dans le snapshot",
            );
        }
    }

    public function test_losing_membership_is_visible_on_the_next_render(): void
    {
        // C'est la garantie qui compte : la memoisation dure **un rendu**, pas
        // une session.
        $this->items(3);

        $avant = Livewire::actingAs($this->membre)
            ->test(LoopRoadmapCard::class, ['loop' => $this->loop])
            ->html();

        $this->assertStringContainsString('action 0', $avant);

        LoopMember::where('loop_id', $this->loop->id)
            ->where('user_id', $this->membre->id)
            ->update(['status' => 'left']);

        $apres = Livewire::actingAs($this->membre)
            ->test(LoopRoadmapCard::class, ['loop' => $this->loop]);

        // Le geste d'ecriture est refuse des le rendu suivant.
        $apres->set('newTitle', 'Apres le depart')->call('createAction');

        $this->assertDatabaseMissing('loop_roadmap_items', ['title' => 'Apres le depart']);
    }

    public function test_gaining_membership_is_visible_on_the_next_render(): void
    {
        $etranger = User::factory()->create(['organization_id' => $this->org->id]);

        Livewire::actingAs($etranger)
            ->test(LoopRoadmapCard::class, ['loop' => $this->loop])
            ->set('newTitle', 'Par effraction')
            ->call('createAction');

        $this->assertDatabaseMissing('loop_roadmap_items', ['title' => 'Par effraction']);

        $this->loops->addMember($this->loop, $etranger, 'member');

        Livewire::actingAs($etranger)
            ->test(LoopRoadmapCard::class, ['loop' => $this->loop])
            ->set('newTitle', 'Maintenant membre')
            ->call('createAction');

        $this->assertDatabaseHas('loop_roadmap_items', ['title' => 'Maintenant membre']);
    }

    public function test_adding_a_member_during_the_request_is_not_masked_by_the_cache(): void
    {
        // Le seul geste de ce composant qui touche une adhesion. Le cache doit
        // etre relache, sinon la suite de la meme requete lirait un etat perime.
        $this->items(1);
        $item = LoopRoadmapItem::where('loop_id', $this->loop->id)->firstOrFail();

        $candidat = User::factory()->create(['organization_id' => $this->org->id]);

        Livewire::actingAs($this->animateur)
            ->test(LoopRoadmapCard::class, ['loop' => $this->loop])
            ->call('assignAndAddMember', $item->id, $candidat->id);

        $this->assertDatabaseHas('loop_members', [
            'loop_id' => $this->loop->id,
            'user_id' => $candidat->id,
            'status' => 'active',
        ]);
    }


    // ── La meme forme, ailleurs ─────────────────────────────────────────────

    public function test_the_decisions_supersede_picker_costs_the_same_at_any_size(): void
    {
        // **Le meme defaut, dans la Card Decisions.** `supersedable()` filtre
        // avec `canEdit()` par ligne, qui appelle deux fois le resolveur — et
        // celui-ci interroge `loop_members` a chaque appel, sans cache et sans
        // liaison en singleton.
        //
        // Mesure d'origine, selecteur ouvert : 14 lectures pour 2 Decisions,
        // **90 pour 40**. La sonde de croissance de TASK-1106 ne l'avait pas
        // vu : elle n'ouvrait jamais le selecteur, et mesurait donc un chemin
        // ou le defaut n'existe pas.
        LoopCard::firstOrCreate(
            ['loop_id' => $this->loop->id, 'card_key' => 'core.decisions'],
            ['organization_id' => $this->org->id, 'enabled' => true],
        );

        $service = app(\App\Services\Loops\LoopDecisionService::class);

        $mesurer = function (int $combien) use ($service): int {
            \App\Models\LoopDecision::where('loop_id', $this->loop->id)->delete();

            $premier = null;
            for ($i = 0; $i < $combien; $i++) {
                $d = $service->record($this->loop, $this->animateur, "choix {$i}");
                $premier ??= $d->id;
            }

            DB::enableQueryLog();
            DB::flushQueryLog();

            Livewire::actingAs($this->animateur)
                ->test(\App\Livewire\LoopDecisionsCard::class, ['loop' => $this->loop])
                ->call('startSuperseding', $premier)
                ->html();

            $n = collect(DB::getQueryLog())
                ->filter(fn ($r) => str_contains($r['query'], 'loop_members'))
                ->count();

            DB::flushQueryLog();

            return $n;
        };

        $petite = $mesurer(2);
        $grande = $mesurer(40);

        $this->assertSame(
            $petite,
            $grande,
            "le selecteur de remplacement lit `loop_members` {$petite} fois pour 2 Decisions et {$grande} fois pour 40",
        );
    }

    // ── Rien d'autre ne bouge ───────────────────────────────────────────────

    public function test_an_archived_loop_still_refuses_writes(): void
    {
        $this->items(2);
        $this->loop->forceFill(['status' => 'archived', 'archived_at' => now()])->save();

        Livewire::actingAs($this->animateur)
            ->test(LoopRoadmapCard::class, ['loop' => $this->loop->fresh()])
            ->set('newTitle', 'Trop tard')
            ->call('createAction');

        $this->assertDatabaseMissing('loop_roadmap_items', ['title' => 'Trop tard']);
    }

    public function test_a_member_of_another_organization_sees_nothing(): void
    {
        $autreOrg = Organization::factory()->create(['is_active' => true]);
        $etranger = User::factory()->create(['organization_id' => $autreOrg->id]);

        $this->items(2);

        Livewire::actingAs($etranger)
            ->test(LoopRoadmapCard::class, ['loop' => $this->loop])
            ->set('newTitle', 'Depuis ailleurs')
            ->call('createAction');

        $this->assertDatabaseMissing('loop_roadmap_items', ['title' => 'Depuis ailleurs']);
    }

    public function test_a_member_still_modifies_only_their_own(): void
    {
        // La regle metier ne doit pas bouger avec la memoisation.
        $this->items(2);

        $sien = LoopRoadmapItem::where('loop_id', $this->loop->id)
            ->where('created_by', $this->membre->id)->firstOrFail();
        $autre = LoopRoadmapItem::where('loop_id', $this->loop->id)
            ->where('created_by', $this->animateur->id)->firstOrFail();

        Livewire::actingAs($this->membre)
            ->test(LoopRoadmapCard::class, ['loop' => $this->loop])
            ->call('archiveItem', $autre->id);

        $this->assertDatabaseHas('loop_roadmap_items', ['id' => $autre->id, 'deleted_at' => null]);

        Livewire::actingAs($this->membre)
            ->test(LoopRoadmapCard::class, ['loop' => $this->loop])
            ->call('archiveItem', $sien->id);

        $this->assertSoftDeleted('loop_roadmap_items', ['id' => $sien->id]);
    }
}
