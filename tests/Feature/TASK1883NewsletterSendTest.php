<?php

namespace Tests\Feature;

use App\Models\BulkEmailSend;
use App\Models\BulkEmailSendRecipient;
use App\Models\EmailLog;
use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;
use App\Services\BulkEmailSendService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1883 — chemin d'envoi de la newsletter (BulkEmailSend, source `newsletter`).
 */
class TASK1883NewsletterSendTest extends TestCase
{
    use RefreshDatabase;

    private Organization $a;

    private Organization $b;

    private Organization $vide;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = Organization::factory()->create(['is_active' => true, 'name' => 'Org A']);
        $this->b = Organization::factory()->create(['is_active' => true, 'name' => 'Org B']);
        $this->vide = Organization::factory()->create(['is_active' => true, 'name' => 'Org vide']);
        $this->admin = User::factory()->create(['organization_id' => $this->a->id, 'is_admin' => true]);

        $this->demande($this->a, 'Demande de A');
        $this->proposition($this->b, 'Proposition de B');
        app()->forgetInstance('current_organization');
    }

    private function demande(Organization $org, string $titre): void
    {
        $auteur = User::factory()->create(['organization_id' => $org->id, 'newsletter_unsubscribed_at' => now()]);
        ServiceRequest::withoutGlobalScopes()->forceCreate(ServiceRequest::factory()->raw([
            'organization_id' => $org->id, 'user_id' => $auteur->id, 'title' => $titre,
        ]));
    }

    private function proposition(Organization $org, string $titre): void
    {
        $auteur = User::factory()->create(['organization_id' => $org->id, 'newsletter_unsubscribed_at' => now()]);
        Service::withoutGlobalScopes()->forceCreate(Service::factory()->raw([
            'organization_id' => $org->id, 'user_id' => $auteur->id, 'title' => $titre,
        ]));
    }

    private function membre(Organization $org, array $attributs = []): User
    {
        return User::factory()->create(['organization_id' => $org->id] + $attributs);
    }

    private function envoyer(array $organizationIds, ?string $titre = null): BulkEmailSend
    {
        $service = app(BulkEmailSendService::class);
        $send = $service->createNewsletterSend([
            'organization_ids' => $organizationIds, 'subject' => $titre, 'demand_count' => 3, 'offer_count' => 3,
        ], $this->admin);
        $service->sendNextBatch($send, 50);

        return $send->fresh();
    }

    public function test_destinataires_abonnes_actifs_des_organisations_visees_avec_du_contenu(): void
    {
        $alice = $this->membre($this->a);
        $this->membre($this->a, ['newsletter_unsubscribed_at' => now()]);
        $this->membre($this->a, ['login_blocked_at' => now()]);
        $this->membre($this->a, ['banned_at' => now()]);
        $bob = $this->membre($this->b);
        $this->membre($this->vide);

        $toutes = app(BulkEmailSendService::class)->newsletterRecipients([], 3, 3)->pluck('id')->map(fn ($id) => (string) $id)->sort()->values()->all();
        $attendus = collect([$alice->id, $bob->id, $this->admin->id])->map(fn ($id) => (string) $id)->sort()->values()->all();
        $this->assertSame($attendus, $toutes);

        $send = app(BulkEmailSendService::class)->createNewsletterSend(['organization_ids' => [$this->b->id], 'subject' => null, 'demand_count' => 3, 'offer_count' => 3], $this->admin);
        $this->assertSame(1, $send->total_recipients);
        $this->assertSame([(string) $this->b->id], $send->organizations->pluck('id')->map(fn ($id) => (string) $id)->all());
        $this->assertSame((string) $this->b->id, (string) $send->organization_id);
    }

    public function test_chaque_membre_ne_voit_que_son_organisation_dans_sa_langue(): void
    {
        $alice = $this->membre($this->a, ['preferred_locale' => 'fr']);
        $bob = $this->membre($this->b, ['preferred_locale' => 'en']);

        $send = $this->envoyer([$this->a->id, $this->b->id], 'Titre de la semaine');

        $this->assertSame(BulkEmailSend::STATUS_COMPLETED, $send->status);
        $this->assertSame(3, $send->sent_count);

        $pourAlice = EmailLog::where('user_id', $alice->id)->firstOrFail();
        $this->assertSame('Titre de la semaine', $pourAlice->subject);
        $this->assertStringContainsString('Demande de A', $pourAlice->body_html);
        $this->assertStringNotContainsString('Proposition de B', $pourAlice->body_html);
        $this->assertStringContainsString('Aucune proposition d’aide en cours.', $pourAlice->body_html);
        $this->assertStringContainsString('/newsletter/desinscription/'.$alice->id, html_entity_decode($pourAlice->body_html));
        $this->assertStringContainsString('<style', $pourAlice->body_html, 'Habillage emails.system-email.');
        $this->assertStringNotContainsString('{demande_aide', $pourAlice->body_html);
        $this->assertSame('newsletter', $pourAlice->data['source']);
        $this->assertSame((string) $send->id, (string) $pourAlice->data['bulk_email_send_id']);

        $pourBob = EmailLog::where('user_id', $bob->id)->firstOrFail();
        $this->assertStringContainsString('Proposition de B', $pourBob->body_html);
        $this->assertStringNotContainsString('Demande de A', $pourBob->body_html);
        $this->assertStringContainsString('They need help', $pourBob->body_html);
    }

    public function test_sans_titre_le_sujet_du_gabarit_est_utilise(): void
    {
        $alice = $this->membre($this->a);

        $this->envoyer([$this->a->id]);

        // TASK-1891 — objet par defaut date du jour de l'envoi.
        $this->assertSame('[Org A] - notre newsletter du '.now('Europe/Paris')->locale('fr')->isoFormat('D MMMM YYYY'), EmailLog::where('user_id', $alice->id)->value('subject'));
    }

    public function test_desabonne_entre_la_preparation_et_l_envoi_rien_n_est_envoye(): void
    {
        $alice = $this->membre($this->a);
        $service = app(BulkEmailSendService::class);
        $send = $service->createNewsletterSend(['organization_ids' => [$this->a->id], 'subject' => null, 'demand_count' => 3, 'offer_count' => 3], $this->admin);

        $alice->forceFill(['newsletter_unsubscribed_at' => now()])->save();
        $service->sendNextBatch($send, 50);

        $this->assertSame(BulkEmailSendRecipient::STATUS_SKIPPED, $send->recipients()->where('user_id', $alice->id)->value('status'));
        $this->assertSame(0, EmailLog::where('user_id', $alice->id)->count());
        $this->assertSame(BulkEmailSend::STATUS_COMPLETED, $send->fresh()->status);
    }
}
