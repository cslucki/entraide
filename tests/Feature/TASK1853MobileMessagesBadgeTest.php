<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\Organization;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1853 — badge mobile des messages non lus, meme compteur que le rail
 * de bureau (TASK-1847) et meme idiome que le badge de notifications
 * mobile (TASK-1843) : `mobile-bottom-nav.blade.php` n'avait aucun badge
 * sur son entree « Messages ».
 *
 * Perimetre : rendu SERVEUR du composant (SSR initial + point de montage
 * temps reel) dans `mobile-bottom-nav.blade.php`. Le cote JS (`cibles()`,
 * mise a jour simultanee des deux surfaces, sequence de compte) est couvert
 * par `tests/js/messages-unread-badge-realtime.test.mjs`.
 */
class TASK1853MobileMessagesBadgeTest extends TestCase
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
            'slug' => 'org-1853-badge-mobile',
            'is_active' => true,
        ]);

        $this->moi = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->autre = User::factory()->create(['organization_id' => $this->organization->id]);

        $this->transaction = Transaction::factory()->create([
            'organization_id' => $this->organization->id,
            'buyer_id' => $this->moi->id,
            'seller_id' => $this->autre->id,
            'status' => 'accepted',
            'points_proposed' => 20,
        ]);

        // `User::unreadMessagesCount()` passe par `whereHas('transaction', ...)`,
        // une sous-requete qui porte le meme scope global
        // `BelongsToOrganizationScope` que toute autre requete sur ce
        // modele : sans contexte tenant courant, elle echoue fail-closed et
        // rendrait silencieusement 0 — seulement pour les assertions
        // directes de PRECONDITION hors requete HTTP (`$this->moi->
        // unreadMessagesCount()` ci-dessous) ; `$this->page()` le lie deja
        // lui-meme via le middleware de la route.
        app()->instance('current_organization', $this->organization);
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

    private function page(): string
    {
        return $this->actingAs($this->moi)
            ->get(route('organization.dashboard', ['organization' => $this->organization->slug]))
            ->assertOk()
            ->getContent();
    }

    /** 1. Badge SSR sur mobile avec messages non lus. */
    public function test_le_badge_ssr_mobile_porte_le_compte_quand_il_y_a_des_messages_non_lus(): void
    {
        $this->messageDe($this->autre);
        $this->messageDe($this->autre, 'Un second message');

        $this->assertSame(2, $this->moi->fresh()->unreadMessagesCount());

        $html = $this->page();

        $this->assertStringContainsString(
            'data-mobile-bottom-nav-messages-unread="2"',
            $html,
            'Le badge SSR de la barre mobile doit porter le compte exact.'
        );
    }

    /** 2. Absence de badge a zero (meme masque, le span ne doit pas exister). */
    public function test_aucun_badge_ssr_mobile_quand_rien_n_est_a_lire(): void
    {
        $this->assertSame(0, $this->moi->fresh()->unreadMessagesCount());

        $html = $this->page();

        $this->assertStringNotContainsString(
            'data-mobile-bottom-nav-messages-unread',
            $html,
            'Aucun badge SSR ne doit etre rendu a zero.'
        );
    }

    /** Le point de montage temps reel existe TOUJOURS, qu'il y ait des messages ou pas (meme a zero). */
    public function test_le_point_de_montage_temps_reel_mobile_est_toujours_present_meme_a_zero(): void
    {
        $this->assertSame(0, $this->moi->fresh()->unreadMessagesCount());

        $html = $this->page();

        $this->assertStringContainsString('data-realtime-badge-mount="messages-mobile"', $html);
    }

    /** 6. Le rail de bureau continue de fonctionner a l'identique. */
    public function test_le_rail_de_bureau_n_est_pas_affecte(): void
    {
        $this->messageDe($this->autre);

        $html = $this->page();

        $this->assertStringContainsString(
            'data-nav-badge-messages="1"',
            $html,
            'Le badge du rail de bureau doit continuer de fonctionner a l\'identique.'
        );
        $this->assertStringContainsString('data-realtime-badge-mount="messages-rail"', $html);
    }

    /** Seul l'onglet Messages recoit le nouveau conteneur — aucun autre onglet n'est touche. */
    public function test_seul_l_onglet_messages_recoit_le_nouveau_conteneur_relatif(): void
    {
        $html = $this->page();

        $this->assertSame(
            1,
            substr_count($html, 'relative inline-flex h-6 w-6'),
            'Le nouveau conteneur ne doit apparaitre qu\'une seule fois (onglet Messages), les autres onglets restent inchanges.'
        );

        // Les autres destinations de la barre mobile existent toujours.
        $this->assertStringContainsString('data-bp-nav-item="loops"', $html);
        $this->assertStringContainsString('data-bp-nav-item="exchanges"', $html);
        $this->assertStringContainsString('data-bp-nav-item="messages"', $html);
    }

    /** 7. Aucun conflit avec le badge des notifications, sur les deux surfaces. */
    public function test_aucun_conflit_avec_le_badge_de_notifications(): void
    {
        $html = $this->page();

        $this->assertStringContainsString(
            'data-realtime-badge-mount="notifications-mobile"',
            $html,
            'Le point de montage des notifications mobile doit coexister, inchange.'
        );
        $this->assertStringContainsString('data-realtime-badge-mount="notifications-rail"', $html);
    }

    /** La hauteur de la barre (contrat documente par TASK-1625) ne doit pas bouger. */
    public function test_la_hauteur_de_la_barre_mobile_reste_inchangee(): void
    {
        $html = $this->page();

        $this->assertStringContainsString('h-16', $html);
    }
}
