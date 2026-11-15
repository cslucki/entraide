<?php

namespace Tests\Feature\Livewire;

use App\Livewire\MessageThread;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Reaction;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1836 — `pollThread()` doit court-circuiter le rendu complet d'une
 * conversation DIRECTE quand rien n'a change, et JAMAIS quand quelque chose a
 * change ou que la conversation n'est pas directe. Chaque test mute l'etat
 * par un chemin DIFFERENT de celui du composant qui sonde, comme le ferait
 * une autre partie, dans une autre requete.
 */
class TASK1836MessageThreadPollFingerprintTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $buyer;

    private User $seller;

    private Transaction $transaction;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->buyer = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->seller = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->transaction = Transaction::factory()->create([
            'organization_id' => $this->organization->id,
            'buyer_id' => $this->buyer->id,
            'seller_id' => $this->seller->id,
            'status' => 'accepted',
            'points_proposed' => 0,
            'service_id' => null,
            'request_id' => null,
        ]);

        app()->instance('current_organization', $this->organization);
    }

    public function test_un_cycle_de_poll_sans_rien_de_change_court_circuite_le_rendu_complet(): void
    {
        Message::create([
            'transaction_id' => $this->transaction->id,
            'organization_id' => $this->organization->id,
            'sender_id' => $this->buyer->id,
            'body' => 'Un message avant la mesure.',
            'type' => 'user',
            'read_at' => now(),
        ]);

        $composant = Livewire::actingAs($this->buyer)->test(MessageThread::class, ['transaction' => $this->transaction]);
        $empreinteInitiale = $composant->get('pollFingerprint');
        $this->assertNotNull($empreinteInitiale);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $composant->call('pollThread');
        $requetes = DB::getQueryLog();
        DB::disableQueryLog();

        // Mesure de reference : un rendu complet coute 8 requetes dont UNE
        // ecriture. L'empreinte seule doit rester largement sous ce seuil et
        // ne JAMAIS ecrire.
        $this->assertLessThanOrEqual(4, count($requetes));
        foreach ($requetes as $r) {
            $this->assertStringStartsNotWith('update', strtolower($r['query']));
        }
        $this->assertSame($empreinteInitiale, $composant->get('pollFingerprint'));
    }

    public function test_un_cycle_inchange_n_ecrit_aucun_read_at_inutile(): void
    {
        Message::create([
            'transaction_id' => $this->transaction->id,
            'organization_id' => $this->organization->id,
            'sender_id' => $this->buyer->id,
            'body' => 'Deja lu.',
            'type' => 'user',
            'read_at' => now(),
        ]);

        $composant = Livewire::actingAs($this->buyer)->test(MessageThread::class, ['transaction' => $this->transaction]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $composant->call('pollThread');
        $ecritures = array_filter(DB::getQueryLog(), fn (array $r) => str_starts_with(strtolower($r['query']), 'update'));
        DB::disableQueryLog();

        $this->assertCount(0, $ecritures);
    }

    public function test_un_nouveau_message_relance_le_rendu_complet_et_marque_lu(): void
    {
        $composant = Livewire::actingAs($this->buyer)->test(MessageThread::class, ['transaction' => $this->transaction]);
        $empreinteInitiale = $composant->get('pollFingerprint');

        $message = Message::create([
            'transaction_id' => $this->transaction->id,
            'organization_id' => $this->organization->id,
            'sender_id' => $this->seller->id,
            'body' => 'Message arrive pendant le cycle de poll.',
            'type' => 'user',
        ]);

        $composant->call('pollThread');

        $this->assertNotSame($empreinteInitiale, $composant->get('pollFingerprint'));
        $composant->assertSee('Message arrive pendant le cycle de poll.');

        // Accuse de lecture : toujours pose, dans le MEME cycle qui affiche
        // le message — aucune regression par rapport au comportement actuel.
        $this->assertNotNull($message->fresh()->read_at);
    }

    public function test_une_deuxieme_edition_dans_la_meme_seconde_relance_le_rendu_complet(): void
    {
        $message = Message::create([
            'transaction_id' => $this->transaction->id,
            'organization_id' => $this->organization->id,
            'sender_id' => $this->buyer->id,
            'body' => 'Version 1.',
            'type' => 'user',
            'read_at' => now(),
        ]);

        $composant = Livewire::actingAs($this->buyer)->test(MessageThread::class, ['transaction' => $this->transaction]);

        Carbon::setTestNow(now());

        try {
            $message->forceFill(['body' => 'Version 2.', 'edited_at' => now(), 'edited_by' => $this->buyer->id])->save();
            $composant->call('pollThread');
            $empreinteApresV2 = $composant->get('pollFingerprint');
            $composant->assertSee('Version 2.');

            $editedAtApresV2 = DB::table('messages')->where('id', $message->id)->value('edited_at');

            $message->forceFill(['body' => 'Version 3.', 'edited_at' => now(), 'edited_by' => $this->buyer->id])->save();
            $editedAtApresV3 = DB::table('messages')->where('id', $message->id)->value('edited_at');

            $this->assertSame(
                $editedAtApresV2,
                $editedAtApresV3,
                'ce test suppose que les deux editions tombent dans la meme seconde stockee — sinon il ne demontre rien.'
            );

            $composant->call('pollThread');

            $this->assertNotSame($empreinteApresV2, $composant->get('pollFingerprint'));
            $composant->assertSee('Version 3.');
            $composant->assertDontSee('Version 2.');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_un_epinglage_relance_le_rendu_complet(): void
    {
        $message = Message::create([
            'transaction_id' => $this->transaction->id,
            'organization_id' => $this->organization->id,
            'sender_id' => $this->buyer->id,
            'body' => 'Message a epingler.',
            'type' => 'user',
            'read_at' => now(),
        ]);

        $composant = Livewire::actingAs($this->buyer)->test(MessageThread::class, ['transaction' => $this->transaction]);
        $empreinteInitiale = $composant->get('pollFingerprint');

        $message->pin($this->seller);

        $composant->call('pollThread');

        $this->assertNotSame($empreinteInitiale, $composant->get('pollFingerprint'));
    }

    public function test_un_changement_de_message_epingle_relance_le_rendu_complet(): void
    {
        $premier = Message::create([
            'transaction_id' => $this->transaction->id,
            'organization_id' => $this->organization->id,
            'sender_id' => $this->buyer->id,
            'body' => 'Premier message.',
            'type' => 'user',
            'read_at' => now(),
        ]);
        $second = Message::create([
            'transaction_id' => $this->transaction->id,
            'organization_id' => $this->organization->id,
            'sender_id' => $this->seller->id,
            'body' => 'Second message.',
            'type' => 'user',
            'read_at' => now(),
        ]);
        $premier->pin($this->buyer);

        $composant = Livewire::actingAs($this->buyer)->test(MessageThread::class, ['transaction' => $this->transaction]);
        $empreinteInitiale = $composant->get('pollFingerprint');

        // Meme geste que `pinMessage()` : depingler l'un, epingler l'autre.
        Message::where('transaction_id', $this->transaction->id)->whereNotNull('pinned_at')->update(['pinned_at' => null, 'pinned_by_id' => null]);
        $second->pin($this->seller);

        $composant->call('pollThread');

        $this->assertNotSame($empreinteInitiale, $composant->get('pollFingerprint'));
    }

    public function test_une_reaction_ajoutee_relance_le_rendu_complet(): void
    {
        $message = Message::create([
            'transaction_id' => $this->transaction->id,
            'organization_id' => $this->organization->id,
            'sender_id' => $this->buyer->id,
            'body' => 'Message reagi.',
            'type' => 'user',
            'read_at' => now(),
        ]);

        $composant = Livewire::actingAs($this->buyer)->test(MessageThread::class, ['transaction' => $this->transaction]);
        $empreinteInitiale = $composant->get('pollFingerprint');

        Reaction::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->seller->id,
            'reactionable_id' => $message->id,
            'reactionable_type' => Message::class,
            'reaction_type' => 'thumbs_up',
        ]);

        $composant->call('pollThread');

        $this->assertNotSame($empreinteInitiale, $composant->get('pollFingerprint'));
    }

    public function test_une_reaction_retiree_relance_le_rendu_complet(): void
    {
        $message = Message::create([
            'transaction_id' => $this->transaction->id,
            'organization_id' => $this->organization->id,
            'sender_id' => $this->buyer->id,
            'body' => 'Message avec reaction a retirer.',
            'type' => 'user',
            'read_at' => now(),
        ]);
        $reaction = Reaction::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->seller->id,
            'reactionable_id' => $message->id,
            'reactionable_type' => Message::class,
            'reaction_type' => 'thumbs_up',
        ]);

        $composant = Livewire::actingAs($this->buyer)->test(MessageThread::class, ['transaction' => $this->transaction]);
        $empreinteInitiale = $composant->get('pollFingerprint');

        $reaction->delete();

        $composant->call('pollThread');

        $this->assertNotSame($empreinteInitiale, $composant->get('pollFingerprint'));
    }

    public function test_un_changement_de_type_de_reaction_relance_le_rendu_complet(): void
    {
        $message = Message::create([
            'transaction_id' => $this->transaction->id,
            'organization_id' => $this->organization->id,
            'sender_id' => $this->buyer->id,
            'body' => 'Message avec reaction qui change de type.',
            'type' => 'user',
            'read_at' => now(),
        ]);
        $reaction = Reaction::create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->seller->id,
            'reactionable_id' => $message->id,
            'reactionable_type' => Message::class,
            'reaction_type' => 'thumbs_up',
        ]);

        $composant = Livewire::actingAs($this->buyer)->test(MessageThread::class, ['transaction' => $this->transaction]);
        $empreinteInitiale = $composant->get('pollFingerprint');

        // MEME ligne, MEME id : seul `reaction_type` change. `COUNT`+`MAX(id)`
        // seuls ne le verraient pas (cf. docblock de computePollFingerprint).
        $reaction->update(['reaction_type' => 'heart']);

        $composant->call('pollThread');

        $this->assertNotSame($empreinteInitiale, $composant->get('pollFingerprint'));
    }

    public function test_un_changement_de_statut_de_transaction_relance_le_rendu_complet(): void
    {
        Message::create([
            'transaction_id' => $this->transaction->id,
            'organization_id' => $this->organization->id,
            'sender_id' => $this->buyer->id,
            'body' => 'Message avant changement de statut.',
            'type' => 'user',
            'read_at' => now(),
        ]);

        $composant = Livewire::actingAs($this->buyer)->test(MessageThread::class, ['transaction' => $this->transaction]);
        $empreinteInitiale = $composant->get('pollFingerprint');

        $this->transaction->update(['status' => 'completed']);

        $composant->call('pollThread');

        $this->assertNotSame($empreinteInitiale, $composant->get('pollFingerprint'));
    }

    public function test_une_conversation_non_directe_ne_court_circuite_jamais(): void
    {
        $transactionEntraide = Transaction::factory()->create([
            'organization_id' => $this->organization->id,
            'buyer_id' => $this->buyer->id,
            'seller_id' => $this->seller->id,
            'status' => 'accepted',
            'points_proposed' => 50,
        ]);
        $this->assertFalse($transactionEntraide->isDirectConversation());

        Message::create([
            'transaction_id' => $transactionEntraide->id,
            'organization_id' => $this->organization->id,
            'sender_id' => $this->buyer->id,
            'body' => 'Message ordinaire sur un echange d\'entraide.',
            'type' => 'user',
            'read_at' => now(),
        ]);

        $composant = Livewire::actingAs($this->buyer)->test(MessageThread::class, ['transaction' => $transactionEntraide]);

        // Jamais amorcee pour une conversation non directe.
        $this->assertNull($composant->get('pollFingerprint'));

        DB::flushQueryLog();
        DB::enableQueryLog();
        $composant->call('pollThread');
        $requetes = DB::getQueryLog();
        DB::disableQueryLog();

        // Rendu complet : meme volume de requetes qu'un `render()` normal
        // (8), jamais celui, minuscule, d'une empreinte.
        $this->assertGreaterThanOrEqual(6, count($requetes));
    }

    public function test_une_revocation_d_acces_n_est_jamais_masquee_par_le_court_circuit(): void
    {
        $composant = Livewire::actingAs($this->buyer)->test(MessageThread::class, ['transaction' => $this->transaction]);
        $composant->assertOk();

        // Un tiers retire le `buyer_id` de la transaction (simule une
        // revocation d'acces) — le prochain `transaction(fresh: true)` DOIT
        // la detecter, AVANT toute decision de court-circuit : exactement le
        // meme `ModelNotFoundException` (404) que `render()` leve deja
        // aujourd'hui pour ce cas. Le court-circuit ne masque donc RIEN : il
        // n'est meme jamais atteint, la revalidation echoue avant.
        $etranger = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->transaction->update(['buyer_id' => $etranger->id]);

        $this->expectException(ModelNotFoundException::class);
        $composant->call('pollThread');
    }

    public function test_la_soupape_periodique_force_un_rendu_complet_au_bout_de_15s_pour_le_badge_global(): void
    {
        Message::create([
            'transaction_id' => $this->transaction->id,
            'organization_id' => $this->organization->id,
            'sender_id' => $this->buyer->id,
            'body' => 'Message stable.',
            'type' => 'user',
            'read_at' => now(),
        ]);

        $composant = Livewire::actingAs($this->buyer)->test(MessageThread::class, ['transaction' => $this->transaction]);
        $horodatageInitial = $composant->get('lastFullRenderAt');

        Carbon::setTestNow(now()->addSeconds(16));

        try {
            $composant->call('pollThread');

            $this->assertGreaterThan($horodatageInitial, $composant->get('lastFullRenderAt'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_la_soupape_ne_se_declenche_pas_trop_tot(): void
    {
        Message::create([
            'transaction_id' => $this->transaction->id,
            'organization_id' => $this->organization->id,
            'sender_id' => $this->buyer->id,
            'body' => 'Message stable.',
            'type' => 'user',
            'read_at' => now(),
        ]);

        $composant = Livewire::actingAs($this->buyer)->test(MessageThread::class, ['transaction' => $this->transaction]);
        $horodatageInitial = $composant->get('lastFullRenderAt');

        Carbon::setTestNow(now()->addSeconds(5));

        try {
            $composant->call('pollThread');

            $this->assertSame($horodatageInitial, $composant->get('lastFullRenderAt'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_un_brouillon_survit_a_un_cycle_court_circuite(): void
    {
        Message::create([
            'transaction_id' => $this->transaction->id,
            'organization_id' => $this->organization->id,
            'sender_id' => $this->buyer->id,
            'body' => 'Message stable.',
            'type' => 'user',
            'read_at' => now(),
        ]);

        $composant = Livewire::actingAs($this->buyer)->test(MessageThread::class, ['transaction' => $this->transaction])
            ->set('newMessage', 'Brouillon en cours de redaction...');

        $composant->call('pollThread');

        $composant->assertSet('newMessage', 'Brouillon en cours de redaction...');
    }

    public function test_les_nouvelles_proprietes_publiques_sont_verrouillees(): void
    {
        $composant = Livewire::actingAs($this->buyer)->test(MessageThread::class, ['transaction' => $this->transaction]);

        $this->expectException(CannotUpdateLockedPropertyException::class);
        $composant->set('pollFingerprint', 'valeur-forgee');
    }

    public function test_les_nouvelles_proprietes_publiques_sont_verrouillees_horodatage(): void
    {
        $composant = Livewire::actingAs($this->buyer)->test(MessageThread::class, ['transaction' => $this->transaction]);

        $this->expectException(CannotUpdateLockedPropertyException::class);
        $composant->set('lastFullRenderAt', 999999999);
    }
}
