<?php

namespace Tests\Feature\Loops;

use App\Livewire\LoopMembersCard;
use App\Models\EmailLog;
use App\Models\Loop;
use App\Models\LoopInvitation;
use App\Models\Organization;
use App\Models\SystemEmailTemplate;
use App\Models\User;
use App\Services\LoopInvitationService;
use App\Services\Loops\LoopInvitationMailer;
use App\Services\LoopService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * L'envoi du courriel d'invitation a une Boucle.
 *
 * Ce code etait prive dans LoopInvitationController et n'avait donc aucun test.
 * Il est maintenant appele par deux chemins — la route POST et la Card Membres —
 * et c'est precisement ce qui rend la couverture necessaire : les deux doivent
 * produire le meme courriel, choisir le meme gabarit, et journaliser la meme
 * chose en cas de non-remise.
 *
 * Les assertions portent sur ce qui part reellement : destinataire, sujet, lien,
 * contenu, et le gabarit effectivement retenu. Le systeme d'e-mail lui-meme
 * n'est pas touche.
 */
class LoopInvitationMailerTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Organization $otherOrganization;

    private User $sender;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['name' => 'Entraide Locale']);
        $this->otherOrganization = Organization::factory()->create(['name' => 'Autre Organization']);

        $this->sender = User::factory()->create([
            'organization_id' => $this->organization->id,
            'first_name' => 'Camille',
            'name' => 'Durand',
        ]);

        $this->loop = (new LoopService)->createLoop($this->sender, 'Boucle Entraide');

        app()->instance('current_organization', $this->organization);
    }

    private function invitationFor(string $email, ?string $name = null, ?string $message = null): LoopInvitation
    {
        return app(LoopInvitationService::class)->invite(
            $this->loop,
            $this->sender,
            $email,
            $name,
            $message,
        );
    }

    /**
     * Le courriel reellement remis.
     *
     * Pas `Mail::fake()` : le mailer appelle `Mail::html()`, qui n'instancie
     * aucun Mailable — le faux ne verrait rien passer. Le transport `array` du
     * harnais de test, lui, garde le message tel qu'il part.
     */
    private function lastEmail(): Email
    {
        $messages = Mail::mailer()->getSymfonyTransport()->messages();

        $this->assertNotEmpty($messages, 'Aucun courriel n\'est parti.');

        return $messages->last()->getOriginalMessage();
    }

    private function sentHtml(): string
    {
        return (string) $this->lastEmail()->getHtmlBody();
    }

    private function sentTo(): string
    {
        return $this->lastEmail()->getTo()[0]->getAddress();
    }

    private function sentSubject(): string
    {
        return (string) $this->lastEmail()->getSubject();
    }

    private function flushMail(): void
    {
        Mail::mailer()->getSymfonyTransport()->flush();
    }

    // ── Ce qui part ─────────────────────────────────────────────────────────

    public function test_the_message_goes_to_the_invited_address(): void
    {
        $this->flushMail();

        $invitation = $this->invitationFor('nouvelle@exemple.fr');
        app(LoopInvitationMailer::class)->send($invitation);

        $this->assertSame('nouvelle@exemple.fr', $this->sentTo());
    }

    public function test_the_subject_names_the_loop(): void
    {
        $this->flushMail();

        $invitation = $this->invitationFor('nouvelle@exemple.fr');
        app(LoopInvitationMailer::class)->send($invitation);

        $this->assertSame(
            __('loops.invitation_mail_subject', ['loop' => 'Boucle Entraide']),
            $this->sentSubject(),
        );
    }

    public function test_the_body_carries_the_invitation_link(): void
    {
        $this->flushMail();

        $invitation = $this->invitationFor('nouvelle@exemple.fr');
        app(LoopInvitationMailer::class)->send($invitation);

        $this->assertStringContainsString(
            route('loop-invitations.show', $invitation->token),
            $this->sentHtml(),
        );
    }

    public function test_the_body_renders_without_throwing_and_names_loop_and_organization(): void
    {
        $this->flushMail();

        $invitation = $this->invitationFor('nouvelle@exemple.fr');
        app(LoopInvitationMailer::class)->send($invitation);

        $html = $this->sentHtml();

        $this->assertNotSame('', $html);
        $this->assertStringContainsString('Boucle Entraide', $html);
        $this->assertStringContainsString('Entraide Locale', $html);
    }

    public function test_a_personal_message_travels_and_otherwise_a_default_one_does(): void
    {
        $this->flushMail();

        $withMessage = $this->invitationFor('avec@exemple.fr', null, 'On compte sur toi.');
        app(LoopInvitationMailer::class)->send($withMessage);
        $this->assertStringContainsString('On compte sur toi.', $this->sentHtml());

        $this->flushMail();

        $without = $this->invitationFor('sans@exemple.fr');
        app(LoopInvitationMailer::class)->send($without);
        $this->assertStringContainsString(
            __('loops.invitation_default_message', ['loop' => 'Boucle Entraide']),
            $this->sentHtml(),
        );
    }

    // ── Les deux langues ────────────────────────────────────────────────────

    public function test_the_french_wording_is_used_when_the_locale_is_french(): void
    {
        $this->flushMail();
        app()->setLocale('fr');

        $invitation = $this->invitationFor('nouvelle@exemple.fr');
        app(LoopInvitationMailer::class)->send($invitation);

        $html = $this->sentHtml();

        $this->assertStringContainsString(
            __('loops.invitation_mail_heading', ['sender' => 'Camille Durand', 'loop' => 'Boucle Entraide']),
            $html,
        );
        $this->assertStringContainsString(e(__('loops.invitation_mail_cta')), $html);

        $this->assertSame('Invitation à rejoindre la Boucle Boucle Entraide', $this->sentSubject());
    }

    public function test_the_english_wording_is_used_when_the_locale_is_english(): void
    {
        $this->flushMail();
        app()->setLocale('en');

        $invitation = $this->invitationFor('nouvelle@exemple.fr');
        app(LoopInvitationMailer::class)->send($invitation);

        $html = $this->sentHtml();

        $this->assertStringContainsString(
            __('loops.invitation_mail_heading', ['sender' => 'Camille Durand', 'loop' => 'Boucle Entraide']),
            $html,
        );
        $this->assertStringContainsString(e(__('loops.invitation_mail_cta')), $html);
        $this->assertSame('Invitation to join the Boucle Entraide Loop', $this->sentSubject());
    }

    // ── Le gabarit choisi, et le repli ──────────────────────────────────────

    public function test_an_enabled_template_of_this_organization_is_preferred(): void
    {
        $this->flushMail();
        app()->setLocale('fr');

        SystemEmailTemplate::factory()->create([
            'organization_id' => $this->organization->id,
            'locale' => 'fr',
            'slug' => 'loop_invitation',
            'enabled' => true,
            'subject' => 'Sujet maison pour {{ loop_name }}',
            'content_html' => '<p>Corps maison : {{ invitation_url }}</p>',
        ]);

        $invitation = $this->invitationFor('nouvelle@exemple.fr');
        app(LoopInvitationMailer::class)->send($invitation);

        $this->assertStringContainsString('Corps maison', $this->sentHtml());

        $this->assertSame('Sujet maison pour Boucle Entraide', $this->sentSubject());

        $this->assertSame('system_email_template', EmailLog::latest('id')->first()->data['template_used']);
    }

    public function test_a_disabled_template_falls_back_to_the_blade_one(): void
    {
        $this->flushMail();
        app()->setLocale('fr');

        SystemEmailTemplate::factory()->create([
            'organization_id' => $this->organization->id,
            'locale' => 'fr',
            'slug' => 'loop_invitation',
            'enabled' => false,
            'subject' => 'Sujet maison',
            'content_html' => '<p>Corps maison</p>',
        ]);

        $invitation = $this->invitationFor('nouvelle@exemple.fr');
        app(LoopInvitationMailer::class)->send($invitation);

        // Le repli est reellement exerce : le gabarit desactive ne sort pas, et
        // la raison du repli est nommee dans la trace.
        $this->assertStringNotContainsString('Corps maison', $this->sentHtml());

        $log = EmailLog::latest('id')->first();
        $this->assertSame('blade_fallback', $log->data['template_used']);
        $this->assertSame('template_disabled', $log->data['fallback_reason']);
    }

    public function test_a_missing_template_falls_back_and_says_so(): void
    {
        $this->flushMail();

        $invitation = $this->invitationFor('nouvelle@exemple.fr');
        app(LoopInvitationMailer::class)->send($invitation);

        $log = EmailLog::latest('id')->first();
        $this->assertSame('blade_fallback', $log->data['template_used']);
        $this->assertSame('template_missing', $log->data['fallback_reason']);
    }

    // ── Le cloisonnement des Organizations ──────────────────────────────────

    public function test_a_template_of_another_organization_is_never_used(): void
    {
        $this->flushMail();
        app()->setLocale('fr');

        SystemEmailTemplate::factory()->create([
            'organization_id' => $this->otherOrganization->id,
            'locale' => 'fr',
            'slug' => 'loop_invitation',
            'enabled' => true,
            'subject' => 'Sujet de l\'autre Organization',
            'content_html' => '<p>Corps de l\'autre Organization</p>',
        ]);

        $invitation = $this->invitationFor('nouvelle@exemple.fr');
        app(LoopInvitationMailer::class)->send($invitation);

        $this->assertStringNotContainsString('autre Organization', $this->sentHtml());
        $this->assertSame('blade_fallback', EmailLog::latest('id')->first()->data['template_used']);
    }

    public function test_the_log_is_scoped_to_the_organization_of_the_invitation(): void
    {
        $this->flushMail();

        $invitation = $this->invitationFor('nouvelle@exemple.fr');
        app(LoopInvitationMailer::class)->send($invitation);

        $log = EmailLog::latest('id')->first();

        $this->assertSame($this->organization->id, $log->organization_id);
        $this->assertNotSame($this->otherOrganization->id, $log->organization_id);
        $this->assertSame($this->loop->id, $log->data['loop_id']);
        $this->assertSame('sent', $log->status);
    }

    // ── Les deux chemins d'appel ────────────────────────────────────────────

    public function test_the_historic_post_route_sends_the_invitation(): void
    {
        $this->flushMail();

        $this->actingAs($this->sender)
            ->post(route('loops.invitations.store', $this->loop), [
                'email' => 'par-la-route@exemple.fr',
                'name' => 'Par La Route',
            ])
            ->assertRedirect();

        $this->assertSame('par-la-route@exemple.fr', $this->sentTo());

        $this->assertDatabaseHas('loop_invitations', [
            'loop_id' => $this->loop->id,
            'recipient_email' => 'par-la-route@exemple.fr',
        ]);
    }

    public function test_the_members_card_sends_the_same_invitation(): void
    {
        $this->flushMail();

        Livewire::actingAs($this->sender)
            ->test(LoopMembersCard::class, ['loop' => $this->loop])
            ->call('openAdd')
            ->set('inviteEmail', 'par-la-card@exemple.fr')
            ->set('inviteName', 'Par La Card')
            ->call('sendInvitation')
            ->assertHasNoErrors();

        $this->assertSame('par-la-card@exemple.fr', $this->sentTo());

        $this->assertDatabaseHas('loop_invitations', [
            'loop_id' => $this->loop->id,
            'recipient_email' => 'par-la-card@exemple.fr',
        ]);
    }

    public function test_both_paths_produce_the_same_subject_and_template_choice(): void
    {
        // Le seul interet d'avoir extrait ce code : les deux chemins ne peuvent
        // plus diverger. On le verifie plutot que de le supposer.
        $this->flushMail();
        $this->actingAs($this->sender)
            ->post(route('loops.invitations.store', $this->loop), ['email' => 'route@exemple.fr']);
        $viaRoute = EmailLog::latest('id')->first();
        $htmlViaRoute = $this->sentHtml();

        $this->flushMail();
        Livewire::actingAs($this->sender)
            ->test(LoopMembersCard::class, ['loop' => $this->loop])
            ->call('openAdd')
            ->set('inviteEmail', 'card@exemple.fr')
            ->call('sendInvitation');
        $viaCard = EmailLog::latest('id')->first();
        $htmlViaCard = $this->sentHtml();

        $this->assertSame($viaRoute->subject, $viaCard->subject);
        $this->assertSame($viaRoute->data['template_used'], $viaCard->data['template_used']);
        $this->assertSame($viaRoute->organization_id, $viaCard->organization_id);

        // Les corps ne different que par le jeton et l'adresse, propres a chaque
        // invitation : une fois neutralises, ils doivent etre identiques.
        $normalise = fn (string $html) => preg_replace(
            ['#/loop-invitations/[A-Za-z0-9]+#', '#[\w.+-]+@exemple\.fr#'],
            ['/loop-invitations/JETON', 'ADRESSE@exemple.fr'],
            $html,
        );

        $this->assertSame(
            $normalise($htmlViaRoute),
            $normalise($htmlViaCard),
            'Les deux chemins doivent rendre le meme gabarit.'
        );
    }
}
