<?php

namespace Tests\Feature;

use App\Models\BulkEmailSend;
use App\Models\EmailLog;
use App\Models\Organization;
use App\Models\ServiceRequest;
use App\Models\SystemEmailTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1883 — ecran /admin/newsletter (Email&Messages) : apercu reel, envoi,
 * garde anti-doublon, progression.
 */
class TASK1883AdminNewsletterTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['is_active' => true, 'name' => 'Entraide Test']);
        $this->superadmin = User::factory()->create(['organization_id' => $this->org->id, 'is_admin' => true]);

        $auteur = User::factory()->create(['organization_id' => $this->org->id, 'newsletter_unsubscribed_at' => now()]);
        ServiceRequest::withoutGlobalScopes()->forceCreate(ServiceRequest::factory()->raw([
            'organization_id' => $this->org->id, 'user_id' => $auteur->id, 'title' => 'Besoin d un coup de main',
        ]));
        User::factory()->count(2)->create(['organization_id' => $this->org->id]);
    }

    private function formulaire(array $plus = []): array
    {
        return $plus + ['scope' => 'selection', 'organization_ids' => [$this->org->id], 'subject' => '', 'demand_count' => 3, 'offer_count' => 2, 'blog_count' => 1];
    }

    public function test_page_reservee_au_superadmin_et_dans_le_menu(): void
    {
        $membre = User::factory()->create(['organization_id' => $this->org->id]);
        $this->actingAs($membre)->get(route('admin.newsletter'))->assertForbidden();
        $this->actingAs($membre)->postJson(route('admin.newsletter.preview'), $this->formulaire(['locale' => 'fr']))->assertForbidden();

        $this->actingAs($this->superadmin)->get(route('admin.newsletter'))
            ->assertOk()
            ->assertSee(route('admin.newsletter'), false)
            ->assertSee('data-admin-newsletter-form', false)
            ->assertSee('data-admin-newsletter-tab-code', false)
            ->assertSee('data-admin-newsletter-blog-count', false);
    }

    public function test_l_apercu_rend_les_vraies_demandes_dans_la_langue_choisie_et_compte_les_destinataires(): void
    {
        $fr = $this->actingAs($this->superadmin)->postJson(route('admin.newsletter.preview'), $this->formulaire(['locale' => 'fr', 'subject' => 'Cette semaine']))
            ->assertOk()->json();

        $this->assertSame('Cette semaine', $fr['subject']);
        $this->assertStringContainsString('Besoin d un coup de main', $fr['html']);
        $this->assertStringContainsString('Ils ont besoin d’aide', $fr['html']);
        $this->assertSame(3, $fr['recipients'], 'superadmin + 2 membres ; l auteur desabonne est exclu.');
        // TASK-1891 — l'apercu porte le MEME lien que l'envoi : scope et signe.
        $this->assertStringContainsString('/org/'.$this->org->slug.'/newsletter/desinscription/', $fr['html']);
        $this->assertStringContainsString('signature=', $fr['html']);
        $this->assertStringNotContainsString('#desinscription-apercu', $fr['html']);

        // Le champ est pre-rempli avec l'objet FR du modele, et modifiable.
        $this->actingAs($this->superadmin)->get(route('admin.newsletter'))
            ->assertSee('[{{ organization_name }}] - newsletter du {{ newsletter_date }}', false)
            ->assertSee('data-admin-newsletter-subject', false);

        // Laisse tel quel : un membre anglophone garde l'objet EN.
        $en = $this->actingAs($this->superadmin)->postJson(route('admin.newsletter.preview'), $this->formulaire([
            'locale' => 'en', 'subject' => '[{{ organization_name }}] - newsletter du {{ newsletter_date }}',
        ]))->json();
        $this->assertStringContainsString('They need help', $en['html']);
        $this->assertSame('[Entraide Test] - newsletter of '.now('Europe/Paris')->format('m/d/y'), $en['subject']);
    }

    public function test_envoyer_puis_suivre_la_progression_et_le_doublon_demande_confirmation(): void
    {
        $reponse = $this->actingAs($this->superadmin)->post(route('admin.newsletter.store'), $this->formulaire());

        $send = BulkEmailSend::query()->where('content_source', BulkEmailSend::CONTENT_SOURCE_NEWSLETTER)->firstOrFail();
        $reponse->assertRedirect(route('admin.newsletter.show', $send));
        $this->assertSame(3, $send->total_recipients);
        $this->assertSame(3, $send->demand_count);
        $this->assertSame(2, $send->offer_count);
        $this->assertSame(1, $send->blog_count);

        $this->actingAs($this->superadmin)->get(route('admin.newsletter.show', $send))->assertOk()->assertSee('data-admin-newsletter-progress', false);

        $etat = $this->actingAs($this->superadmin)->postJson(route('admin.newsletter.batch', $send))->assertOk()->json();
        $this->assertSame(3, $etat['sent']);
        $this->assertTrue($etat['done']);
        $this->assertSame(3, EmailLog::where('data->source', 'newsletter')->count());

        $this->actingAs($this->superadmin)->get(route('admin.email-logs', ['source' => 'newsletter']))->assertOk()->assertSee('Newsletter hebdomadaire');

        // L'avertissement de doublon decrit l'envoi precedent et y mene.
        $this->actingAs($this->superadmin)->get(route('admin.newsletter'))
            ->assertSee('data-admin-newsletter-repeat', false)
            ->assertSee(route('admin.newsletter.show', $send), false)
            ->assertSee('vers Entraide Test', false)
            ->assertSee('3 envoyé(s) sur 3', false);

        // Moins de 6 jours apres : refuse sans la confirmation, accepte avec.
        $this->actingAs($this->superadmin)->from(route('admin.newsletter'))->post(route('admin.newsletter.store'), $this->formulaire())
            ->assertRedirect(route('admin.newsletter'))->assertSessionHas('error');
        $this->assertSame(1, BulkEmailSend::where('content_source', 'newsletter')->count());

        $this->actingAs($this->superadmin)->post(route('admin.newsletter.store'), $this->formulaire(['confirm_repeat' => '1']))->assertRedirect();
        $this->assertSame(2, BulkEmailSend::where('content_source', 'newsletter')->count());
    }

    public function test_un_envoi_groupe_ordinaire_n_est_pas_une_newsletter(): void
    {
        $send = BulkEmailSend::factory()->create(['created_by' => $this->superadmin->id]);

        $this->actingAs($this->superadmin)->get(route('admin.newsletter.show', $send))->assertNotFound();
    }

    public function test_l_apercu_du_modele_systeme_developpe_les_blocs(): void
    {
        $this->org->update(['is_default' => true]);
        $gabarit = SystemEmailTemplate::query()->whereNull('organization_id')->where('slug', 'weekly_help_digest')->where('locale', 'fr')->firstOrFail();

        $html = $this->actingAs($this->superadmin)
            ->postJson(route('admin.system-email-templates.preview', $gabarit), ['subject' => $gabarit->subject, 'content_html' => $gabarit->content_html])
            ->assertOk()->json('html');

        $this->assertStringContainsString('Besoin d un coup de main', $html);
        $this->assertStringNotContainsString('{demande_aide;3}', $html);
    }
}
