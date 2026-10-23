<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\Organization;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1693, bug V — le badge « Messagerie » compte les messages SYSTEME.
 *
 * ## Le defaut
 *
 * « Aucune alerte n'apparait sous forme de badge au-dessus de l'icone
 * Messagerie » (recette Cyril, 21/10). Sur la base de recette, TOUS les
 * messages non lus etaient des messages systeme :
 *
 *     « Nouvelle échange envoyée : 20 points. »
 *     « Échange acceptée. L'échange est en cours. »
 *     « L'acheteur a déclaré la prestation terminée »
 *
 * Aucun ne pouvait allumer le badge.
 *
 * ## DEUX exclusions, dont une invisible
 *
 * 1. `type = 'user'` les ecartait deliberement.
 * 2. `sender_id != $moi` les ecartait une SECONDE fois, et celle-la ne se
 *    voyait pas : `addSystemMessage()` pose `sender_id = NULL`, et en SQL
 *    `NULL != 'x'` vaut NULL — jamais VRAI. Mesure sur la base : 8 messages
 *    comptes contre 17 des que les NULL sont admis.
 *
 * Lever la seule premiere n'aurait RIEN change tout en donnant l'impression du
 * correctif applique. C'est ce que fixe `test_lifting_only_the_type_filter_would_not_have_been_enough`.
 *
 * ## Et `markRead()` devait bouger en meme temps
 *
 * Il portait la meme comparaison, et ne marquait donc JAMAIS un message systeme
 * comme lu. Compter sans corriger cela aurait allume le badge DEFINITIVEMENT.
 */
class TASK1693UnreadSystemMessagesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $moi;

    private User $autre;

    private Transaction $transaction;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1693v',
            'is_active' => true,
            'is_public' => true,
        ]);

        $this->moi = $this->membre();
        $this->autre = $this->membre();

        $this->transaction = Transaction::factory()->create([
            'organization_id' => $this->organization->id,
            'buyer_id' => $this->moi->id,
            'seller_id' => $this->autre->id,
            'status' => 'accepted',
            'points_proposed' => 20,
            'points_agreed' => 20,
        ]);

        // `Transaction` porte le scope de tenant, qui retombe a vide hors
        // requete HTTP : sans ce contexte, `whereHas('transaction')` ne ramene
        // RIEN et toutes les assertions nues mesureraient 0 pour une raison
        // etrangere au correctif. Premier jet : 5 faux rouges.
        app()->instance('current_organization', $this->organization);

        Http::preventStrayRequests();
        Http::fake();
    }

    private function membre(): User
    {
        return User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
            'points_balance' => 500,
        ]);
    }

    /** Un message systeme, tel que le produit `addSystemMessage()`. */
    private function messageSysteme(string $body = 'Échange acceptée.'): Message
    {
        return Message::query()->create([
            'transaction_id' => $this->transaction->id,
            'sender_id' => null,
            'body' => $body,
            'type' => 'system',
            'organization_id' => $this->organization->id,
            'created_at' => now(),
        ]);
    }

    private function messageDe(User $auteur, string $body = 'Bonjour'): Message
    {
        return Message::query()->create([
            'transaction_id' => $this->transaction->id,
            'sender_id' => $auteur->id,
            'body' => $body,
            'type' => 'user',
            'organization_id' => $this->organization->id,
            'created_at' => now(),
        ]);
    }

    /** LE defaut signale : un message systeme allume desormais le badge. */
    public function test_a_system_message_now_counts_towards_the_badge(): void
    {
        $this->assertSame(0, $this->moi->fresh()->unreadMessagesCount());

        $this->messageSysteme();

        $this->assertSame(1, $this->moi->fresh()->unreadMessagesCount(),
            'ce sont les messages systeme qui portent les nouvelles qui comptent');
    }

    /**
     * LA mesure qui compte : lever la seule exclusion `type` n'aurait rien
     * change.
     *
     * Ce test reproduit la requete telle qu'elle aurait ete si l'on s'etait
     * arrete au filtre visible — elle rend toujours 0, parce que la
     * comparaison sur `sender_id` ecarte les NULL. Sans ce test, un correctif
     * a moitie fait aurait pu passer pour complet.
     */
    public function test_lifting_only_the_type_filter_would_not_have_been_enough(): void
    {
        $this->messageSysteme();

        $correctifAMoitie = Message::whereHas('transaction', function ($q) {
            $q->where('buyer_id', $this->moi->id)->orWhere('seller_id', $this->moi->id);
        })
            ->where('sender_id', '!=', $this->moi->id)   // la comparaison d'origine
            ->whereNull('read_at')
            ->count();                                   // `type` leve, et c'est tout

        $this->assertSame(0, $correctifAMoitie,
            'NULL != x vaut NULL en SQL : la ligne tombe quand meme');

        $this->assertSame(1, $this->moi->fresh()->unreadMessagesCount(),
            'le correctif complet, lui, la compte');
    }

    /** Les messages d'une personne comptent toujours : rien n'est retire. */
    public function test_a_message_from_a_person_still_counts(): void
    {
        $this->messageDe($this->autre);

        $this->assertSame(1, $this->moi->fresh()->unreadMessagesCount());
    }

    /** Mes PROPRES messages ne me sont pas signales. */
    public function test_my_own_messages_never_count(): void
    {
        $this->messageDe($this->moi);

        $this->assertSame(0, $this->moi->fresh()->unreadMessagesCount());
    }

    /**
     * LE corollaire indispensable : ouvrir le fil ETEINT le badge.
     *
     * `markRead()` portait la meme comparaison et ne marquait jamais un
     * message systeme comme lu. Sans ce second correctif, le badge se serait
     * allume pour ne plus JAMAIS s'eteindre.
     */
    public function test_opening_the_thread_clears_system_messages_too(): void
    {
        $this->messageSysteme();
        $this->messageDe($this->autre);

        $this->assertSame(2, $this->moi->fresh()->unreadMessagesCount());

        $this->actingAs($this->moi);
        Livewire::test(\App\Livewire\MessageThread::class, ['transaction' => $this->transaction])
            ->call('markRead');

        $this->assertSame(0, $this->moi->fresh()->unreadMessagesCount(),
            'un badge qui ne peut pas s\'eteindre est pire que pas de badge');
    }

    /** Et le fil de quelqu'un d'autre ne me concerne pas. */
    public function test_a_thread_i_am_not_part_of_never_counts(): void
    {
        $tiers = $this->membre();

        $ailleurs = Transaction::factory()->create([
            'organization_id' => $this->organization->id,
            'buyer_id' => $this->autre->id,
            'seller_id' => $tiers->id,
            'status' => 'accepted',
            'points_proposed' => 20,
            'points_agreed' => 20,
        ]);

        Message::query()->create([
            'transaction_id' => $ailleurs->id,
            'sender_id' => null,
            'body' => 'Échange acceptée.',
            'type' => 'system',
            'organization_id' => $this->organization->id,
            'created_at' => now(),
        ]);

        $this->assertSame(0, $this->moi->fresh()->unreadMessagesCount());
    }

    /**
     * Les deux compteurs disent la MEME chose.
     *
     * Celui du rail et celui de la liste des fils suivaient des regles
     * differentes : la liste traitait deja le `sender_id` NULL correctement
     * mais ecartait le `type`. Les laisser diverger aurait donne un rail
     * annoncant 3 et des fils affichant 0 — un badge qu'aucune ligne ne
     * justifie est pire que pas de badge.
     */
    public function test_the_rail_badge_and_the_thread_list_agree(): void
    {
        $this->messageSysteme();
        $this->messageSysteme('L\'acheteur a déclaré la prestation terminée.');
        $this->messageDe($this->autre);

        $html = $this->actingAs($this->moi)
            ->get(route('organization.messages.index', ['organization' => $this->organization->slug]))
            ->assertOk()
            ->getContent();

        $this->assertSame(3, $this->moi->fresh()->unreadMessagesCount());

        // Viser le compte EXACT porte par le fil, pas le chiffre « 3 » quelque
        // part dans la page : la premiere version de cette assertion restait
        // VERTE quand on faisait diverger les deux compteurs, elle ne mesurait
        // donc rien.
        $this->assertStringContainsString(
            'data-thread-unread="'.$this->transaction->id.':3"',
            $html,
            'la liste des fils doit porter le MEME compte que le rail',
        );
    }

    /** Le badge apparait bien dans le rail quand il y a de quoi. */
    public function test_the_badge_is_rendered_in_the_side_rail(): void
    {
        $this->messageSysteme();

        $html = $this->actingAs($this->moi)
            ->get(route('organization.dashboard', ['organization' => $this->organization->slug]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-nav-badge-messages', $html);
    }
}
