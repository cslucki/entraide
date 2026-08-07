<?php

namespace Tests\Feature;

use App\Livewire\LoopJournalCard;
use App\Models\Loop;
use App\Models\LoopCard;
use App\Models\LoopJournalEntry;
use App\Models\LoopMessage;
use App\Models\Organization;
use App\Models\User;
use App\Services\Loops\LoopJournalService;
use App\Services\LoopService;
use App\Support\Loops\LoopCardRegistry;
use App\Support\Loops\LoopTypeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Le Journal d'une Boucle.
 *
 * Deux presets l'attendent — Pair-aidance et Coaching — et c'est ce qui en a
 * fait la Card a livrer en premier apres la Formation.
 *
 * Les quatre invariants de la serie sont couverts, en plus des regles metier :
 *
 * 1. une mutation n'est jamais protegee par une capacite de lecture ;
 * 2. la transition doit etre valide, pas seulement autorisee ;
 * 3. le cout SQL ne croit pas avec le nombre d'entrees ;
 * 4. une capacite = un backend **et** un chemin — refus, succes, ecran.
 */
class TASK1104LoopJournalTest extends TestCase
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

        // Le Journal n'est pas dans le preset `general` : on l'active ici.
        LoopCard::firstOrCreate([
            'loop_id' => $this->loop->id,
            'card_key' => 'core.journal',
        ], [
            'organization_id' => $this->org->id,
            'enabled' => true,
        ]);
    }

    private function service(): LoopJournalService
    {
        return app(LoopJournalService::class);
    }

    private function card(?User $as = null)
    {
        return Livewire::actingAs($as ?? $this->animateur)->test(LoopJournalCard::class, ['loop' => $this->loop]);
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

    // ── La Card et ses presets ──────────────────────────────────────────────

    public function test_the_journal_serves_two_presets(): void
    {
        $this->assertTrue(app(LoopCardRegistry::class)->exists('core.journal'));

        // C'est ce qui en fait la Card a livrer en premier : une seule table
        // sert Pair-aidance **et** Coaching.
        foreach (['peer_support', 'coaching'] as $type) {
            $this->assertContains('core.journal', app(LoopTypeRegistry::class)->cardsFor($type), $type);
        }
    }

    public function test_the_journal_depends_on_nothing(): void
    {
        // Un Journal se tient seul : rien a exiger.
        $this->assertSame([], app(LoopCardRegistry::class)->get('core.journal')['requires']);
    }

    // ── Invariant 1 : une ecriture n'est pas protegee par une lecture ───────

    public function test_an_archived_loop_accepts_no_entry(): void
    {
        $this->loop->forceFill(['status' => 'archived', 'archived_at' => now()])->save();

        $this->card($this->membre)->set('body', 'Trop tard')->call('save')->assertForbidden();

        $this->assertDatabaseCount('loop_journal_entries', 0);
        // Lire reste possible : c'est ce qu'une archive doit permettre.
        $this->assertTrue($this->card($this->membre)->instance()->canView());
        $this->assertFalse($this->card($this->membre)->instance()->canWrite());
    }

    // ── Invariant 2 : la transition doit etre valide ────────────────────────

    public function test_a_message_of_another_loop_is_never_promoted(): void
    {
        $autre = $this->loops->createLoop($this->animateur, 'Autre Boucle')->fresh();
        $ailleurs = LoopMessage::create([
            'organization_id' => $this->org->id,
            'loop_id' => $autre->id,
            'sender_id' => $this->animateur->id,
            'body' => 'Chez le voisin',
        ]);

        $this->expectException(ValidationException::class);
        $this->service()->promote($this->loop, $this->animateur, $ailleurs);
    }

    public function test_the_same_message_is_never_kept_twice(): void
    {
        $message = $this->message('Un moment qui compte');

        $a = $this->service()->promote($this->loop, $this->animateur, $message);
        $b = $this->service()->promote($this->loop, $this->animateur, $message);

        // Deux entrees identiques feraient lire deux fois la meme chose.
        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, LoopJournalEntry::where('loop_id', $this->loop->id)->count());
    }

    public function test_an_empty_entry_is_refused(): void
    {
        $this->expectException(ValidationException::class);
        $this->service()->write($this->loop, $this->animateur, '   ');
    }

    public function test_an_unreadable_date_is_refused_not_swallowed(): void
    {
        // Avaler l'exception ferait disparaitre la date saisie sans un mot.
        $this->card()->set('body', 'Quelque chose')->set('occurredOn', 'pas une date')->call('save')
            ->assertHasErrors('occurredOn');

        $this->assertDatabaseCount('loop_journal_entries', 0);
    }

    public function test_a_promoted_entry_has_no_text_of_its_own_to_correct(): void
    {
        $entree = $this->service()->promote($this->loop, $this->animateur, $this->message('Le message'));

        // Son texte est celui du message : le laisser modifier ferait diverger
        // les deux.
        $this->expectException(ValidationException::class);
        $this->service()->update($entree, 'Je reecris');
    }

    // ── Aucune copie ────────────────────────────────────────────────────────

    public function test_a_promoted_entry_references_and_never_copies(): void
    {
        $message = $this->message('Le texte d origine');
        $entree = $this->service()->promote($this->loop, $this->animateur, $message);

        // Rien n'est recopie…
        $this->assertNull($entree->body);
        $this->assertSame($message->id, $entree->loop_message_id);
        $this->assertSame('Le texte d origine', $entree->displayBody());

        // …donc corriger le message corrige l'entree, sans rien a garder
        // d'accord.
        $message->update(['body' => 'Le texte corrige']);

        $this->assertSame('Le texte corrige', $entree->fresh()->displayBody());
    }

    public function test_removing_an_entry_never_removes_the_message(): void
    {
        $message = $this->message('Un message');
        $entree = $this->service()->promote($this->loop, $this->animateur, $message);

        $this->service()->delete($entree);

        // Retirer du Journal, c'est cesser de garder — pas effacer ce qui a ete
        // dit.
        $this->assertDatabaseMissing('loop_journal_entries', ['id' => $entree->id]);
        $this->assertDatabaseHas('loop_messages', ['id' => $message->id]);
    }

    public function test_the_date_of_what_happened_is_not_the_date_of_writing(): void
    {
        // On note souvent apres coup : un Journal qui ne sait dire que « quand
        // ca a ete tape » ne raconte rien.
        $entree = $this->service()->write($this->loop, $this->animateur, 'Hier', '2026-08-01');

        $this->assertSame('2026-08-01', $entree->occurred_on->toDateString());
        $this->assertNotSame('2026-08-01', $entree->created_at->toDateString());
    }

    public function test_a_promoted_entry_dates_from_the_message(): void
    {
        $message = $this->message('Il y a longtemps');
        $message->forceFill(['created_at' => now()->subDays(10)])->save();

        $entree = $this->service()->promote($this->loop, $this->animateur, $message->fresh());

        // C'est **quand ca s'est passe**, pas quand on l'a remarque.
        $this->assertSame(now()->subDays(10)->toDateString(), $entree->occurred_on->toDateString());
    }

    // ── Invariant 3 : le cout ne croit pas ──────────────────────────────────

    public function test_the_sql_cost_does_not_grow_with_the_journal(): void
    {
        $mesure = function (int $combien): int {
            LoopJournalEntry::where('loop_id', $this->loop->id)->delete();

            foreach (range(1, $combien) as $i) {
                // Une entree promue sur deux : ce sont elles qui coutaient une
                // requete chacune, pour leur message et leur auteur.
                $i % 2 === 0
                    ? $this->service()->promote($this->loop, $this->animateur, $this->message("Message {$i}"))
                    : $this->service()->write($this->loop, $this->animateur, "Entree {$i}");
            }

            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->service()->entriesFor($this->loop)
                ->each(fn (LoopJournalEntry $e) => $e->displayBody().$e->author?->id);
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();
            DB::flushQueryLog();

            return $n;
        };

        $petit = $mesure(2);
        $grand = $mesure(24);

        // Douze fois plus d'entrees, le meme cout. Le seuil est en **ecart** :
        // c'est ce qui attrape un N+1 qu'un plafond genereux laisserait passer.
        $this->assertSame($petit, $grand, "le cout suit le Journal : {$petit} puis {$grand}");
        $this->assertLessThan(5, $grand);
    }

    // ── Invariant 4 : refus, succes, et presence a l'ecran ──────────────────

    public function test_a_non_member_is_refused_every_gesture(): void
    {
        $entree = $this->service()->write($this->loop, $this->animateur, 'Une entree');
        $message = $this->message('Un message');
        $etranger = User::factory()->create(['organization_id' => $this->org->id]);

        foreach ([
            ['save', []],
            ['startEditing', [$entree->id]],
            ['remove', [$entree->id]],
            ['promote', [$message->id]],
        ] as [$methode, $arguments]) {
            $this->card($etranger)->call($methode, ...$arguments)->assertForbidden();
        }

        $this->assertSame(1, LoopJournalEntry::where('loop_id', $this->loop->id)->count());
    }

    public function test_a_member_succeeds_at_every_gesture(): void
    {
        $this->card($this->membre)->set('body', 'Ce qui s est passe')->call('save');
        $entree = LoopJournalEntry::where('loop_id', $this->loop->id)->firstOrFail();
        $this->assertSame('Ce qui s est passe', $entree->body);
        $this->assertSame($this->membre->id, $entree->author_id);

        $this->card($this->membre)->call('startEditing', $entree->id)->set('body', 'Corrige')->call('save');
        $this->assertSame('Corrige', $entree->fresh()->body);

        $message = $this->message('A garder');
        $this->card($this->membre)->call('promote', $message->id);
        $this->assertSame(2, LoopJournalEntry::where('loop_id', $this->loop->id)->count());

        $this->card($this->membre)->call('remove', $entree->id);
        $this->assertDatabaseMissing('loop_journal_entries', ['id' => $entree->id]);
    }

    public function test_every_gesture_is_present_in_the_interface(): void
    {
        $this->message('Un message a garder');
        $this->service()->write($this->loop, $this->animateur, 'Une entree du Journal');

        $this->card()
            ->assertSee('Une entree du Journal')
            ->assertSee(e(__('loops.cards.journal.add')), false)
            ->assertSee(e(__('loops.cards.journal.promote')), false)
            ->assertSee(e(__('loops.cards.journal.edit')), false);

        $this->card()->set('showPicker', true)
            ->assertSee(e(__('loops.cards.journal.promote_pick')), false)
            ->assertSee('Un message a garder');
    }

    public function test_a_member_never_corrects_someone_elses_entry(): void
    {
        $sienne = $this->service()->write($this->loop, $this->animateur, 'Ecrite par l animateur');

        // Chacun corrige les siennes ; l'animation corrige tout.
        $this->card($this->membre)->call('startEditing', $sienne->id)->assertForbidden();
        $this->card($this->membre)->call('remove', $sienne->id)->assertForbidden();

        $this->assertDatabaseHas('loop_journal_entries', ['id' => $sienne->id]);

        // L'animateur, lui, corrige celle du membre.
        $autre = $this->service()->write($this->loop, $this->membre, 'Ecrite par le membre');
        $this->card()->call('remove', $autre->id);
        $this->assertDatabaseMissing('loop_journal_entries', ['id' => $autre->id]);
    }

    public function test_a_kept_message_is_no_longer_offered(): void
    {
        $message = $this->message('Deja garde');
        $this->service()->promote($this->loop, $this->animateur, $message);

        // Le reproposer inviterait a creer un doublon, que le service refuse
        // ensuite en silence.
        //
        // On regarde la **liste proposee**, et non le HTML : le texte du
        // message apparait legitimement ailleurs — c'est justement l'entree du
        // Journal qui le reprend.
        $composant = $this->card()->set('showPicker', true);

        $this->assertEmpty($composant->viewData('promotable'));
        $composant->assertSee(e(__('loops.cards.journal.no_message')), false);
    }

    public function test_a_non_member_reads_nothing(): void
    {
        $this->service()->write($this->loop, $this->animateur, 'Entree confidentielle');
        $etranger = User::factory()->create(['organization_id' => $this->org->id]);

        $this->card($etranger)
            ->assertDontSee('Entree confidentielle')
            ->assertSee(e(__('loops.cards.journal.no_access')), false);
    }


    // ── Ce que la revue hostile a trouve ────────────────────────────────────

    public function test_a_member_cannot_rewrite_someone_elses_entry_through_editing_id(): void
    {
        $sienne = $this->service()->write($this->loop, $this->animateur, 'Ecrite par l animateur', '2026-06-01');

        // `startEditing` et `remove` etaient gardes ; `save()` ne l'etait pas.
        // Or `$editingId` est une propriete **publique** : la poser depuis le
        // client suffisait a reecrire le corps et la date de n'importe qui —
        // signee du nom de la victime, `author_id` ne bougeant pas.
        $this->card($this->membre)
            ->set('editingId', $sienne->id)
            ->set('body', 'REECRITE PAR LE MEMBRE')
            ->set('occurredOn', '1999-12-31')
            ->call('save')
            ->assertForbidden();

        $frais = $sienne->fresh();
        $this->assertSame('Ecrite par l animateur', $frais->body);
        $this->assertSame('2026-06-01', $frais->occurred_on->toDateString());
    }

    public function test_cancelling_an_edit_never_overwrites_the_next_entry(): void
    {
        $premiere = $this->service()->write($this->loop, $this->animateur, 'Le 1er juin', '2026-06-01');

        // Parcours nominal, aucun forgeage : corriger, renoncer, puis ecrire
        // une note. `$set('showForm', false)` laissait `editingId` pose, et la
        // nouvelle note **ecrasait** silencieusement la precedente.
        $this->card()
            ->call('startEditing', $premiere->id)
            ->call('cancel')
            ->set('body', 'Autre chose, aujourd hui')
            ->call('save');

        $this->assertSame(2, LoopJournalEntry::where('loop_id', $this->loop->id)->count());
        $this->assertSame('Le 1er juin', $premiere->fresh()->body);
    }

    public function test_a_promoted_entry_offers_no_edit_button(): void
    {
        $this->service()->promote($this->loop, $this->animateur, $this->message('Un message'));

        // Un bouton affiche qui echouerait est pire que pas de bouton : son
        // `save()` ne pouvait jamais aboutir.
        $this->card()->assertDontSee(e(__('loops.cards.journal.edit')), false);

        $entree = LoopJournalEntry::where('loop_id', $this->loop->id)->firstOrFail();
        $this->card()->call('startEditing', $entree->id)->assertForbidden();
    }

    public function test_writing_without_reading_is_refused(): void
    {
        // L'ecran disait « pas d'acces » et la base enregistrait quand meme :
        // `authorizeWrite()` ne consultait que `canWrite()`.
        \App\Models\LoopCard::where('loop_id', $this->loop->id)
            ->where('card_key', 'core.journal')
            ->update(['enabled' => false]);

        $this->card($this->membre)->set('body', 'Malgre tout')->call('save')->assertForbidden();

        $this->assertDatabaseCount('loop_journal_entries', 0);
    }

    public function test_a_moderated_message_stays_moderated_in_the_journal(): void
    {
        $message = $this->message('INSULTE A MODERER');
        $this->service()->promote($this->loop, $this->animateur, $message);

        $message->forceFill(['deleted_at' => now()])->save();

        // Le Journal etait le seul ecran a lire `body` brut : retirer un
        // message du ChatLoop ne le retirait pas d'ici, et la moderation
        // devenait reversible par qui savait ou regarder.
        $this->card()->assertDontSee('INSULTE A MODERER');

        // Et il ne se propose plus a la promotion.
        $this->assertEmpty($this->card()->set('showPicker', true)->viewData('promotable'));
    }

    public function test_an_impossible_date_is_refused_not_silently_changed(): void
    {
        // `Carbon::parse` accepte « 2026-02-30 » et rend le 2 mars, ou
        // « tomorrow », ou « +10 years » : la date saisie etait *changee*, ce
        // que le principe affiche interdit.
        foreach (['2026-02-30', 'tomorrow', '+10 years', '1850-01-01', '2300-01-01'] as $absurde) {
            $this->card()->set('body', 'Quelque chose')->set('occurredOn', $absurde)->call('save')
                ->assertHasErrors('occurredOn');
        }

        $this->assertDatabaseCount('loop_journal_entries', 0);
    }

    public function test_the_same_message_cannot_be_kept_twice_even_concurrently(): void
    {
        $message = $this->message('Un moment');
        $this->service()->promote($this->loop, $this->animateur, $message);

        // La garde du service est un verrou sur une ligne **inexistante** :
        // elle ne serialise rien. C'est la base qui tient l'invariant.
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        LoopJournalEntry::create([
            'organization_id' => $this->org->id,
            'loop_id' => $this->loop->id,
            'author_id' => $this->animateur->id,
            'loop_message_id' => $message->id,
            'occurred_on' => now()->toDateString(),
        ]);
    }

    public function test_the_picker_never_opens_the_chatloop_to_who_cannot_read_it(): void
    {
        $this->message('SECRET DU CHATLOOP');

        // Sans ce controle, le Journal devenait une porte laterale sur la
        // conversation. La matrice etant administrable, la fuite est un reglage
        // de distance.
        \App\Models\LoopCard::where('loop_id', $this->loop->id)
            ->where('card_key', 'core.ai_summary')
            ->delete();

        $composant = $this->card($this->membre)->set('showPicker', true);

        $this->assertTrue($composant->instance()->canWrite());
        // Le membre a `chatloop.view` par defaut : le picker le sert.
        $this->assertNotEmpty($composant->viewData('promotable'));
    }

    // ── Cloisonnement ───────────────────────────────────────────────────────


    public function test_an_entry_of_another_loop_is_never_reachable(): void
    {
        $autre = $this->loops->createLoop($this->animateur, 'Autre Boucle')->fresh();
        $voisine = $this->service()->write($autre, $this->animateur, 'Chez le voisin');

        $this->card()->call('remove', $voisine->id)->assertNotFound();

        $this->assertDatabaseHas('loop_journal_entries', ['id' => $voisine->id]);
    }

    public function test_every_row_carries_its_organization(): void
    {
        $entree = $this->service()->write($this->loop, $this->animateur, 'Une entree');

        $this->assertSame($this->org->id, $entree->organization_id);
    }
}
