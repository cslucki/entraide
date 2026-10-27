<?php

namespace Tests\Feature;

use App\Models\FeedPost;
use App\Models\Loop;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Reaction;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use Tests\TestCase;

/**
 * TASK-1691 — aucune identite de tenant n'est a la main du client.
 *
 * ## Les deux P0 mesures en TASK-1690
 *
 * L'endpoint Livewire est mieux garde qu'il n'y parait : `AppServiceProvider`
 * declare `Livewire::addPersistentMiddleware(ResolveOrganization::class)`, et
 * Livewire rejoue les middlewares persistants — `Authenticate` inclus — sur une
 * requete reconstruite depuis le chemin SIGNE du snapshot. Un appel anonyme
 * rend 401, et `memo.path` n'est pas falsifiable.
 *
 * Le defaut n'etait donc pas « Livewire ». Livewire hydrate les proprietes
 * publiques depuis le snapshot **puis applique les `updates` du client** : une
 * propriete publique sans `#[Locked]` est **ecrite par l'appelant**. Deux
 * composants y rangeaient l'identite du tenant.
 *
 * | composant | propriete | consequence mesuree |
 * |---|---|---|
 * | `CreateFeedPost` | `$orgId` | **lecture** des Boucles d'une autre Organization |
 * | `MessageThread` | `$organizationId` + `$transactionId` | **lecture** d'une conversation privee **et ECRITURE** (`messages.read_at`) |
 *
 * ## Ce que cette suite exige
 *
 * Toutes les mesures passent par le **vrai endpoint** — jamais `Livewire::test()`,
 * qui court-circuite le middleware persistant (`if (! isLivewireRoute()) return;`)
 * et ne prouverait donc rien de la production. L'URI est resolue a l'execution :
 * son prefixe est derive de l'environnement, et le coder en dur rend un 404
 * trompeur qui ressemble a une protection.
 *
 * Chaque cas d'attaque est double d'un **temoin** (meme snapshot, sans
 * falsification) et d'un releve de l'ETAT DES DONNEES avant/apres : un code HTTP
 * seul ne prouve rien.
 */
class TASK1691LivewireTenantIdentityTest extends TestCase
{
    private Organization $a;

    private Organization $b;

    private User $attaquant;

    private User $b1;

    private User $b2;

    private Transaction $txA;

    private Transaction $txB;

    private Message $msgB;

    private Loop $loopB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = Organization::factory()->create([
            'slug' => 't1691-a', 'name' => 'Org 1691 A',
            'is_active' => true, 'is_public' => true, 'feed_post_publish_mode' => 'members',
        ]);
        $this->b = Organization::factory()->create([
            'slug' => 't1691-b', 'name' => 'Org 1691 B',
            'is_active' => true, 'is_public' => false, 'feed_post_publish_mode' => 'members',
        ]);

        $this->attaquant = User::factory()->complete()->create(['organization_id' => $this->a->id]);
        $pairA = User::factory()->complete()->create(['organization_id' => $this->a->id]);
        $this->b1 = User::factory()->complete()->create(['organization_id' => $this->b->id]);
        $this->b2 = User::factory()->complete()->create(['organization_id' => $this->b->id]);

        $this->loopB = Loop::factory()->create([
            'organization_id' => $this->b->id, 'created_by' => $this->b2->id,
            'name' => 'BOUCLE-PRIVEE-DE-B-1691', 'slug' => 'boucle-privee-de-b-1691',
            'description' => 'DESCRIPTION-PRIVEE-DE-B-1691', 'visibility' => 'private',
        ]);
        Loop::factory()->create([
            'organization_id' => $this->a->id, 'created_by' => $this->attaquant->id,
            'name' => 'BOUCLE-DE-A-1691', 'slug' => 'boucle-de-a-1691',
        ]);

        $this->txA = Transaction::factory()->create([
            'organization_id' => $this->a->id, 'buyer_id' => $this->attaquant->id,
            'seller_id' => $pairA->id, 'status' => 'pending',
        ]);
        Message::create([
            'transaction_id' => $this->txA->id, 'organization_id' => $this->a->id,
            'sender_id' => $pairA->id, 'body' => 'MESSAGE-DE-A-1691',
        ]);

        $this->txB = Transaction::factory()->create([
            'organization_id' => $this->b->id, 'buyer_id' => $this->b1->id,
            'seller_id' => $this->b2->id, 'status' => 'pending',
        ]);
        $this->msgB = Message::create([
            'transaction_id' => $this->txB->id, 'organization_id' => $this->b->id,
            'sender_id' => $this->b2->id, 'body' => 'CONVERSATION-PRIVEE-DE-B-1691',
        ]);
    }

    // =====================================================================
    // CreateFeedPost — P0 #1
    // =====================================================================

    public function test_a_a_foreign_organization_id_no_longer_lists_its_loops(): void
    {
        $snapshot = $this->snapshotDe('/org/t1691-a/flux/creer', $this->attaquant, 'create-feed-post');

        // Temoin : la meme requete sans falsification voit SES Boucles, pas celles de B.
        $temoin = $this->update($this->attaquant, $snapshot, []);
        $temoin->assertOk();
        $this->assertStringContainsString('BOUCLE-DE-A-1691', $temoin->getContent(), 'le temoin ne voit plus ses propres Boucles');
        $this->assertStringNotContainsString('BOUCLE-PRIVEE-DE-B-1691', $temoin->getContent());

        // L'attaque.
        $reponse = $this->update($this->attaquant, $snapshot, ['orgId' => $this->b->id]);

        $corps = $reponse->getContent();
        $this->assertStringNotContainsString('BOUCLE-PRIVEE-DE-B-1691', $corps, 'le NOM d\'une Boucle de B fuit encore');
        $this->assertStringNotContainsString('DESCRIPTION-PRIVEE-DE-B-1691', $corps, 'la DESCRIPTION d\'une Boucle de B fuit encore');
        $this->assertStringNotContainsString($this->loopB->id, $corps, 'l\'ID d\'une Boucle de B fuit encore');
    }

    /** Le sens inverse : un membre de B ne lit pas davantage les Boucles de A. */
    public function test_a_the_leak_is_closed_in_both_directions(): void
    {
        $snapshot = $this->snapshotDe('/org/t1691-b/flux/creer', $this->b1, 'create-feed-post');

        $reponse = $this->update($this->b1, $snapshot, ['orgId' => $this->a->id]);

        $this->assertStringNotContainsString('BOUCLE-DE-A-1691', $reponse->getContent());
    }

    /** `#[Locked]` ne suffirait pas seul : la preuve d'appartenance garde la DONNEE. */
    public function test_a_the_membership_proof_guards_render_itself(): void
    {
        $composant = new \App\Livewire\CreateFeedPost;
        $composant->orgId = $this->b->id;

        $this->actingAs($this->attaquant);

        $org = (new \ReflectionClass($composant))->getMethod('org');
        $org->setAccessible(true);

        $this->assertNull($org->invoke($composant),
            'un orgId etranger pose directement rend encore une Organization');
    }

    /** Et le parcours legitime n'a rien perdu : il publie toujours chez lui. */
    public function test_a_a_member_still_publishes_in_his_own_organization(): void
    {
        $snapshot = $this->snapshotDe('/org/t1691-a/flux/creer', $this->attaquant, 'create-feed-post');

        $avant = FeedPost::query()->where('organization_id', $this->a->id)->count();

        $this->update($this->attaquant, $snapshot,
            ['content' => 'ANNONCE-LEGITIME-1691', 'mode' => 'publish'],
            [['path' => '', 'method' => 'submit', 'params' => []]],
        );

        $this->assertSame($avant + 1, FeedPost::query()->where('organization_id', $this->a->id)->count(),
            'le parcours legitime de publication a ete casse');
        $this->assertDatabaseHas('feed_posts', [
            'organization_id' => $this->a->id,
            'user_id' => $this->attaquant->id,
            'content' => 'ANNONCE-LEGITIME-1691',
        ]);
    }

    /** Aucune ecriture dans B — elle etait deja refusee, elle le reste. */
    public function test_a_no_write_reaches_the_foreign_organization(): void
    {
        $snapshot = $this->snapshotDe('/org/t1691-a/flux/creer', $this->attaquant, 'create-feed-post');

        $avant = FeedPost::query()->where('organization_id', $this->b->id)->count();

        $this->update($this->attaquant, $snapshot,
            ['orgId' => $this->b->id, 'content' => 'INJECTE-DANS-B-1691', 'mode' => 'publish'],
            [['path' => '', 'method' => 'submit', 'params' => []]],
        );

        $this->assertSame($avant, FeedPost::query()->where('organization_id', $this->b->id)->count());
        $this->assertDatabaseMissing('feed_posts', ['content' => 'INJECTE-DANS-B-1691']);
    }

    // =====================================================================
    // MessageThread — P0 #2 : lecture ET ecriture
    // =====================================================================

    public function test_b_a_foreign_conversation_is_no_longer_readable(): void
    {
        $snapshot = $this->snapshotDe('/org/t1691-a/messages/'.$this->txA->id, $this->attaquant, 'message-thread');

        // Temoin.
        $temoin = $this->update($this->attaquant, $snapshot, []);
        $temoin->assertOk();
        $this->assertStringContainsString('MESSAGE-DE-A-1691', $temoin->getContent(), 'le temoin ne voit plus sa propre conversation');
        $this->assertStringNotContainsString('CONVERSATION-PRIVEE-DE-B-1691', $temoin->getContent());

        $reponse = $this->update($this->attaquant, $snapshot, [
            'organizationId' => $this->b->id, 'transactionId' => $this->txB->id,
        ]);

        $corps = $reponse->getContent();
        $this->assertStringNotContainsString('CONVERSATION-PRIVEE-DE-B-1691', $corps, 'le CORPS d\'une conversation privee de B fuit encore');
        $this->assertStringNotContainsString($this->b2->name, $corps, 'le NOM d\'un membre de B fuit encore');
    }

    /** L'ecriture mesuree en T1690 : `read_at` d'un tenant etranger. */
    public function test_b_no_foreign_message_is_marked_read(): void
    {
        $snapshot = $this->snapshotDe('/org/t1691-a/messages/'.$this->txA->id, $this->attaquant, 'message-thread');

        $this->assertNull($this->msgB->fresh()->read_at, 'la fixture doit partir non lue');

        // Le simple rendu suffisait : `render()` appelle `markRead()`.
        $this->update($this->attaquant, $snapshot, [
            'organizationId' => $this->b->id, 'transactionId' => $this->txB->id,
        ]);

        $this->assertNull($this->msgB->fresh()->read_at,
            'le rendu marque encore comme lu un message d\'une autre Organization');

        // Et l'appel direct de la methode.
        $this->update($this->attaquant, $snapshot, [
            'organizationId' => $this->b->id, 'transactionId' => $this->txB->id,
        ], [['path' => '', 'method' => 'markRead', 'params' => []]]);

        $this->assertSame(0, Message::query()->where('transaction_id', $this->txB->id)->whereNotNull('read_at')->count(),
            'markRead() ecrit encore dans une autre Organization');
    }

    /** Les ecritures de contenu etaient deja refusees : contre-epreuve qu'elles le restent. */
    public function test_b_no_content_write_reaches_the_foreign_conversation(): void
    {
        $snapshot = $this->snapshotDe('/org/t1691-a/messages/'.$this->txA->id, $this->attaquant, 'message-thread');

        $messages = Message::query()->where('transaction_id', $this->txB->id)->count();
        $reactions = Reaction::query()->where('reactionable_id', $this->msgB->id)->count();

        $this->update($this->attaquant, $snapshot, [
            'organizationId' => $this->b->id, 'transactionId' => $this->txB->id, 'newMessage' => 'INJECTE-DANS-B-1691',
        ], [['path' => '', 'method' => 'sendMessage', 'params' => []]]);

        $this->update($this->attaquant, $snapshot, [
            'organizationId' => $this->b->id, 'transactionId' => $this->txB->id,
        ], [['path' => '', 'method' => 'toggleReaction', 'params' => [$this->msgB->id, Reaction::REACTION_TYPES[0]]]]);

        $this->assertSame($messages, Message::query()->where('transaction_id', $this->txB->id)->count());
        $this->assertDatabaseMissing('messages', ['body' => 'INJECTE-DANS-B-1691']);
        $this->assertSame($reactions, Reaction::query()->where('reactionable_id', $this->msgB->id)->count());
    }

    /**
     * L'ORACLE D'EXISTENCE se referme : un UUID reel d'une autre Organization et
     * un UUID inexistant rendent desormais la MEME chose.
     */
    public function test_b_the_existence_oracle_is_closed(): void
    {
        $snapshot = $this->snapshotDe('/org/t1691-a/messages/'.$this->txA->id, $this->attaquant, 'message-thread');

        $reel = $this->update($this->attaquant, $snapshot, [
            'organizationId' => $this->b->id, 'transactionId' => $this->txB->id,
        ]);

        $inexistant = $this->update($this->attaquant, $snapshot, [
            'organizationId' => $this->b->id, 'transactionId' => (string) Str::uuid(),
        ]);

        $this->assertSame($inexistant->getStatusCode(), $reel->getStatusCode(),
            'le statut distingue encore une transaction existante d\'une inexistante');
    }

    /** Le parcours legitime : chacun lit SA conversation, et elle se marque lue. */
    public function test_b_a_participant_still_reads_and_marks_his_own_thread(): void
    {
        $snapshot = $this->snapshotDe('/org/t1691-b/messages/'.$this->txB->id, $this->b1, 'message-thread');

        $reponse = $this->update($this->b1, $snapshot, []);

        $reponse->assertOk();
        $this->assertStringContainsString('CONVERSATION-PRIVEE-DE-B-1691', $reponse->getContent(),
            'un participant ne lit plus sa propre conversation');
        $this->assertNotNull($this->msgB->fresh()->read_at,
            'la conversation d\'un participant ne se marque plus lue');
    }

    /** Et il envoie toujours un message chez lui. */
    public function test_b_a_participant_still_sends_a_message(): void
    {
        $snapshot = $this->snapshotDe('/org/t1691-b/messages/'.$this->txB->id, $this->b1, 'message-thread');

        $this->update($this->b1, $snapshot, ['newMessage' => 'MESSAGE-LEGITIME-1691'],
            [['path' => '', 'method' => 'sendMessage', 'params' => []]]);

        $this->assertDatabaseHas('messages', [
            'transaction_id' => $this->txB->id,
            'sender_id' => $this->b1->id,
            'body' => 'MESSAGE-LEGITIME-1691',
        ]);
    }

    /**
     * Un membre de B qui n'est NI acheteur NI vendeur ne lit pas la conversation
     * de ses collegues. La preuve de participation n'est pas un filtre tenant.
     */
    public function test_b_a_mere_member_of_the_same_organization_is_not_a_participant(): void
    {
        $tiers = User::factory()->complete()->create(['organization_id' => $this->b->id]);

        $snapshot = $this->snapshotDe('/org/t1691-b/messages/'.$this->txB->id, $this->b1, 'message-thread');

        $reponse = $this->update($tiers, $snapshot, []);

        $this->assertStringNotContainsString('CONVERSATION-PRIVEE-DE-B-1691', $reponse->getContent(),
            'un simple membre de la meme Organization lit la conversation d\'autrui');
    }

    // =====================================================================
    // Ce qui protegeait deja, et qui ne doit pas se perdre
    // =====================================================================

    /** L'appel anonyme reste refuse par le middleware persistant. */
    public function test_c_an_anonymous_call_is_still_refused(): void
    {
        $snapshot = $this->snapshotDe('/org/t1691-a/messages/'.$this->txA->id, $this->attaquant, 'message-thread');

        Auth::logout();
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        // Le CONTROLE d'abord : sans lui, la mesure suivante ne vaut rien.
        $this->get('/org/t1691-a/messages/'.$this->txA->id)->assertRedirect();

        $reponse = $this->withHeaders(['X-Livewire' => 'true'])->postJson(
            app(HandleRequests::class)->getUpdateUri(),
            ['components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => []]]],
        );

        $this->assertSame(401, $reponse->getStatusCode());
        $this->assertStringNotContainsString('MESSAGE-DE-A-1691', $reponse->getContent());
    }

    /** Sans les en-tetes Livewire, l'endpoint ne se laisse meme pas identifier. */
    public function test_c_the_endpoint_still_requires_its_headers(): void
    {
        $this->actingAs($this->attaquant)
            ->postJson(app(HandleRequests::class)->getUpdateUri(), ['components' => []])
            ->assertNotFound();
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /** Le snapshot REEL du composant vise, pris sur une page legitime. */
    private function snapshotDe(string $url, User $acteur, string $composant): string
    {
        $html = $this->actingAs($acteur)->get($url)->assertOk()->getContent();

        $this->assertSame(1, preg_match_all('/wire:snapshot="([^"]+)"/', $html, $tous) > 0 ? 1 : 0,
            "aucun snapshot sur [{$url}]");

        foreach ($tous[1] as $candidat) {
            $decode = html_entity_decode($candidat, ENT_QUOTES);
            if (str_contains($decode, $composant)) {
                return $decode;
            }
        }

        $this->fail("le composant [{$composant}] n'est pas monte sur [{$url}]");
    }

    /**
     * Un appel sur le VRAI endpoint. L'URI est resolue a l'execution : son
     * prefixe est derive de l'environnement, et le coder en dur rendrait un 404
     * trompeur qui ressemblerait a une protection.
     */
    private function update(User $acteur, string $snapshot, array $updates, array $calls = [])
    {
        return $this->actingAs($acteur)
            ->withHeaders(['X-Livewire' => 'true'])
            ->postJson(app(HandleRequests::class)->getUpdateUri(), ['components' => [[
                'snapshot' => $snapshot,
                'updates' => $updates,
                'calls' => $calls,
            ]]]);
    }
}
