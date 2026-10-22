<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TASK-1693, bug AC — la Messagerie sur un ecran de telephone.
 *
 * Trois demandes (recette Cyril, 22/10, capture a l'appui) :
 *
 * 1. le bas de l'ecran doit revenir au COMPOSEUR, pas a la barre d'outils ;
 * 2. le badge « Conversation directe » rejoint la ligne du pseudo, a droite ;
 * 3. un declencheur pour envoyer une image, et sur telephone pour PRENDRE une
 *    photo.
 *
 * ## Ce que ces tests mesurent, et ce qu'ils ne peuvent pas mesurer
 *
 * Ils lisent le HTML rendu par le serveur. Ils etablissent donc qu'un element
 * est PRESENT ou ABSENT, et qu'il porte les classes voulues — pas qu'il
 * s'affiche a tel endroit. La mise en page se verifie a l'oeil ; ce qui se
 * teste, c'est la DECISION qui la produit.
 */
class TASK1693MobileMessagingTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $alice;

    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create([
            'slug' => 'org-1693ac',
            'is_active' => true,
            'is_public' => true,
        ]);

        $this->alice = $this->membre();
        $this->bob = $this->membre();

        app()->instance('current_organization', $this->organization);

        Http::preventStrayRequests();
        Http::fake();
    }

    private function membre(): User
    {
        return User::factory()->complete()->create([
            'organization_id' => $this->organization->id,
            'email_verified_at' => now(),
            'points_balance' => 100,
        ]);
    }

    private function discussion(): Transaction
    {
        return Transaction::factory()->create([
            'organization_id' => $this->organization->id,
            'buyer_id' => $this->alice->id,
            'seller_id' => $this->bob->id,
            'service_id' => null,
            'request_id' => null,
            'status' => 'pending',
            'points_proposed' => 0,
        ]);
    }

    private function echange(): Transaction
    {
        $categorie = \App\Models\Category::factory()->create(['organization_id' => $this->organization->id]);

        return Transaction::factory()->create([
            'organization_id' => $this->organization->id,
            'buyer_id' => $this->alice->id,
            'seller_id' => $this->bob->id,
            'service_id' => \App\Models\Service::factory()->create([
                'user_id' => $this->bob->id,
                'organization_id' => $this->organization->id,
                'category_id' => $categorie->id,
                'status' => 'active',
            ])->id,
            'status' => 'accepted',
            'points_proposed' => 20,
            'points_agreed' => 20,
        ]);
    }

    private function conversation(Transaction $t): string
    {
        return $this->actingAs($this->alice)
            ->get(route('organization.messages.show', [
                'organization' => $this->organization->slug,
                'transaction' => $t,
            ]))
            ->assertOk()
            ->getContent();
    }

    private function liste(): string
    {
        return $this->actingAs($this->alice)
            ->get(route('organization.messages.index', ['organization' => $this->organization->slug]))
            ->assertOk()
            ->getContent();
    }

    // ── 1. Le bas de l'ecran ─────────────────────────────────────────────────

    /** Dans une conversation, la barre d'outils cede la place. */
    public function test_the_bottom_bar_steps_aside_inside_a_conversation(): void
    {
        $this->assertStringNotContainsString('data-bp-mobile-nav', $this->conversation($this->discussion()));
    }

    /**
     * Mais la LISTE la garde : on y navigue encore.
     *
     * Le point d'ancrage est `data-bp-mobile-nav`, pose par TASK-1609
     * precisement pour qu'on ne vise pas une chaine de classes utilitaires.
     */
    public function test_the_conversation_list_keeps_its_bottom_bar(): void
    {
        $this->assertStringContainsString('data-bp-mobile-nav', $this->liste());
    }

    /**
     * Et la marge basse qui lui etait reservee part avec elle.
     *
     * La laisser creuserait un vide de 4rem sous le composeur — le defaut
     * remplace par un autre.
     */
    public function test_the_reserved_bottom_padding_goes_with_it(): void
    {
        // Viser le point d'ancrage, pas le nom de la classe : celle-ci figure
        // de toute facon dans la feuille de style du document, et l'assertion
        // ne distinguait pas sa DEFINITION de son APPLICATION — premier jet,
        // faux rouge.
        $this->assertStringContainsString('data-bp-shell-bottom-nav="0"', $this->conversation($this->discussion()));
        $this->assertStringContainsString('data-bp-shell-bottom-nav="1"', $this->liste());
    }

    /** Le retour vers la liste reste atteignable depuis l'en-tete. */
    public function test_going_back_to_the_list_stays_reachable(): void
    {
        $this->assertStringContainsString(
            e(route('organization.messages.index', ['organization' => $this->organization->slug])),
            $this->conversation($this->discussion()),
            'retirer la barre ne doit pas enfermer dans la conversation',
        );
    }

    // ── 2. Le badge ──────────────────────────────────────────────────────────

    /** Le badge est pose sur la ligne du pseudo. */
    public function test_the_badge_sits_on_the_name_line(): void
    {
        $html = $this->conversation($this->discussion());

        $this->assertStringContainsString('data-conversation-badge', $html);
        $this->assertStringContainsString(e(__('messages.direct_conversation')), $html);
    }

    /**
     * Et la bande qui ne le portait plus disparait — pour une DISCUSSION.
     *
     * Elle n'y contenait que le badge : les actions y sont masquees et il n'y
     * a pas de points.
     */
    public function test_the_now_empty_status_banner_is_gone_for_a_discussion(): void
    {
        $html = $this->conversation($this->discussion());

        $this->assertMatchesRegularExpression(
            '/class="[^"]*hidden[^"]*"\s*\n?\s*data-thread-status-banner/',
            $html,
            'la bande ne porte plus rien pour une discussion : elle doit etre masquee',
        );
    }

    /**
     * Mais elle RESTE pour un echange : elle y porte le statut, les points et
     * les boutons.
     *
     * Sans ce test, masquer la bande pour tout le monde passerait inapercu —
     * et emporterait « Confirmer / Contester ».
     */
    public function test_the_status_banner_stays_for_an_exchange(): void
    {
        $html = $this->conversation($this->echange());

        // Mesurer la VISIBILITE, pas la presence : masquer la bande pour tout
        // le monde laisserait le marqueur et les « 20 pts » dans le document.
        // Premier jet, le sabotage correspondant restait vert.
        $this->assertStringContainsString('data-thread-status-banner', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/class="[^"]*\bhidden\b[^"]*"\s*\n?\s*data-thread-status-banner/',
            $html,
            'la bande porte le statut, les points et les boutons d\'un echange : elle doit rester VISIBLE',
        );
        $this->assertStringContainsString('20 pts', $html);
        $this->assertStringNotContainsString('data-conversation-badge', $html,
            'le badge « conversation directe » n\'a pas lieu d\'etre sur un echange');
    }

    // ── 3. Les pieces jointes ────────────────────────────────────────────────

    /** Le declencheur d'image est present, et desormais NOMME. */
    public function test_the_image_control_is_present_and_named(): void
    {
        $html = $this->conversation($this->discussion());

        $this->assertStringContainsString('data-composer-upload', $html);
        $this->assertStringContainsString(e(__('messages.send_image')), $html,
            'ce bouton n\'avait aucun intitule : on ne devinait pas ce qu\'il faisait');
    }

    /**
     * LE geste demande : prendre une photo, sur telephone.
     *
     * Le champ existait depuis TASK-1329 mais restait INERTE hors du ChatLoop :
     * aucun bouton ne l'invoquait.
     */
    public function test_the_camera_can_finally_be_triggered(): void
    {
        $html = $this->conversation($this->discussion());

        $this->assertStringContainsString('data-composer-camera', $html);
        $this->assertStringContainsString('$refs.cameraInput.click()', $html,
            'le champ `capture` existait deja : il lui manquait un declencheur');
        $this->assertStringContainsString('capture="environment"', $html);
    }

    /** Et il est reserve au telephone : sur ordinateur il ferait doublon. */
    public function test_the_camera_control_is_mobile_only(): void
    {
        $this->assertMatchesRegularExpression(
            '/class="md:hidden[^"]*"[^>]*>\s*<svg|data-composer-camera[\s\S]{0,400}?md:hidden/',
            $this->conversation($this->discussion()),
            '`capture` n\'a de sens que sur un appareil qui a une camera',
        );
    }
}
