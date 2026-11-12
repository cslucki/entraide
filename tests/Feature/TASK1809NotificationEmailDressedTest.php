<?php

namespace Tests\Feature;

use App\Models\EmailLog;
use App\Models\Loop;
use App\Models\MemberNotificationDelivery;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopInvitationService;
use App\Support\Notifications\NotificationCatalogue;
use App\Support\Notifications\NotificationEmailDeliverer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * TASK-1809 (micro-sequence B) — le HTML administre, livre par
 * `NotificationEmailDeliverer`, part desormais HABILLE.
 *
 * ## Ce que ce fichier protege
 *
 * Avant ce correctif, `Mail::html($contenu['body'], ...)` recevait le
 * fragment brut — sans style, sans structure en tableau — mesure en base
 * reelle sur `loop_invitation`/`transaction_proposal_created`/
 * `entraide_evaluation_invite`/etc (audit T1809). Publier le theme
 * `vendor/mail` (micro-sequence A) ne suffit pas a corriger ce chemin, qui
 * n'utilise jamais `->markdown()`.
 *
 * ## Deux pieges deja ecartes, et que ces tests gardent fermes
 *
 * 1. `Illuminate\Mail\Markdown::parse()` casse sur un HTML multi-lignes AVEC
 *    lignes vides (le cas du repli Blade `loop-invitation.blade.php`) :
 *    abandonne apres l'avoir mesure.
 * 2. `CssToInlineStyles` (donc `DOMDocument`) re-encode les caracteres
 *    qu'il juge invalides dans un `href` — `[action-link-redacted]` en
 *    ressortait `%5Baction-link-redacted%5D`, deformant le marqueur
 *    anti-jeton de TASK-1378. Abandonne lui aussi.
 *
 * La solution retenue (`emails.notification-shell.blade.php`, theme en
 * `<style>` plutot qu'inline) est une pure concatenation Blade : aucun
 * parseur, aucun serialiseur DOM entre le texte administre et le transport.
 */
class TASK1809NotificationEmailDressedTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $expediteur;

    private User $membre;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['is_active' => true]);
        $this->expediteur = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->membre = User::factory()->create([
            'organization_id' => $this->organization->id,
            'preferred_locale' => 'fr',
        ]);
        $this->loop = Loop::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by' => $this->expediteur->id,
            'status' => 'active',
        ]);

        config(['mail.default' => 'array']);
        Mail::forgetMailers();
        Mail::mailer()->getSymfonyTransport()->flush();

        Queue::fake();
    }

    private function livrer(): void
    {
        $invitation = app(LoopInvitationService::class)->invite($this->loop, $this->expediteur, $this->membre->email);

        $livraison = MemberNotificationDelivery::query()
            ->forChannel(NotificationCatalogue::CHANNEL_EMAIL)
            ->where('notification_id', function ($q) use ($invitation) {
                $q->select('id')->from('member_notifications')
                    ->where('object_id', $invitation->id)
                    ->where('recipient_id', $this->membre->id);
            })
            ->firstOrFail();

        app(NotificationEmailDeliverer::class)->deliver((string) $livraison->notification_id);
    }

    private function corpsEnvoye(): string
    {
        $envoye = array_values(Mail::mailer()->getSymfonyTransport()->messages()->all())[0]->getOriginalMessage();

        return (string) ($envoye->getHtmlBody() ?? $envoye->getBody()->bodyToString());
    }

    /** Le corps reellement remis au transport porte la structure en tableau. */
    public function test_the_delivered_body_carries_the_table_layout(): void
    {
        $this->livrer();

        $corps = $this->corpsEnvoye();

        $this->assertStringContainsString('<table', $corps);
        $this->assertStringContainsString('class="inner-body"', $corps);
    }

    /** Et la palette BouclePro du theme (micro-sequence A), pas l'ancienne. */
    public function test_the_delivered_body_carries_the_bouclepro_theme(): void
    {
        $this->livrer();

        $this->assertStringContainsString('#4338ca', $this->corpsEnvoye());
    }

    /** Un email nu (avant ce correctif) etait un fragment de quelques centaines d'octets. */
    public function test_a_naked_email_would_be_an_order_of_magnitude_smaller(): void
    {
        $this->livrer();

        $this->assertGreaterThan(2000, strlen($this->corpsEnvoye()));
    }

    /** Le texte administre (nom de la Boucle, de l'expediteur) traverse l'habillage intact. */
    public function test_the_editorial_text_survives_unaltered(): void
    {
        $this->livrer();

        $corps = $this->corpsEnvoye();

        $this->assertStringContainsString($this->loop->name, $corps);
        $this->assertStringContainsString($this->expediteur->fullName, $corps);
    }

    /**
     * Ce qui est ARCHIVE (`EmailLog.body_html`) est, lui aussi, habille —
     * coherent avec ce qui est reellement parti — et le marqueur expurge
     * anti-jeton n'est PAS deforme par l'habillage (piege 2 du docbloc de
     * la classe).
     */
    public function test_the_archived_log_body_is_dressed_and_keeps_the_redaction_marker_intact(): void
    {
        $this->livrer();

        $journal = EmailLog::query()->whereNotNull('notification_id')->firstOrFail();

        $this->assertStringContainsString('<table', (string) $journal->body_html);
        $this->assertStringContainsString('[action-link-redacted]', (string) $journal->body_html);
        $this->assertStringNotContainsString('%5Baction-link-redacted%5D', (string) $journal->body_html);
    }

    /** `body_hash` doit hacher exactement ce qui a ete remis au transport, habille compris. */
    public function test_body_hash_matches_the_dressed_body_actually_sent(): void
    {
        $this->livrer();

        $journal = EmailLog::query()->whereNotNull('notification_id')->firstOrFail();

        $this->assertSame(hash('sha256', $this->corpsEnvoye()), $journal->body_hash);
    }
}
