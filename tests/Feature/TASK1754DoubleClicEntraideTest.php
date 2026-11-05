<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1754 (§4) — le bug du double clic, des DEUX cotes.
 *
 * Le defaut, reproduit en recette le 04/11 : la page de l'annonce rendait
 * TOUJOURS le formulaire, le POST partait, et la garde serveur repondait par un
 * toast rouge SANS aucun chemin vers la conversation existante. Un cul-de-sac.
 *
 * Deux corrections, et les deux sont necessaires :
 * - la VUE sait qu'un echange est actif et propose « Discuter avec … » ;
 * - la GARDE serveur reste — un second onglet, un bouton PWA en cache ou un
 *   double-submit rendent le POST doublon toujours possible — mais elle
 *   REDIRIGE au lieu de refuser.
 *
 * La vue supprime l'occasion, la garde supprime la consequence.
 */
class TASK1754DoubleClicEntraideTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $auteur;

    private User $repondant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['slug' => 'org-1754', 'is_active' => true]);
        app()->instance('current_organization', $this->organization);

        $this->auteur = User::factory()->create([
            'organization_id' => $this->organization->id,
            'first_name' => 'Ottoline', 'name' => 'Kaminski', 'points_balance' => 200,
        ]);
        $this->repondant = User::factory()->create([
            'organization_id' => $this->organization->id,
            'first_name' => 'Brunhilde', 'name' => 'Vasquez', 'points_balance' => 200,
        ]);
    }

    private function service(): Service
    {
        return Service::factory()->create([
            'user_id' => $this->auteur->id,
            'organization_id' => $this->organization->id,
            'category_id' => Category::factory()->create(['organization_id' => $this->organization->id])->id,
            'status' => 'active',
            'points_cost' => 30,
        ]);
    }

    private function demande(): ServiceRequest
    {
        return ServiceRequest::factory()->create([
            'user_id' => $this->auteur->id,
            'organization_id' => $this->organization->id,
            'category_id' => Category::factory()->create(['organization_id' => $this->organization->id])->id,
            'status' => 'open',
            'budget_min' => 30,
        ]);
    }

    // ── Le POST doublon : une REDIRECTION, plus une erreur ───────────────────

    /**
     * Le second POST atterrit DANS la conversation, pas sur un toast rouge.
     *
     * C'est la demande litterale de Cyril. Le test mesure la DESTINATION — un
     * `assertSessionHas('info')` seul passerait au vert meme si l'utilisateur
     * restait bloque sur l'annonce.
     */
    public function test_un_second_envoi_redirige_vers_la_conversation_existante(): void
    {
        $service = $this->service();

        $this->actingAs($this->repondant)
            ->post(route('transactions.store'), ['service_id' => $service->id, 'points_proposed' => 30]);

        $echange = Transaction::where('service_id', $service->id)->firstOrFail();

        $reponse = $this->actingAs($this->repondant)
            ->post(route('transactions.store'), ['service_id' => $service->id, 'points_proposed' => 30]);

        $reponse->assertRedirect(route('messages.show', $echange));
        $reponse->assertSessionHas('info');
        $reponse->assertSessionMissing('error');

        $this->assertSame(1, Transaction::where('service_id', $service->id)->count(),
            'Le second envoi a cree un doublon.');
    }

    /** Meme garde cote DEMANDES — l'enonce ne la citait pas, la recette si. */
    public function test_le_double_envoi_sur_une_demande_redirige_aussi(): void
    {
        $demande = $this->demande();

        $this->actingAs($this->repondant)
            ->post(route('transactions.store'), ['request_id' => $demande->id, 'points_proposed' => 30]);

        $echange = Transaction::where('request_id', $demande->id)->firstOrFail();

        $this->actingAs($this->repondant)
            ->post(route('transactions.store'), ['request_id' => $demande->id, 'points_proposed' => 30])
            ->assertRedirect(route('messages.show', $echange))
            ->assertSessionHas('info');

        $this->assertSame(1, Transaction::where('request_id', $demande->id)->count());
    }

    // ── La page : « Discuter avec … » a la place du formulaire ───────────────

    /** Un echange actif : la page porte le chemin vers la conversation. */
    public function test_la_page_d_annonce_mene_a_la_conversation_quand_un_echange_est_actif(): void
    {
        $service = $this->service();

        $avant = $this->actingAs($this->repondant)
            ->get(route('services.show', $service))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-entraide-discuter', $avant,
            'Sans echange, la page doit proposer le formulaire.');

        $this->actingAs($this->repondant)
            ->post(route('transactions.store'), ['service_id' => $service->id, 'points_proposed' => 30]);

        $apres = $this->actingAs($this->repondant)
            ->get(route('services.show', $service))->assertOk()->getContent();

        $this->assertStringContainsString('data-entraide-discuter', $apres);
        $this->assertStringContainsString('data-entraide-echange-actif', $apres);
    }

    /**
     * LA RECIPROQUE, et c'est le cas limite que le CDC exige de couvrir.
     *
     * Un echange `refused`, `cancelled` ou `completed` ne doit RIEN empecher :
     * on doit pouvoir re-demander de l'aide a quelqu'un qui a decline, ou
     * redemander la meme aide plus tard. Si `echangeActif()` ne bornait pas aux
     * deux statuts vivants, une annonce deviendrait definitivement inaccessible
     * apres un seul refus.
     */
    public function test_un_echange_termine_ou_decline_rend_le_formulaire(): void
    {
        $service = $this->service();

        $this->actingAs($this->repondant)
            ->post(route('transactions.store'), ['service_id' => $service->id, 'points_proposed' => 30]);

        $echange = Transaction::where('service_id', $service->id)->firstOrFail();

        foreach (['refused', 'cancelled', 'completed'] as $statut) {
            $echange->update(['status' => $statut]);

            $html = $this->actingAs($this->repondant)
                ->get(route('services.show', $service))->assertOk()->getContent();

            $this->assertStringNotContainsString('data-entraide-discuter', $html,
                "Un echange [{$statut}] ne doit plus bloquer l'annonce.");
        }
    }

    /**
     * Un AUTRE membre n'est jamais bloque par l'echange de quelqu'un d'autre.
     *
     * La garde est par (proposant, annonce) — pas par annonce. C'est ce qui rend
     * possible, cote demandes, les propositions multiples livrees par T1693
     * bug W, et c'est la fondation de la proposition de groupe du §6.
     */
    public function test_l_echange_d_un_autre_membre_ne_bloque_personne(): void
    {
        $service = $this->service();

        $this->actingAs($this->repondant)
            ->post(route('transactions.store'), ['service_id' => $service->id, 'points_proposed' => 30]);

        $troisieme = User::factory()->create([
            'organization_id' => $this->organization->id, 'points_balance' => 200,
        ]);

        $html = $this->actingAs($troisieme)
            ->get(route('services.show', $service))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-entraide-discuter', $html);

        $this->actingAs($troisieme)
            ->post(route('transactions.store'), ['service_id' => $service->id, 'points_proposed' => 30])
            ->assertSessionMissing('info');

        $this->assertSame(2, Transaction::where('service_id', $service->id)->count());
    }
}
