<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TASK-1795 correctif 3 — `/admin/messages` s'ouvre sur TOUT.
 *
 * Cyril : « Il faut mettre la liste sur "Toutes les organisations" par defaut.
 * Et sur "Tous" qui indique tout type de messages. Actuellement c'est sur
 * `main`. Donc il faut passer par le menu, et c'est perte de temps. »
 *
 * Deux defauts se cumulaient — le type `chatloop` ET l'Organization par
 * defaut — et une liste filtree ne dit pas qu'elle l'est : un SuperAdmin
 * cherchant un message d'une autre Organization voyait une page vide qui avait
 * l'air d'une absence.
 */
class TASK1795AdminMessagesDefaultFiltersTest extends TestCase
{
    use RefreshDatabase;

    private Organization $main;

    private Organization $ofsh;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->main = Organization::factory()->create([
            'slug' => 'main',
            'is_active' => true,
            'is_default' => true,
            'loops_enabled' => true,
        ]);

        $this->ofsh = Organization::factory()->create([
            'slug' => 'ofsh',
            'name' => 'OFSH',
            'is_active' => true,
            'is_default' => false,
            'loops_enabled' => true,
        ]);

        $this->superAdmin = User::factory()->create([
            'organization_id' => $this->main->id,
            'is_admin' => true,
            'email_verified_at' => now(),
        ]);

        Http::preventStrayRequests();
        Http::fake();
    }

    private function messageDEchange(Organization $org, string $body): Message
    {
        $acheteur = User::factory()->create(['organization_id' => $org->id]);
        $vendeur = User::factory()->create(['organization_id' => $org->id]);

        app()->instance('current_organization', $org);

        $transaction = Transaction::factory()->create([
            'organization_id' => $org->id,
            'buyer_id' => $acheteur->id,
            'seller_id' => $vendeur->id,
            'service_id' => null,
            'request_id' => null,
            'points_proposed' => 0,
            'status' => 'pending',
        ]);

        return Message::query()->create([
            'transaction_id' => $transaction->id,
            'sender_id' => $acheteur->id,
            'organization_id' => $org->id,
            'body' => $body,
        ]);
    }

    private function messageDeBoucle(Organization $org, string $body): LoopMessage
    {
        $auteur = User::factory()->create(['organization_id' => $org->id]);

        $loop = Loop::factory()->create([
            'organization_id' => $org->id,
            'visibility' => 'public',
            'status' => 'active',
        ]);

        return LoopMessage::query()->create([
            'loop_id' => $loop->id,
            'sender_id' => $auteur->id,
            'organization_id' => $org->id,
            'body' => $body,
            'type' => 'user',
        ]);
    }

    public function test_la_liste_s_ouvre_sur_toutes_les_organisations_et_tous_les_types(): void
    {
        $this->messageDeBoucle($this->main, 'Boucle de main');
        $this->messageDeBoucle($this->ofsh, 'Boucle de ofsh');
        $this->messageDEchange($this->main, 'Echange de main');
        $this->messageDEchange($this->ofsh, 'Echange de ofsh');

        $reponse = $this->actingAs($this->superAdmin)
            ->get(route('admin.messages'))
            ->assertOk();

        // Les deux defauts, mesures sur ce que la VUE a recu — pas sur le HTML,
        // qui tronque les corps a l'affichage.
        $reponse->assertViewHas('filter', 'all');
        $reponse->assertViewHas('selectedOrganizationId', 'all');

        // Et la consequence qui compte : les quatre lignes sont la, les deux
        // Organizations et les deux modeles confondus.
        $corps = $reponse->viewData('messages')->pluck('body')->all();

        foreach (['Boucle de main', 'Boucle de ofsh', 'Echange de main', 'Echange de ofsh'] as $attendu) {
            $this->assertContains($attendu, $corps, "« $attendu » doit figurer dans la liste par defaut");
        }
    }

    public function test_un_filtre_explicite_de_l_url_gouverne_toujours(): void
    {
        $this->messageDeBoucle($this->main, 'Boucle de main');
        $this->messageDeBoucle($this->ofsh, 'Boucle de ofsh');

        $reponse = $this->actingAs($this->superAdmin)
            ->get(route('admin.messages', [
                'filter' => 'chatloop',
                'organization_id' => $this->ofsh->id,
            ]))
            ->assertOk();

        $reponse->assertViewHas('filter', 'chatloop');
        $reponse->assertViewHas('selectedOrganizationId', (string) $this->ofsh->id);

        $corps = $reponse->viewData('messages')->pluck('body')->all();
        $this->assertContains('Boucle de ofsh', $corps);
        $this->assertNotContains('Boucle de main', $corps, 'le filtre explicite doit restreindre');
    }

    /**
     * TASK-1666 — un `transaction_id` ne tombe JAMAIS sur un onglet vide.
     *
     * La garantie porte sur l'absence de faux « il n'y a rien », pas sur le nom
     * de l'onglet : forcer `exchanges` en dur etait la facon de la tenir tant
     * qu'il n'existait qu'un onglet de conversations.
     *
     * Le correctif 4 en a ajoute un second — « Messages privés » — et
     * `exchanges` en est desormais le COMPLEMENT : y envoyer une discussion
     * directe aurait recree exactement le defaut que T1666 avait ferme. Le
     * helper de ce fichier fabrique justement une conversation DIRECTE (ni
     * service, ni demande, zero point), et c'est donc `private` que l'on
     * attend. Ce que le test verifie reste le meme : la conversation visee est
     * bien la, dans l'onglet ou le lien a mene.
     */
    public function test_un_transaction_id_ouvre_l_onglet_qui_contient_la_conversation(): void
    {
        $message = $this->messageDEchange($this->ofsh, 'Conversation cible');

        $reponse = $this->actingAs($this->superAdmin)
            ->get(route('admin.messages', ['transaction_id' => $message->transaction_id]))
            ->assertOk();

        $reponse->assertViewHas('filter', 'private');

        $corps = $reponse->viewData('messages')->pluck('body')->all();
        $this->assertSame(['Conversation cible'], $corps);
    }

    public function test_la_pagination_reste_fonctionnelle_sur_le_defaut(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->messageDeBoucle($this->ofsh, 'Message numero '.$i);
        }

        $reponse = $this->actingAs($this->superAdmin)
            ->get(route('admin.messages'))
            ->assertOk();

        $messages = $reponse->viewData('messages');

        $this->assertSame(25, $messages->perPage());
        $this->assertSame(30, $messages->total());
        $this->assertCount(25, $messages->items(), 'la premiere page est pleine');

        $page2 = $this->actingAs($this->superAdmin)
            ->get(route('admin.messages', ['page' => 2]))
            ->assertOk();

        $this->assertCount(5, $page2->viewData('messages')->items());
    }

    /**
     * La suppression en masse NE s'elargit PAS avec le nouveau defaut.
     *
     * `selectedAdminOrganizationId()` sert aussi `bulkDestroy()`. Lui donner
     * `'all'` par defaut aurait transforme une suppression sans
     * `organization_id` en suppression a travers TOUTES les Organizations.
     * Le defaut y reste l'Organization par defaut : le message de `ofsh`
     * survit.
     */
    public function test_la_suppression_en_masse_sans_organization_id_ne_traverse_pas_les_organizations(): void
    {
        $cible = $this->messageDeBoucle($this->ofsh, 'A ne pas supprimer');

        $this->actingAs($this->superAdmin)->delete(route('admin.messages.bulk-destroy'), [
            'ids' => [$cible->id],
            'filter' => 'chatloop',
        ]);

        $this->assertDatabaseHas('loop_messages', ['id' => $cible->id]);
    }
}
