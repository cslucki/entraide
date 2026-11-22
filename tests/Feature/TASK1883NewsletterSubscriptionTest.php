<?php

namespace Tests\Feature;

use App\Http\Controllers\NewsletterSubscriptionController;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1883 — desinscription de la newsletter (activee par defaut).
 */
class TASK1883NewsletterSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $membre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['is_active' => true]);
        $this->membre = User::factory()->create(['organization_id' => $this->org->id]);
    }

    public function test_abonne_par_defaut_et_le_lien_signe_demande_une_confirmation(): void
    {
        $this->assertNull($this->membre->newsletter_unsubscribed_at);
        $url = NewsletterSubscriptionController::urlDesinscription($this->membre);

        // Ouvrir le lien (ou un antivirus qui l'ouvre) n'enregistre rien.
        $this->get($url)->assertOk()->assertSee('data-newsletter-unsubscribe-confirm', false);
        $this->assertNull($this->membre->fresh()->newsletter_unsubscribed_at);

        $this->post($url)->assertOk()->assertSee('data-newsletter-unsubscribed', false);
        $this->assertNotNull($this->membre->fresh()->newsletter_unsubscribed_at);
        $this->assertGuest();
    }

    public function test_un_lien_non_signe_ou_modifie_est_refuse(): void
    {
        $this->get(route('newsletter.unsubscribe', ['compte' => $this->membre->id]))->assertForbidden();

        $autre = User::factory()->create(['organization_id' => $this->org->id]);
        $falsifie = str_replace($this->membre->id, $autre->id, NewsletterSubscriptionController::urlDesinscription($this->membre));
        $this->post($falsifie)->assertForbidden();

        $this->assertNull($autre->fresh()->newsletter_unsubscribed_at);
    }

    public function test_la_case_des_preferences_desabonne_et_reabonne(): void
    {
        $preferences = route('organization.notifications.preferences.edit', ['organization' => $this->org->slug]);
        $action = route('organization.notifications.preferences.newsletter.update', ['organization' => $this->org->slug]);

        $this->actingAs($this->membre)->get($preferences)->assertOk()->assertSee('data-newsletter-preference', false);

        $this->actingAs($this->membre)->from($preferences)->post($action, ['newsletter' => '0'])->assertRedirect($preferences);
        $this->assertNotNull($this->membre->fresh()->newsletter_unsubscribed_at);

        $this->actingAs($this->membre)->from($preferences)->post($action, ['newsletter' => '1'])->assertRedirect($preferences);
        $this->assertNull($this->membre->fresh()->newsletter_unsubscribed_at);
    }
}
