<?php

namespace Tests\Feature;

use App\Livewire\LoopMarketplaceCard;
use App\Models\Loop;
use App\Models\LoopCard;
use App\Models\LoopMarketplaceLink;
use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\Loops\LoopMarketplaceService;
use App\Services\LoopService;
use App\Support\Loops\LoopCardRegistry;
use App\Support\Loops\LoopTypeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * La Card Demande-Offre, et le preset Reseautage.
 *
 * **Le point central est ce qui n'est PAS construit.** Les Offres (`services`)
 * et les Demandes (`service_requests`) existent depuis l'origine du produit,
 * avec leurs categories, leurs competences, leurs points et leurs
 * transactions. La Card les **rattache** a une Boucle ; elle n'en refait pas
 * un second.
 *
 * Les cinq invariants de la serie sont couverts, en plus des regles metier.
 */
class TASK1107LoopMarketplaceTest extends TestCase
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
        $this->loop = $this->loops->createLoop($this->animateur, 'Mon reseau')->fresh();
        $this->loops->addMember($this->loop, $this->membre, 'member');

        LoopCard::firstOrCreate(
            ['loop_id' => $this->loop->id, 'card_key' => 'core.marketplace'],
            ['organization_id' => $this->org->id, 'enabled' => true],
        );
    }

    private function service(): LoopMarketplaceService
    {
        return app(LoopMarketplaceService::class);
    }

    private function card(?User $as = null)
    {
        return Livewire::actingAs($as ?? $this->membre)->test(LoopMarketplaceCard::class, ['loop' => $this->loop]);
    }

    /** Une categorie est obligatoire sur les deux modeles : elle est creee ici. */
    private function categorie(?Organization $org = null): \App\Models\Category
    {
        $org ??= $this->org;

        return \App\Models\Category::firstOrCreate(
            ['organization_id' => $org->id, 'slug' => 'artisanat'],
            ['name_b2c' => 'Artisanat', 'name_b2b' => 'Artisanat', 'color' => 'gray'],
        );
    }

    private function offre(?User $de = null, string $titre = 'Je sais souder', string $statut = 'active', ?Organization $org = null): Service
    {
        return Service::create([
            'organization_id' => ($org ?? $this->org)->id,
            'category_id' => $this->categorie($org)->id,
            'user_id' => ($de ?? $this->membre)->id,
            'title' => $titre,
            'description' => 'Une description.',
            'delivery_mode' => 'remote',
            'points_cost' => 10,
            'status' => $statut,
        ]);
    }

    private function demande(?User $de = null, string $titre = 'Je cherche un soudeur', string $statut = 'open'): ServiceRequest
    {
        return ServiceRequest::create([
            'organization_id' => $this->org->id,
            'category_id' => $this->categorie()->id,
            'user_id' => ($de ?? $this->membre)->id,
            'title' => $titre,
            'description' => 'Une description.',
            'delivery_mode' => 'remote',
            // Les deux bornes sont obligatoires en base : la fixture les pose
            // plutot que de laisser le test echouer sur une contrainte qui n'a
            // rien a voir avec ce qu'il verifie.
            'budget_min' => 0,
            'budget_max' => 100,
            'status' => $statut,
        ]);
    }

    // ── Aucun second systeme ────────────────────────────────────────────────

    public function test_no_second_catalogue_is_created(): void
    {
        // C'est la faute que cette Card pouvait commettre : refaire les Offres
        // et les Demandes, et donner deux endroits pour dire la meme chose.
        foreach (['loop_offers', 'loop_requests', 'loop_services', 'loop_needs'] as $interdite) {
            $this->assertFalse(Schema::hasTable($interdite), $interdite);
        }

        $this->assertTrue(Schema::hasTable('loop_marketplace_links'));
    }

    public function test_the_card_carries_no_creation_form(): void
    {
        // Le parcours de creation existe, avec ses regles de categorie, de mode
        // de livraison et de cout en points. Le dupliquer les ferait diverger.
        $source = file_get_contents(app_path('Livewire/LoopMarketplaceCard.php'));

        $this->assertStringNotContainsString('Service::create', $source);
        $this->assertStringNotContainsString('ServiceRequest::create', $source);
    }

    public function test_the_screen_points_at_the_existing_creation_path(): void
    {
        $this->card()
            ->set('picking', 'offer')
            ->assertSee(__('loops.cards.marketplace.create_offer'))
            ->assertSeeHtml(route('services.create'));
    }

    // ── Le preset Reseautage ────────────────────────────────────────────────

    public function test_the_networking_preset_exists_with_its_three_distinctive_cards(): void
    {
        // La matrice : « Reseautage | Manifeste · Membres | Demande-Offre ·
        // Roadmap · Evenements ».
        $cles = app(LoopTypeRegistry::class)->cardsFor('networking');

        foreach (['core.marketplace', 'core.roadmap', 'core.events', 'core.manifesto', 'core.members'] as $attendue) {
            $this->assertContains($attendue, $cles, $attendue);
        }
    }

    public function test_the_networking_preset_is_not_offered_yet(): void
    {
        // Ouvrir un type est une decision de produit, prise a part : c'est
        // ainsi que `training` et `peer_support` sont arrives.
        $registre = app(LoopTypeRegistry::class);

        $this->assertFalse($registre->isAvailable('networking'));
        $this->assertNotContains('networking', $registre->availableKeys());
    }

    public function test_the_card_order_is_unique_among_every_card(): void
    {
        $ordres = collect(config('loop_cards.cards'))->pluck('order');

        $this->assertSame($ordres->count(), $ordres->unique()->count(), 'deux Cards partagent un ordre');
    }

    public function test_the_card_exists_and_depends_on_nothing(): void
    {
        $registre = app(LoopCardRegistry::class);

        $this->assertTrue($registre->exists('core.marketplace'));
        $this->assertSame([], $registre->get('core.marketplace')['requires']);
    }

    // ── Invariant 1 : une ecriture n'est pas protegee par une lecture ───────

    public function test_highlighting_carries_no_read_flag(): void
    {
        $permissions = config('loop_permissions.permissions');

        $this->assertTrue($permissions['marketplace.view']['read']);
        $this->assertArrayNotHasKey('read', $permissions['marketplace.highlight']);
        $this->assertArrayNotHasKey('read', $permissions['marketplace.manage']);
    }

    public function test_an_archived_loop_highlights_nothing(): void
    {
        $offre = $this->offre();
        $this->loop->forceFill(['status' => 'archived', 'archived_at' => now()])->save();

        $this->card()->call('highlightOffer', $offre->id)->assertForbidden();

        $this->assertDatabaseCount('loop_marketplace_links', 0);
    }

    public function test_an_archived_loop_removes_nothing(): void
    {
        $lien = $this->service()->highlightOffer($this->loop, $this->membre, $this->offre());
        $this->loop->forceFill(['status' => 'archived', 'archived_at' => now()])->save();

        $this->card()->call('remove', $lien->id)->assertForbidden();

        $this->assertDatabaseHas('loop_marketplace_links', ['id' => $lien->id]);
    }

    public function test_an_archived_loop_still_reads_what_it_highlighted(): void
    {
        // Boucle archivee = lecture historique, aucune mutation metier.
        $this->service()->highlightOffer($this->loop, $this->membre, $this->offre());
        $this->loop->forceFill(['status' => 'archived', 'archived_at' => now()])->save();

        $this->card()->assertSee('Je sais souder')->assertOk();
    }

    public function test_an_archived_loop_saves_no_note(): void
    {
        $lien = $this->service()->highlightOffer($this->loop, $this->membre, $this->offre());
        $this->loop->forceFill(['status' => 'archived', 'archived_at' => now()])->save();

        $this->card()->set('editingId', $lien->id)->set('note', 'En douce')->call('saveNote')->assertForbidden();

        $this->assertNull($lien->fresh()->note);
    }

    // ── Invariant 2 : la transition doit etre valide ────────────────────────

    public function test_one_only_highlights_ones_own_offers(): void
    {
        // Mettre en avant celle de quelqu'un d'autre l'engagerait a repondre a
        // une Boucle qu'il n'a pas choisie.
        $celleDeLAnimateur = $this->offre($this->animateur, 'La sienne');

        $this->card($this->membre)->call('highlightOffer', $celleDeLAnimateur->id)->assertNotFound();

        $this->assertDatabaseCount('loop_marketplace_links', 0);
    }

    public function test_one_only_highlights_ones_own_requests(): void
    {
        $celleDeLAnimateur = $this->demande($this->animateur, 'La sienne');

        $this->card($this->membre)->call('highlightRequest', $celleDeLAnimateur->id)->assertNotFound();
    }

    public function test_an_offer_from_another_organization_is_refused(): void
    {
        $autreOrg = Organization::factory()->create(['is_active' => true]);
        $ailleurs = $this->offre($this->membre, 'Ailleurs', 'active', $autreOrg);

        $this->expectException(ValidationException::class);
        $this->service()->highlightOffer($this->loop, $this->membre, $ailleurs);
    }

    public function test_the_same_offer_is_never_highlighted_twice(): void
    {
        $offre = $this->offre();

        $a = $this->service()->highlightOffer($this->loop, $this->membre, $offre);
        $b = $this->service()->highlightOffer($this->loop, $this->membre, $offre);

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, LoopMarketplaceLink::where('service_id', $offre->id)->count());
    }

    public function test_the_database_itself_refuses_a_duplicate(): void
    {
        // Un `SELECT` puis un `INSERT` sans verrou laisse passer deux ecritures
        // concurrentes : l'unicite est tenue par la base.
        $offre = $this->offre();
        $this->service()->highlightOffer($this->loop, $this->membre, $offre);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        LoopMarketplaceLink::create([
            'organization_id' => $this->org->id,
            'loop_id' => $this->loop->id,
            'added_by' => $this->membre->id,
            'service_id' => $offre->id,
        ]);
    }

    public function test_two_different_requests_do_not_collide_on_their_null_service_id(): void
    {
        // Plusieurs NULL ne s'egalent ni en SQLite ni en PostgreSQL : deux
        // Demandes mises en avant ont toutes deux `service_id` a NULL et ne
        // doivent pas se heurter.
        $this->service()->highlightRequest($this->loop, $this->membre, $this->demande($this->membre, 'Une'));
        $this->service()->highlightRequest($this->loop, $this->membre, $this->demande($this->membre, 'Deux'));

        $this->assertSame(2, LoopMarketplaceLink::where('loop_id', $this->loop->id)->count());
    }

    public function test_the_same_offer_lives_in_several_loops(): void
    {
        // On offre la meme competence dans deux reseaux : d'ou une table de
        // liens et non une colonne `loop_id` sur `services`.
        $offre = $this->offre();
        $autre = $this->loops->createLoop($this->animateur, 'Un autre reseau')->fresh();
        $this->loops->addMember($autre, $this->membre, 'member');

        $this->service()->highlightOffer($this->loop, $this->membre, $offre);
        $this->service()->highlightOffer($autre, $this->membre, $offre);

        $this->assertSame(2, LoopMarketplaceLink::where('service_id', $offre->id)->count());
    }

    // ── Un lien, jamais une copie ───────────────────────────────────────────

    public function test_the_title_is_read_on_the_offer_and_never_copied(): void
    {
        $offre = $this->offre();
        $lien = $this->service()->highlightOffer($this->loop, $this->membre, $offre);

        $offre->update(['title' => 'Je sais souder et braser']);

        $this->assertSame('Je sais souder et braser', $lien->fresh()->displayTitle());
        $this->card()->assertSee('Je sais souder et braser');
    }

    public function test_no_column_copies_the_offer(): void
    {
        $colonnes = Schema::getColumnListing('loop_marketplace_links');

        foreach (['title', 'description', 'status', 'points_cost'] as $copie) {
            $this->assertNotContains($copie, $colonnes, "« {$copie} » recopie l'Offre");
        }
    }

    public function test_an_offer_withdrawn_from_the_catalogue_shows_as_withdrawn(): void
    {
        // Sans cela, quelqu'un l'aurait sollicitee pour rien.
        $offre = $this->offre();
        $lien = $this->service()->highlightOffer($this->loop, $this->membre, $offre);

        $this->assertTrue($lien->fresh()->isLive());

        // `paused`, et non « inactive » : PostgreSQL porte un `CHECK` que
        // SQLite ignore, et le vocabulaire reel est `active|paused|deleted`.
        // Mon test inventait un statut, et seul PostgreSQL l'a dit.
        $offre->update(['status' => 'paused']);

        $this->assertFalse($lien->fresh()->isLive());
        $this->card()->assertSee(__('loops.cards.marketplace.closed_badge'));
    }

    public function test_the_real_status_vocabulary_is_respected(): void
    {
        // PostgreSQL porte un `CHECK` sur les deux colonnes ; SQLite ne
        // l'exprime pas. Un test ecrit sur SQLite seul aurait invente des
        // statuts qui n'existent pas — c'est exactement ce qui s'est passe.
        foreach (['active', 'paused', 'deleted'] as $statut) {
            $this->assertNotNull($this->offre($this->membre, "offre {$statut}", $statut)->id);
        }

        foreach (['open', 'in_progress', 'closed'] as $statut) {
            $this->assertNotNull($this->demande($this->membre, "demande {$statut}", $statut)->id);
        }
    }

    public function test_a_request_uses_its_own_word_for_alive(): void
    {
        // Une Offre est `active`, une Demande est `open`. Les confondre aurait
        // montre toutes les Demandes comme eteintes.
        $lien = $this->service()->highlightRequest($this->loop, $this->membre, $this->demande());

        $this->assertTrue($lien->fresh()->isLive());
    }

    // ── Invariant 5 : retirer ne detruit rien ───────────────────────────────

    public function test_removing_a_link_never_touches_the_offer(): void
    {
        $offre = $this->offre();
        $lien = $this->service()->highlightOffer($this->loop, $this->membre, $offre);

        $this->service()->remove($lien);

        $this->assertDatabaseHas('services', ['id' => $offre->id, 'title' => 'Je sais souder']);
    }

    public function test_deleting_the_offer_takes_its_link_away(): void
    {
        // Une Offre supprimee n'a plus rien a mettre en avant, et son lien
        // n'aurait plus de titre a afficher.
        $offre = $this->offre();
        $lien = $this->service()->highlightOffer($this->loop, $this->membre, $offre);

        $offre->forceDelete();

        $this->assertDatabaseMissing('loop_marketplace_links', ['id' => $lien->id]);
    }

    // ── Le cloisonnement ────────────────────────────────────────────────────

    public function test_every_row_carries_its_organization(): void
    {
        $lien = $this->service()->highlightOffer($this->loop, $this->membre, $this->offre());

        // Lu depuis la Boucle, jamais depuis la requete.
        $this->assertSame($this->org->id, $lien->organization_id);
    }

    public function test_a_link_of_another_organization_is_never_reachable(): void
    {
        $autreOrg = Organization::factory()->create(['is_active' => true]);
        $ailleurs = User::factory()->create(['organization_id' => $autreOrg->id]);

        app()->instance('current_organization', $autreOrg);
        $autreLoop = (new LoopService)->createLoop($ailleurs, 'Chez eux')->fresh();
        $leurOffre = $this->offre($ailleurs, 'La leur', 'active', $autreOrg);
        $leurLien = $this->service()->highlightOffer($autreLoop, $ailleurs, $leurOffre);

        app()->instance('current_organization', $this->org);

        $this->card()->call('remove', $leurLien->id)->assertNotFound();
        $this->card()->call('startEditingNote', $leurLien->id)->assertNotFound();
        $this->card()->assertDontSee('La leur');

        $this->assertDatabaseHas('loop_marketplace_links', ['id' => $leurLien->id]);
    }

    public function test_an_invalid_identifier_is_a_404_and_not_a_500(): void
    {
        // Sous PostgreSQL les colonnes sont des `uuid` natifs : une chaine qui
        // n'en est pas un rend `SQLSTATE 22P02`, donc 500. Les quatre gestes
        // qui recoivent un identifiant sont eprouves, **avec une chaine qui
        // n'est pas un UUID** — la lecon de TASK-1106, ou le test passait un
        // UUID valide et ne prouvait rien.
        $this->card()->call('remove', 'pas-un-uuid')->assertNotFound();
        $this->card()->call('startEditingNote', 'pas-un-uuid')->assertNotFound();
        $this->card()->call('highlightOffer', 'pas-un-uuid')->assertNotFound();
        $this->card()->call('highlightRequest', 'pas-un-uuid')->assertNotFound();
        $this->card()->set('editingId', 'pas-un-uuid')->call('saveNote')->assertNotFound();
    }

    // ── Invariant 4 : capacite = backend + chemin utilisateur ───────────────

    public function test_a_member_has_the_two_gestures_on_screen(): void
    {
        // Une Boucle de Reseautage n'a d'interet que si chacun y apporte ce
        // qu'il sait faire et ce qu'il cherche.
        $this->card($this->membre)
            ->assertSee(__('loops.cards.marketplace.add_offer'))
            ->assertSee(__('loops.cards.marketplace.add_request'));
    }

    public function test_highlighting_from_the_screen_works(): void
    {
        $offre = $this->offre();

        $this->card()
            ->set('picking', 'offer')
            ->set('note', 'Exactement ce dont on parlait')
            ->call('highlightOffer', $offre->id)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('loop_marketplace_links', [
            'loop_id' => $this->loop->id,
            'service_id' => $offre->id,
            'note' => 'Exactement ce dont on parlait',
        ]);
    }

    public function test_the_picker_only_offers_ones_own(): void
    {
        $this->offre($this->animateur, 'La sienne');
        $laMienne = $this->offre($this->membre, 'La mienne');

        $this->card($this->membre)
            ->set('picking', 'offer')
            ->assertSeeHtml("highlightOffer('{$laMienne->id}')")
            ->assertDontSee('La sienne');
    }

    public function test_the_picker_drops_what_is_already_highlighted(): void
    {
        $offre = $this->offre();
        $this->service()->highlightOffer($this->loop, $this->membre, $offre);

        $this->card()->set('picking', 'offer')->assertDontSeeHtml("highlightOffer('{$offre->id}')");
    }

    public function test_an_inactive_offer_is_not_proposed(): void
    {
        $eteinte = $this->offre($this->membre, 'Eteinte', 'paused');

        $this->card()->set('picking', 'offer')->assertDontSeeHtml("highlightOffer('{$eteinte->id}')");
    }

    public function test_the_screen_says_what_highlighting_does_not_do(): void
    {
        // Laisser croire qu'une Boucle rend une Offre privee serait une
        // promesse de confidentialite que le produit ne tient pas.
        $this->card()->set('picking', 'offer')->assertSee(__('loops.cards.marketplace.visibility_notice'));
    }

    public function test_someone_without_read_access_sees_nothing_and_writes_nothing(): void
    {
        $etranger = User::factory()->create(['organization_id' => $this->org->id]);
        $sienne = $this->offre($etranger, 'La sienne');

        Livewire::actingAs($etranger)
            ->test(LoopMarketplaceCard::class, ['loop' => $this->loop])
            ->assertSee(__('loops.cards.marketplace.no_access'))
            ->call('highlightOffer', $sienne->id)
            ->assertForbidden();
    }

    // ── Le droit de retirer ─────────────────────────────────────────────────

    public function test_nobody_removes_someone_elses_highlight(): void
    {
        $sonLien = $this->service()->highlightOffer($this->loop, $this->membre, $this->offre());

        $autre = User::factory()->create(['organization_id' => $this->org->id]);
        $this->loops->addMember($this->loop, $autre, 'member');

        Livewire::actingAs($autre)
            ->test(LoopMarketplaceCard::class, ['loop' => $this->loop])
            ->call('remove', $sonLien->id)
            ->assertForbidden();

        $this->assertDatabaseHas('loop_marketplace_links', ['id' => $sonLien->id]);
    }

    public function test_nobody_writes_someone_elses_note_by_setting_the_public_property(): void
    {
        // `$editingId` est public : la garde doit etre **dans `saveNote()`**,
        // pas seulement dans `startEditingNote()`.
        $sonLien = $this->service()->highlightOffer($this->loop, $this->membre, $this->offre());

        $autre = User::factory()->create(['organization_id' => $this->org->id]);
        $this->loops->addMember($this->loop, $autre, 'member');

        Livewire::actingAs($autre)
            ->test(LoopMarketplaceCard::class, ['loop' => $this->loop])
            ->set('editingId', $sonLien->id)
            ->set('note', 'Ecrit en douce')
            ->call('saveNote')
            ->assertForbidden();

        $this->assertNull($sonLien->fresh()->note);
    }

    public function test_the_animation_removes_everyones(): void
    {
        $sonLien = $this->service()->highlightOffer($this->loop, $this->membre, $this->offre());

        $this->card($this->animateur)->call('remove', $sonLien->id)->assertHasNoErrors();

        $this->assertDatabaseMissing('loop_marketplace_links', ['id' => $sonLien->id]);
    }

    public function test_cancel_releases_what_the_form_was_holding(): void
    {
        // Sinon le mot suivant ecraserait celui qu'on venait de renoncer a
        // corriger.
        $premier = $this->service()->highlightOffer($this->loop, $this->membre, $this->offre($this->membre, 'Une'));
        $second = $this->service()->highlightOffer($this->loop, $this->membre, $this->offre($this->membre, 'Deux'));

        $this->card()
            ->call('startEditingNote', $premier->id)
            ->call('cancel')
            ->call('startEditingNote', $second->id)
            ->set('note', 'Pour le second')
            ->call('saveNote')
            ->assertHasNoErrors();

        $this->assertNull($premier->fresh()->note);
        $this->assertSame('Pour le second', $second->fresh()->note);
    }

    // ── Qui contacter ───────────────────────────────────────────────────────

    public function test_the_author_shown_is_the_offers_author_and_not_who_highlighted_it(): void
    {
        // Confondre les deux ferait ecrire au mauvais.
        $sonOffre = $this->offre($this->animateur, 'Celle de l’animateur');
        $lien = $this->service()->highlightOffer($this->loop, $this->animateur, $sonOffre);

        $this->assertSame($this->animateur->id, $lien->fresh()->author()?->id);
    }

    // ── La note ─────────────────────────────────────────────────────────────

    public function test_a_note_is_bounded(): void
    {
        $lien = $this->service()->highlightOffer($this->loop, $this->membre, $this->offre(), str_repeat('z', 50000));

        $this->assertLessThanOrEqual(2000, mb_strlen($lien->note));
    }

    public function test_an_empty_note_is_stored_as_nothing(): void
    {
        $lien = $this->service()->highlightOffer($this->loop, $this->membre, $this->offre(), '   ');

        $this->assertNull($lien->note);
    }

    // ── Invariant 3 : le cout ne croit pas ──────────────────────────────────

    public function test_the_cost_does_not_follow_the_number_of_links(): void
    {
        $compter = function (int $combien): int {
            LoopMarketplaceLink::where('loop_id', $this->loop->id)->delete();

            for ($i = 0; $i < $combien; $i++) {
                $this->service()->highlightOffer($this->loop, $this->membre, $this->offre($this->membre, "offre {$i}"));
            }

            DB::enableQueryLog();
            // `flushQueryLog` et non `disableQueryLog` : celui-ci ne vide pas le
            // journal, et la seconde mesure compterait les requetes de la
            // premiere.
            DB::flushQueryLog();

            $this->service()->linksFor($this->loop)->each(function (LoopMarketplaceLink $l) {
                $l->displayTitle();
                $l->author()?->first_name;
                $l->addedBy?->first_name;
                $l->isLive();
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
            "le cout suit le nombre de liens : {$petit} requetes pour 2, {$grand} pour 20",
        );
    }


    // ── Ce que la revue hostile a trouve ────────────────────────────────────

    public function test_a_deactivated_persons_offer_is_not_announced_as_live(): void
    {
        // `services.show` rend 404 pour elle et `scopeActive()` l'exclut du
        // catalogue. La Card etait la seule surface a l'annoncer vivante et
        // contactable, sous le vrai nom de la personne.
        $offre = $this->offre();
        $lien = $this->service()->highlightOffer($this->loop, $this->membre, $offre);

        $this->membre->forceFill(['banned_at' => now()])->save();

        $lu = $this->service()->linksFor($this->loop)->firstWhere('id', $lien->id);

        $this->assertFalse($lu->isLive());
        $this->assertNotSame($this->membre->fullName, $lu->authorName());
    }

    public function test_the_screen_never_shows_a_deactivated_persons_real_name(): void
    {
        $this->service()->highlightOffer($this->loop, $this->membre, $this->offre());
        $this->membre->forceFill(['banned_at' => now()])->save();

        $this->card($this->animateur)
            ->assertDontSee($this->membre->fullName)
            ->assertSee(__('loops.cards.marketplace.closed_badge'));
    }

    public function test_a_soft_deleted_offer_leaves_no_empty_box(): void
    {
        // `ServiceController::destroy()` fait un **soft delete** : la ligne
        // reste, le `cascadeOnDelete` ne se declenche jamais, et la Card
        // affichait une boite sans titre que seul son auteur pouvait retirer.
        // Mon test d'origine eprouvait `forceDelete()`, geste reserve au
        // super-admin — un chemin que le produit ne prend jamais.
        $offre = $this->offre();
        $this->service()->highlightOffer($this->loop, $this->membre, $offre);

        $offre->update(['status' => 'deleted']);
        $offre->delete();

        $this->assertCount(0, $this->service()->linksFor($this->loop));
        $this->card()->assertSee(__('loops.cards.marketplace.empty_title'));
    }

    public function test_an_in_progress_request_is_not_called_withdrawn(): void
    {
        // Elle est en cours de traitement, pas retiree du catalogue.
        $lien = $this->service()->highlightRequest($this->loop, $this->membre, $this->demande());

        $lien->serviceRequest->update(['status' => 'in_progress']);

        $this->assertTrue($lien->fresh()->isLive());
    }

    public function test_a_closed_request_is_withdrawn(): void
    {
        $lien = $this->service()->highlightRequest($this->loop, $this->membre, $this->demande());

        $lien->serviceRequest->update(['status' => 'closed']);

        $this->assertFalse($lien->fresh()->isLive());
    }

    public function test_opening_a_picker_releases_what_the_form_was_holding(): void
    {
        // `$set('picking', …)` laissait `editingId` pose : le mot tape pour la
        // nouvelle mise en avant ecrasait celui de la precedente, ou la
        // nouvelle naissait avec le mot de l'ancienne. Trois clics ordinaires.
        $premier = $this->service()->highlightOffer($this->loop, $this->membre, $this->offre($this->membre, 'Une'), 'mot du premier');
        $seconde = $this->offre($this->membre, 'Deux');

        $this->card()
            ->call('startEditingNote', $premier->id)
            ->call('startPicking', 'offer')
            ->assertSet('editingId', null)
            ->assertSet('note', '')
            ->call('highlightOffer', $seconde->id)
            ->assertHasNoErrors();

        $this->assertSame('mot du premier', $premier->fresh()->note);
        $this->assertNull(LoopMarketplaceLink::where('service_id', $seconde->id)->value('note'));
    }

    public function test_a_paused_offer_cannot_be_highlighted_by_direct_call(): void
    {
        // Le filtre de statut n'existait que dans le selecteur, donc
        // contournable : l'identifiant vient du client. On pouvait injecter du
        // mort dans la Boucle.
        $eteinte = $this->offre($this->membre, 'Eteinte', 'paused');

        $this->card()->call('highlightOffer', $eteinte->id)->assertNotFound();

        $this->assertDatabaseCount('loop_marketplace_links', 0);
    }

    public function test_a_closed_request_cannot_be_highlighted_by_direct_call(): void
    {
        $fermee = $this->demande($this->membre, 'Fermee', 'closed');

        $this->card()->call('highlightRequest', $fermee->id)->assertNotFound();
    }

    public function test_the_card_leads_somewhere(): void
    {
        // Sans ce lien la Card montrait un titre et un prenom, sans permettre
        // ni d'ouvrir l'Offre, ni de la demander, ni d'ecrire a la personne.
        $offre = $this->offre();
        $this->service()->highlightOffer($this->loop, $this->membre, $offre);

        $this->card()
            ->assertSee(__('loops.cards.marketplace.open_in_catalogue'))
            ->assertSeeHtml(route('services.show', $offre));
    }

    public function test_the_most_recent_are_the_ones_shown(): void
    {
        // Sous PostgreSQL les `timestamps()` sont a la seconde : trente-cinq
        // mises en avant faites dans la meme seconde s'ordonnaient au hasard,
        // et les trente affichees etaient les **plus anciennes**.
        for ($i = 0; $i < 35; $i++) {
            $this->service()->highlightOffer($this->loop, $this->membre, $this->offre($this->membre, "offre {$i}"));
        }

        $titres = $this->service()->linksFor($this->loop)->map(fn ($l) => $l->displayTitle());

        $this->assertContains('offre 34', $titres, 'la plus recente est absente');
        $this->assertNotContains('offre 0', $titres, 'la plus ancienne est affichee a sa place');
    }

    public function test_what_is_not_shown_is_not_hidden_in_silence(): void
    {
        for ($i = 0; $i < 33; $i++) {
            $this->service()->highlightOffer($this->loop, $this->membre, $this->offre($this->membre, "offre {$i}"));
        }

        $this->assertSame(33, $this->service()->countFor($this->loop));
        $this->card()->assertSee(trans_choice('loops.cards.marketplace.and_more', 3, ['count' => 3]));
    }

    public function test_highlighting_again_applies_the_new_note(): void
    {
        // L'ecran annonçait « mis en avant » et n'ecrivait rien : un succes
        // pour un geste sans effet.
        $offre = $this->offre();
        $this->service()->highlightOffer($this->loop, $this->membre, $offre, 'premier mot');

        $lien = $this->service()->highlightOffer($this->loop, $this->membre, $offre, 'second mot');

        $this->assertSame('second mot', $lien->note);
    }

    public function test_can_edit_is_not_exposed_as_a_livewire_action(): void
    {
        // Livewire expose toute methode publique comme action et resout son
        // argument par liaison implicite — donc sans le `where('loop_id', …)`
        // du resolveur. C'etait un oracle d'existence inter-Boucles.
        $methode = new \ReflectionMethod(LoopMarketplaceCard::class, 'canEdit');

        $this->assertFalse($methode->isPublic(), 'canEdit est exposee comme action Livewire');
    }


    // ── Ce que la recette navigateur a montre ───────────────────────────────

    public function test_correcting_a_note_does_not_claim_something_was_highlighted(): void
    {
        // Observe en recette : la confirmation decrivait un autre geste que
        // celui qu'on venait de faire.
        $lien = $this->service()->highlightOffer($this->loop, $this->membre, $this->offre());

        $this->card()
            ->call('startEditingNote', $lien->id)
            ->set('note', 'Un mot')
            ->call('saveNote')
            ->assertSet('flash', __('loops.cards.marketplace.note_saved'));

        $this->assertNotSame(
            __('loops.cards.marketplace.highlighted'),
            __('loops.cards.marketplace.note_saved'),
        );
    }

    public function test_each_edit_button_names_what_it_edits(): void
    {
        // Cinq boutons portaient le meme libelle « Corriger ce mot » : au
        // lecteur d'ecran, ils etaient indistinguables. La croix, elle, nommait
        // deja son element.
        $this->service()->highlightOffer($this->loop, $this->membre, $this->offre($this->membre, 'Premiere'));
        $this->service()->highlightOffer($this->loop, $this->membre, $this->offre($this->membre, 'Seconde'));

        $html = $this->card()->html();

        foreach (['Premiere', 'Seconde'] as $titre) {
            $this->assertStringContainsString(
                __('loops.cards.marketplace.edit_note_of', ['name' => $titre]),
                $html,
                $titre,
            );
        }
    }

    public function test_the_count_of_what_is_not_shown_reads_properly_in_both_numbers(): void
    {
        // « et 4 autre(s) non affiché(s) » : le pluriel entre parentheses est
        // ce qu'on ecrit quand on n'a pas voulu choisir.
        $un = trans_choice('loops.cards.marketplace.and_more', 1, ['count' => 1]);
        $plusieurs = trans_choice('loops.cards.marketplace.and_more', 4, ['count' => 4]);

        $this->assertStringNotContainsString('(s)', $un);
        $this->assertStringNotContainsString('(s)', $plusieurs);
        $this->assertStringContainsString('4', $plusieurs);
        $this->assertNotSame($un, $plusieurs);
    }

    // ── Aucune condition sur le type ────────────────────────────────────────

    public function test_no_business_code_branches_on_the_loop_type(): void
    {
        foreach ([
            app_path('Livewire/LoopMarketplaceCard.php'),
            app_path('Services/Loops/LoopMarketplaceService.php'),
            resource_path('views/livewire/loop-marketplace-card.blade.php'),
        ] as $fichier) {
            $source = file_get_contents($fichier);

            foreach (["\$loop->type ===", "\$loop->type =="] as $condition) {
                $this->assertStringNotContainsString($condition, $source, basename($fichier));
            }
        }
    }

    public function test_the_card_declares_a_data_count(): void
    {
        $this->service()->highlightOffer($this->loop, $this->membre, $this->offre());

        $composition = app(\App\Services\Loops\LoopCardCompositionService::class)->compositionFor($this->loop->fresh());
        $ligne = collect($composition)->firstWhere('key', 'core.marketplace');

        $this->assertSame(1, $ligne['data_count'] ?? null);
    }
}
