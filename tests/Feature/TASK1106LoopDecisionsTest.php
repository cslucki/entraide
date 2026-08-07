<?php

namespace Tests\Feature;

use App\Livewire\LoopDecisionsCard;
use App\Models\Loop;
use App\Models\LoopCard;
use App\Models\LoopDecision;
use App\Models\LoopMessage;
use App\Models\LoopRoadmapItem;
use App\Models\Organization;
use App\Models\User;
use App\Services\Loops\LoopDecisionService;
use App\Services\LoopService;
use App\Support\Loops\LoopCardRegistry;
use App\Support\Loops\LoopTypeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Les Decisions d'une Boucle.
 *
 * La matrice produit les donne au Projet, aux cotes de la Roadmap et des
 * Dossiers. Le North Star nomme la perte qu'elles adressent — « une decision
 * n'est pas transformee en action » — et la regle qui les gouverne :
 * « l'humain reste responsable des decisions durables ».
 *
 * Les cinq invariants de la serie sont couverts, en plus des regles metier :
 *
 * 1. une mutation n'est jamais protegee par une capacite de lecture ;
 * 2. une permission ne suffit pas — la transition doit etre valide ;
 * 3. le cout SQL ne croit pas avec le nombre de Decisions ;
 * 4. une capacite = un backend **et** un chemin — refus, succes, ecran ;
 * 5. rien ne se fige : retirer ou remplacer n'annule pas l'acquis.
 */
class TASK1106LoopDecisionsTest extends TestCase
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

        $this->org = Organization::factory()->create(['is_active' => true]);
        $this->animateur = User::factory()->create(['organization_id' => $this->org->id]);
        $this->membre = User::factory()->create(['organization_id' => $this->org->id]);

        app()->instance('current_organization', $this->org);

        $this->loops = new LoopService;
        $this->loop = $this->loops->createLoop($this->animateur, 'Ma Boucle')->fresh();
        $this->loops->addMember($this->loop, $this->membre, 'member');

        // Les Decisions ne sont pas dans le preset `general` : on les active.
        LoopCard::firstOrCreate(
            ['loop_id' => $this->loop->id, 'card_key' => 'core.decisions'],
            ['organization_id' => $this->org->id, 'enabled' => true],
        );

        // La Roadmap sert aux actions engagees.
        LoopCard::firstOrCreate(
            ['loop_id' => $this->loop->id, 'card_key' => 'core.roadmap'],
            ['organization_id' => $this->org->id, 'enabled' => true],
        );
    }

    private function service(): LoopDecisionService
    {
        return app(LoopDecisionService::class);
    }

    private function card(?User $as = null)
    {
        return Livewire::actingAs($as ?? $this->animateur)->test(LoopDecisionsCard::class, ['loop' => $this->loop]);
    }

    private function message(string $corps): LoopMessage
    {
        return LoopMessage::create([
            'organization_id' => $this->org->id,
            'loop_id' => $this->loop->id,
            'sender_id' => $this->membre->id,
            'body' => $corps,
        ]);
    }

    private function decision(string $titre, ?User $qui = null, ?string $date = null): LoopDecision
    {
        return $this->service()->record($this->loop, $qui ?? $this->animateur, $titre, null, $date);
    }

    // ── La Card et sa place dans la matrice ─────────────────────────────────

    public function test_the_card_exists_and_belongs_to_the_project_preset(): void
    {
        $this->assertTrue(app(LoopCardRegistry::class)->exists('core.decisions'));

        // La matrice produit : « Projet | Manifeste · Membres | Roadmap ·
        // Decisions · Dossiers ».
        $this->assertContains('core.decisions', app(LoopTypeRegistry::class)->cardsFor('project'));
    }

    public function test_the_card_depends_on_nothing(): void
    {
        // Le lien vers la Roadmap est un service rendu quand elle est la, pas
        // une dependance : une Boucle qui n'a que les Decisions les consigne.
        $this->assertSame([], app(LoopCardRegistry::class)->get('core.decisions')['requires']);
    }

    public function test_its_order_is_unique_among_every_card(): void
    {
        $ordres = collect(config('loop_cards.cards'))->pluck('order');

        $this->assertSame($ordres->count(), $ordres->unique()->count(), 'deux Cards partagent un ordre');
    }

    public function test_no_second_task_system_is_created(): void
    {
        // Une action nee d'une Decision est un item de Roadmap ordinaire.
        foreach (['loop_decision_actions', 'loop_tasks', 'decision_actions'] as $interdite) {
            $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable($interdite));
        }
    }

    // ── Invariant 1 : une ecriture n'est pas protegee par une lecture ───────

    public function test_recording_carries_no_read_flag(): void
    {
        // C'est ce qui fait qu'une Boucle archivee la refuse.
        // `config()` coupe sur les points : la cle est litteralement
        // « decisions.view », pas un chemin imbrique.
        $permissions = config('loop_permissions.permissions');

        $this->assertTrue($permissions['decisions.view']['read']);
        $this->assertArrayNotHasKey('read', $permissions['decisions.record']);
        $this->assertArrayNotHasKey('read', $permissions['decisions.manage']);
    }

    public function test_an_archived_loop_accepts_no_decision(): void
    {
        $this->loop->forceFill(['status' => 'archived', 'archived_at' => now()])->save();

        $this->card()->set('title', 'Trop tard')->call('save')->assertForbidden();

        $this->assertDatabaseCount('loop_decisions', 0);
    }

    public function test_an_archived_loop_still_reads_its_decisions(): void
    {
        // Boucle archivee = lecture historique, aucune mutation metier.
        $this->decision('Ce qui a ete tranche');
        $this->loop->forceFill(['status' => 'archived', 'archived_at' => now()])->save();

        $this->card()->assertSee('Ce qui a ete tranche')->assertOk();
    }

    public function test_an_archived_loop_starts_no_action(): void
    {
        $decision = $this->decision('Un choix');
        $this->loop->forceFill(['status' => 'archived', 'archived_at' => now()])->save();

        $this->card()->call('startAction', $decision->id)->assertForbidden();
    }

    public function test_an_archived_loop_supersedes_nothing(): void
    {
        $ancienne = $this->decision('Avant');
        $nouvelle = $this->decision('Apres');
        $this->loop->forceFill(['status' => 'archived', 'archived_at' => now()])->save();

        $this->card()->call('startSuperseding', $nouvelle->id)->assertForbidden();

        $this->assertNull($ancienne->fresh()->superseded_by_id);
    }

    // ── Invariant 2 : la transition doit etre valide ────────────────────────

    public function test_a_decision_cannot_supersede_itself(): void
    {
        $decision = $this->decision('Seule');

        $this->expectException(ValidationException::class);
        $this->service()->supersede($decision, $decision);
    }

    public function test_a_decision_cannot_be_superseded_from_another_loop(): void
    {
        $autre = $this->loops->createLoop($this->animateur, 'Autre Boucle')->fresh();
        $ailleurs = $this->service()->record($autre, $this->animateur, 'Ailleurs');

        $ici = $this->decision('Ici');

        $this->expectException(ValidationException::class);
        $this->service()->supersede($ici, $ailleurs);
    }

    public function test_a_decision_is_superseded_only_once(): void
    {
        $ancienne = $this->decision('Avant');
        $this->service()->supersede($ancienne, $this->decision('Apres'));

        $this->expectException(ValidationException::class);
        $this->service()->supersede($ancienne->fresh(), $this->decision('Encore apres'));
    }

    public function test_two_decisions_cannot_supersede_each_other(): void
    {
        // Sinon chacune renvoie a l'autre, et plus rien ne fait foi.
        $a = $this->decision('A');
        $b = $this->decision('B');

        $this->service()->supersede($a, $b);

        $this->expectException(ValidationException::class);
        $this->service()->supersede($b->fresh(), $a->fresh());
    }

    public function test_a_superseded_decision_is_not_edited(): void
    {
        $ancienne = $this->decision('Avant');
        $this->service()->supersede($ancienne, $this->decision('Apres'));

        $this->expectException(ValidationException::class);
        $this->service()->update($ancienne->fresh(), 'Reecrite');
    }

    public function test_a_superseded_decision_starts_no_action(): void
    {
        // Engager une action au nom d'un choix abandonne serait le contraire du
        // service rendu.
        $ancienne = $this->decision('Avant');
        $this->service()->supersede($ancienne, $this->decision('Apres'));

        $this->expectException(ValidationException::class);
        $this->service()->startAction($ancienne->fresh(), $this->animateur, 'Faire quand meme');
    }

    // ── Invariant 5 : rien ne disparait ─────────────────────────────────────

    public function test_a_superseded_decision_stays_readable(): void
    {
        // C'est le point de la Card : effacer ce qui a ete decide avant
        // priverait le collectif de son histoire.
        $ancienne = $this->decision('On part sur Postgres');
        $nouvelle = $this->decision('Finalement SQLite');

        $this->service()->supersede($ancienne, $nouvelle);

        $this->assertDatabaseHas('loop_decisions', ['id' => $ancienne->id, 'title' => 'On part sur Postgres']);
        $this->card()->assertSee('On part sur Postgres')->assertSee('Finalement SQLite');
    }

    public function test_removing_a_decision_keeps_the_actions_it_started(): void
    {
        // Quelqu'un les a peut-etre deja faites.
        $decision = $this->decision('Un choix');
        $action = $this->service()->startAction($decision, $this->animateur, 'Une action');

        $this->service()->delete($decision->fresh());

        $this->assertDatabaseHas('loop_roadmap_items', ['id' => $action->id, 'title' => 'Une action']);
        $this->assertNull($action->fresh()->loop_decision_id);
    }

    public function test_deleting_the_superseding_decision_makes_the_earlier_one_current_again(): void
    {
        // Plutot que de la laisser barree en pointant vers rien.
        $ancienne = $this->decision('Avant');
        $nouvelle = $this->decision('Apres');
        $this->service()->supersede($ancienne, $nouvelle);

        $this->service()->delete($nouvelle->fresh());

        $this->assertFalse($ancienne->fresh()->isSuperseded());
    }

    public function test_removing_a_decision_never_touches_the_promoted_message(): void
    {
        $message = $this->message('On fait comme ça');
        $decision = $this->service()->promote($this->loop, $this->animateur, $message, 'Comme ça');

        $this->service()->delete($decision->fresh());

        $this->assertDatabaseHas('loop_messages', ['id' => $message->id, 'body' => 'On fait comme ça']);
    }

    // ── La promotion ne copie jamais ────────────────────────────────────────

    public function test_promoting_stores_a_reference_and_not_a_copy(): void
    {
        $message = $this->message('Texte d’origine');
        $decision = $this->service()->promote($this->loop, $this->animateur, $message, 'Le choix');

        $this->assertSame($message->id, $decision->loop_message_id);

        // Le message corrige se corrige partout.
        $message->update(['body' => 'Texte corrige']);

        $this->assertSame('Texte corrige', $decision->fresh()->displayMessage());
    }

    public function test_a_moderated_message_stays_moderated_in_the_card(): void
    {
        // Sans cela, retirer un message du ChatLoop ne le retirait pas d'ici, et
        // la moderation devenait reversible pour qui savait ou regarder.
        $message = $this->message('A retirer');
        $decision = $this->service()->promote($this->loop, $this->animateur, $message, 'Le choix');

        $message->update(['deleted_at' => now()]);

        $lue = $this->service()->decisionsFor($this->loop)->firstWhere('id', $decision->id);

        $this->assertSame(__('loops.cards.decisions.message_removed'), $lue->displayMessage());
        $this->card()->assertDontSee('A retirer');
    }

    public function test_a_message_from_another_loop_is_refused(): void
    {
        $autre = $this->loops->createLoop($this->animateur, 'Autre')->fresh();
        $ailleurs = LoopMessage::create([
            'organization_id' => $this->org->id,
            'loop_id' => $autre->id,
            'sender_id' => $this->animateur->id,
            'body' => 'Ailleurs',
        ]);

        $this->expectException(ValidationException::class);
        $this->service()->promote($this->loop, $this->animateur, $ailleurs, 'Le choix');
    }

    public function test_the_same_message_is_never_promoted_twice(): void
    {
        $message = $this->message('Une fois');

        $a = $this->service()->promote($this->loop, $this->animateur, $message, 'Premier titre');
        $b = $this->service()->promote($this->loop, $this->animateur, $message, 'Deuxieme titre');

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, LoopDecision::where('loop_message_id', $message->id)->count());
    }

    public function test_the_database_itself_refuses_a_duplicate_promotion(): void
    {
        // La garde en service ne suffit pas : un `SELECT` puis un `INSERT` sans
        // verrou laisse passer deux ecritures concurrentes. L'unicite est tenue
        // par la base.
        $message = $this->message('Une fois');
        $this->service()->promote($this->loop, $this->animateur, $message, 'Titre');

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        LoopDecision::create([
            'organization_id' => $this->org->id,
            'loop_id' => $this->loop->id,
            'author_id' => $this->animateur->id,
            'title' => 'En double',
            'decided_on' => now()->toDateString(),
            'loop_message_id' => $message->id,
        ]);
    }

    public function test_a_promoted_decision_defaults_to_the_message_date(): void
    {
        // C'est **quand ca s'est decide**, pas quand on l'a remarque.
        $message = $this->message('Il y a longtemps');
        $message->forceFill(['created_at' => now()->subDays(9)])->save();

        $decision = $this->service()->promote($this->loop, $this->animateur, $message->fresh(), 'Le choix');

        $this->assertSame(now()->subDays(9)->toDateString(), $decision->decided_on->toDateString());
    }

    // ── L'action : la perte que la Card evite ───────────────────────────────

    public function test_a_decision_becomes_an_action_in_the_roadmap(): void
    {
        $decision = $this->decision('On migre en septembre');

        $action = $this->service()->startAction($decision, $this->animateur, 'Preparer la migration');

        $this->assertSame($decision->id, $action->loop_decision_id);
        $this->assertSame($this->loop->id, $action->loop_id);
        $this->assertSame(LoopRoadmapItem::STATUS_TODO, $action->status);
        $this->assertSame($this->org->id, $action->organization_id);
    }

    public function test_one_decision_can_start_several_actions(): void
    {
        $decision = $this->decision('Un choix large');

        $this->service()->startAction($decision, $this->animateur, 'Premiere');
        $this->service()->startAction($decision, $this->animateur, 'Deuxieme');

        $this->assertCount(2, $decision->fresh()->actions);
    }

    public function test_an_empty_action_is_refused(): void
    {
        $this->expectException(ValidationException::class);
        $this->service()->startAction($this->decision('Un choix'), $this->animateur, '   ');
    }

    public function test_starting_an_action_needs_the_right_to_write_in_the_roadmap(): void
    {
        // Sinon la Card Decisions serait une porte laterale pour poser des
        // taches a qui n'a pas `roadmap.manage`.
        $decision = $this->decision('Un choix');

        $this->loops->addMember($this->loop, $sansRoadmap = User::factory()->create(['organization_id' => $this->org->id]), 'facilitator');

        // Le facilitateur a `roadmap.manage` : on le lui retire par la matrice
        // pour eprouver la garde, plutot que de supposer qu'elle tient.
        config(['loop_permissions.role_defaults.facilitator' => array_values(array_diff(
            config('loop_permissions.role_defaults.facilitator'),
            ['roadmap.manage'],
        ))]);

        Livewire::actingAs($sansRoadmap)
            ->test(LoopDecisionsCard::class, ['loop' => $this->loop])
            ->set('actionForId', $decision->id)
            ->set('actionTitle', 'Passer par la fenetre')
            ->call('saveAction')
            ->assertForbidden();

        $this->assertDatabaseMissing('loop_roadmap_items', ['title' => 'Passer par la fenetre']);
    }

    // ── Invariant 4 : capacite = backend + chemin utilisateur ───────────────

    public function test_a_member_reads_but_does_not_record(): void
    {
        // Une Decision engage le collectif : si chacun pouvait inscrire « nous
        // avons decide X », le registre cesserait de faire foi.
        $this->decision('Ce qui a ete tranche');

        $this->card($this->membre)
            ->assertSee('Ce qui a ete tranche')
            ->assertDontSee(__('loops.cards.decisions.add'));

        $this->card($this->membre)->set('title', 'Moi je decide')->call('save')->assertForbidden();
    }

    public function test_the_animator_has_the_gesture_on_screen(): void
    {
        $this->card()->assertSee(__('loops.cards.decisions.add'));
    }

    public function test_recording_from_the_screen_works(): void
    {
        $this->card()
            ->set('showForm', true)
            ->set('title', 'On part sur Postgres')
            ->set('rationale', 'Pour les contraintes')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('loop_decisions', [
            'loop_id' => $this->loop->id,
            'title' => 'On part sur Postgres',
            'rationale' => 'Pour les contraintes',
        ]);
    }

    public function test_the_supersede_and_action_gestures_are_on_screen(): void
    {
        $this->decision('Un choix');

        $this->card()
            ->assertSee(__('loops.cards.decisions.supersede'))
            ->assertSee(__('loops.cards.decisions.start_action'));
    }

    public function test_superseding_from_the_screen_works(): void
    {
        $ancienne = $this->decision('Avant');
        $nouvelle = $this->decision('Apres');

        $this->card()
            ->call('startSuperseding', $nouvelle->id)
            ->call('supersede', $ancienne->id)
            ->assertHasNoErrors();

        $this->assertSame($nouvelle->id, $ancienne->fresh()->superseded_by_id);
    }

    public function test_starting_an_action_from_the_screen_works(): void
    {
        $decision = $this->decision('Un choix');

        $this->card()
            ->call('startAction', $decision->id)
            ->set('actionTitle', 'Faire la chose')
            ->call('saveAction')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('loop_roadmap_items', [
            'loop_decision_id' => $decision->id,
            'title' => 'Faire la chose',
        ]);
    }

    public function test_someone_without_read_access_sees_nothing_and_writes_nothing(): void
    {
        $etranger = User::factory()->create(['organization_id' => $this->org->id]);

        Livewire::actingAs($etranger)
            ->test(LoopDecisionsCard::class, ['loop' => $this->loop])
            ->assertSee(__('loops.cards.decisions.no_access'))
            ->set('title', 'Par effraction')
            ->call('save')
            ->assertForbidden();
    }

    // ── Le droit de corriger ────────────────────────────────────────────────

    public function test_nobody_rewrites_someone_elses_decision_by_setting_the_public_property(): void
    {
        // `$editingId` est public : la garde doit etre **dans `save()`**, pas
        // seulement dans `startEditing()`. C'est le defaut exact trouve dans le
        // Journal, ou poser la propriete suffisait a reecrire l'entree de
        // n'importe qui, signee du nom de la victime.
        $this->loops->addMember($this->loop, $autre = User::factory()->create(['organization_id' => $this->org->id]), 'facilitator');

        // Le facilitateur a `decisions.manage` par defaut : on le lui retire,
        // sinon le test ne prouverait rien.
        config(['loop_permissions.role_defaults.facilitator' => array_values(array_diff(
            config('loop_permissions.role_defaults.facilitator'),
            ['decisions.manage'],
        ))]);

        $sienne = $this->decision('Celle de l’animateur');

        Livewire::actingAs($autre)
            ->test(LoopDecisionsCard::class, ['loop' => $this->loop])
            ->set('editingId', $sienne->id)
            ->set('title', 'Reecrite en douce')
            ->call('save')
            ->assertForbidden();

        $this->assertSame('Celle de l’animateur', $sienne->fresh()->title);
    }

    public function test_cancel_releases_what_the_form_was_holding(): void
    {
        // Sinon la Decision suivante — ecrite en croyant en commencer une —
        // ecraserait celle qu'on venait de renoncer a corriger.
        $premiere = $this->decision('La premiere');

        $this->card()
            ->call('startEditing', $premiere->id)
            ->call('cancel')
            ->set('showForm', true)
            ->set('title', 'Une toute nouvelle')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('La premiere', $premiere->fresh()->title);
        $this->assertSame(2, LoopDecision::where('loop_id', $this->loop->id)->count());
    }

    public function test_a_decision_from_another_loop_is_a_404(): void
    {
        $autre = $this->loops->createLoop($this->animateur, 'Autre')->fresh();
        $ailleurs = $this->service()->record($autre, $this->animateur, 'Ailleurs');

        $this->card()->call('startEditing', $ailleurs->id)->assertNotFound();
    }

    public function test_an_invalid_identifier_is_a_404_and_not_a_500(): void
    {
        // Sous PostgreSQL, une colonne `uuid` native rend `SQLSTATE 22P02` — un
        // 500 — la ou SQLite rend 404. La resolution passe par un `where` sur
        // la Boucle, qui ne compare jamais l'identifiant a une colonne uuid
        // sans l'avoir trouve.
        $this->card()->call('startEditing', '00000000-0000-0000-0000-000000000000')->assertNotFound();
    }

    // ── Les dates ───────────────────────────────────────────────────────────

    public function test_an_impossible_date_is_refused_and_never_silently_changed(): void
    {
        // `Carbon::parse` accepte « 2026-02-30 » et rend le 2 mars : la date
        // saisie serait *changee* sans un mot.
        $this->expectException(ValidationException::class);
        $this->service()->record($this->loop, $this->animateur, 'Un choix', null, '2026-02-30');
    }

    public function test_an_absurd_year_is_refused(): void
    {
        $this->expectException(ValidationException::class);
        $this->service()->record($this->loop, $this->animateur, 'Un choix', null, '0000-01-01');
    }

    public function test_the_decision_date_is_distinct_from_the_writing_date(): void
    {
        // On consigne souvent apres coup.
        $decision = $this->decision('Decide la semaine derniere', null, now()->subWeek()->toDateString());

        $this->assertSame(now()->subWeek()->toDateString(), $decision->decided_on->toDateString());
        $this->assertSame(now()->toDateString(), $decision->created_at->toDateString());
    }

    // ── Le titre ────────────────────────────────────────────────────────────

    public function test_an_empty_title_is_refused(): void
    {
        $this->expectException(ValidationException::class);
        $this->service()->record($this->loop, $this->animateur, '   ');
    }

    public function test_a_very_long_title_is_cut_here_and_not_by_the_database(): void
    {
        // La colonne fait 255 : laisser la base trancher rend 500 sous
        // PostgreSQL et coupe en silence ailleurs.
        $decision = $this->service()->record($this->loop, $this->animateur, str_repeat('a', 400));

        $this->assertSame(255, mb_strlen($decision->title));
    }

    public function test_promoting_without_a_title_is_refused_on_screen(): void
    {
        // Le corps d'un message n'est pas un titre.
        $message = $this->message('Un long echange qui ne fait pas un titre');

        $this->card()
            ->set('showForm', true)
            ->set('showPicker', true)
            ->call('promote', $message->id);

        $this->assertDatabaseCount('loop_decisions', 0);
    }

    // ── Le cloisonnement ────────────────────────────────────────────────────

    public function test_every_row_carries_its_organization(): void
    {
        $decision = $this->decision('Un choix');
        $action = $this->service()->startAction($decision, $this->animateur, 'Une action');

        // Porte par la ligne, lu depuis la Boucle — jamais depuis la requete.
        $this->assertSame($this->org->id, $decision->organization_id);
        $this->assertSame($this->org->id, $action->organization_id);
    }

    public function test_a_decision_of_another_organization_is_never_listed(): void
    {
        $autreOrg = Organization::factory()->create(['is_active' => true]);
        $ailleurs = User::factory()->create(['organization_id' => $autreOrg->id]);

        app()->instance('current_organization', $autreOrg);
        $autreLoop = (new LoopService)->createLoop($ailleurs, 'Chez eux')->fresh();
        $this->service()->record($autreLoop, $ailleurs, 'Leur choix');

        app()->instance('current_organization', $this->org);

        $lues = $this->service()->decisionsFor($this->loop);

        $this->assertTrue($lues->every(fn (LoopDecision $d) => $d->organization_id === $this->org->id));
        $this->card()->assertDontSee('Leur choix');
    }

    // ── Invariant 3 : le cout ne croit pas ──────────────────────────────────

    public function test_the_cost_does_not_follow_the_number_of_decisions(): void
    {
        $compter = function (int $combien): int {
            LoopDecision::where('loop_id', $this->loop->id)->delete();

            for ($i = 0; $i < $combien; $i++) {
                $decision = $this->service()->promote(
                    $this->loop, $this->animateur, $this->message("message {$i}"), "choix {$i}",
                );
                $this->service()->startAction($decision, $this->animateur, "action {$i}");
            }

            DB::enableQueryLog();
            // **`flushQueryLog` et non `disableQueryLog`** : celui-ci ne vide
            // pas le journal, et la seconde mesure comptait les requetes de la
            // premiere. Ma propre sonde avait accuse le code a tort.
            DB::flushQueryLog();

            $this->service()->decisionsFor($this->loop)->each(function (LoopDecision $d) {
                $d->displayMessage();
                $d->author?->first_name;
                $d->supersededBy?->title;
                $d->actions->pluck('title');
            });

            $n = count(DB::getQueryLog());
            DB::flushQueryLog();

            return $n;
        };

        $petit = $compter(2);
        $grand = $compter(20);

        $this->assertLessThanOrEqual(
            $petit + 2,
            $grand,
            "le cout suit le nombre de Decisions : {$petit} requetes pour 2, {$grand} pour 20",
        );
    }

    // ── Aucune condition sur le type ────────────────────────────────────────

    public function test_no_business_code_branches_on_the_loop_type(): void
    {
        foreach ([
            app_path('Livewire/LoopDecisionsCard.php'),
            app_path('Services/Loops/LoopDecisionService.php'),
            resource_path('views/livewire/loop-decisions-card.blade.php'),
        ] as $fichier) {
            $source = file_get_contents($fichier);

            foreach (["\$loop->type ===", "\$loop->type =="] as $condition) {
                $this->assertStringNotContainsString($condition, $source, basename($fichier));
            }
        }
    }
}
