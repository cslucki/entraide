<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Support\Email\HabillageOrganisation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TASK-1894 correctif 2 — habillage commun AU NOM DE L'ORGANISATION
 * (arbitrages Cyril du 23/11) : en-tete et pied au nom de l'organisation,
 * signature « Prenom pour Organisation » (sinon « L'equipe Organisation »),
 * mention des reglages seulement sur un email qu'on peut couper, jamais
 * « BouclePro ».
 */
class TASK1894HabillageOrganisationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.name' => 'BouclePro']);
        $this->org = Organization::factory()->create(['name' => 'OFSH', 'slug' => 'ofsh-1894', 'is_active' => true]);
        $admin = User::factory()->create(['organization_id' => $this->org->id, 'first_name' => 'Cyril', 'name' => 'SLUCKI']);
        $this->org->forceFill(['admin_id' => $admin->id])->save();
    }

    public function test_signature_prenom_pour_organisation_et_repli_l_equipe(): void
    {
        $this->assertSame('Cyril pour OFSH', HabillageOrganisation::pour($this->org, 'new_message', 'fr')['signature']);
        $this->assertSame('Cyril for OFSH', HabillageOrganisation::pour($this->org, 'new_message', 'en')['signature']);

        $sansAdmin = Organization::factory()->create(['name' => 'Rebondir', 'admin_id' => null]);
        $this->assertSame('L’équipe Rebondir', HabillageOrganisation::pour($sansAdmin, 'new_message', 'fr')['signature']);
        $this->assertSame('The Rebondir team', HabillageOrganisation::pour($sansAdmin, 'new_message', 'en')['signature']);
    }

    public function test_reglages_seulement_pour_un_email_qu_on_peut_couper(): void
    {
        $this->assertNotNull(HabillageOrganisation::pour($this->org, 'loop_mention_created', 'fr')['reglagesUrl']);

        foreach (['confirmation-email-suite-inscription', 'organization_invitation', 'liaison-comptes-confirmation', 'weekly_help_digest', 'new_message', 'transaction_status_changed'] as $slug) {
            $this->assertNull(HabillageOrganisation::pour($this->org, $slug, 'fr')['reglagesUrl'], $slug);
        }
    }

    public function test_le_gabarit_systeme_habille_ne_dit_jamais_bouclepro(): void
    {
        $html = (string) app(Markdown::class)->render('emails.system-email', ['html' => '<p>Corps</p>']
            + HabillageOrganisation::pour($this->org, 'private_message_received', 'fr'));
        $corps = substr($html, (int) strpos($html, '<body'));

        $this->assertStringContainsString('OFSH', $corps);
        $this->assertStringContainsString('Cyril pour OFSH', $corps);
        $this->assertStringContainsString('Réglages des notifications', $corps);
        $this->assertStringNotContainsString('BouclePro', $corps);
    }

    public function test_la_confirmation_d_inscription_n_a_pas_de_mention_de_reglages(): void
    {
        $html = (string) app(Markdown::class)->render('emails.system-email', ['html' => '<p>Corps</p>']
            + HabillageOrganisation::pour($this->org, 'confirmation-email-suite-inscription', 'fr'));

        $this->assertStringContainsString('Cyril pour OFSH', $html);
        $this->assertStringNotContainsString('Pour ne plus recevoir', $html);
    }

    public function test_le_cadre_des_notifications_porte_l_organisation(): void
    {
        $html = view('emails.notification-shell', ['html' => '<p>Corps</p>', 'themeCss' => '']
            + HabillageOrganisation::pour($this->org, 'notification', 'fr'))->render();

        $this->assertStringContainsString('Cyril pour OFSH', $html);
        $this->assertStringContainsString('&copy; '.date('Y').' OFSH', $html);
        $this->assertStringContainsString(route('organization.notifications.preferences.edit', ['organization' => $this->org]), $html);
        $this->assertStringNotContainsString('BouclePro', $html);
    }

    public function test_sans_habillage_le_cadre_historique_reste_intact(): void
    {
        $html = view('emails.notification-shell', ['html' => '<p>Corps</p>', 'themeCss' => ''])->render();

        $this->assertStringContainsString('BouclePro', $html);
        $this->assertStringNotContainsString('Pour ne plus recevoir', $html);
    }

    public function test_la_migration_des_trois_exemples_est_idempotente_et_reversible(): void
    {
        $migration = require database_path('migrations/2026_11_24_100000_habillage_organisation_trois_gabarits_exemples.php');
        $avant = '<p>Bonjour</p><p><a href="{{ message_url }}">Lire le message dans la Boucle</a></p><p>À bientôt,<br>L’équipe {{ app_name }}</p><hr style="margin:24px 0;border:0;border-top:1px solid #eee"><p style="font-size:12px;color:#777">Pour ne plus recevoir cet email, allez dans <a href="{{ preferences_url }}">Réglages des notifications</a>.</p>';

        DB::table('system_email_templates')->where('slug', 'loop_mention_created')->delete();
        \App\Models\SystemEmailTemplate::query()->create([
            'slug' => 'loop_mention_created', 'locale' => 'fr', 'organization_id' => null,
            'name' => 'Mention', 'subject' => 'S', 'content_html' => $avant, 'variables' => [], 'enabled' => true,
        ]);
        $lire = fn () => (string) DB::table('system_email_templates')->where('slug', 'loop_mention_created')->value('content_html');

        $migration->up();
        $apres = $lire();
        $this->assertStringContainsString(HabillageOrganisation::BOUTON, $apres);
        $this->assertStringNotContainsString('L’équipe {{ app_name }}', $apres);

        $migration->up();
        $this->assertSame($apres, $lire(), 'Rejouer ne change rien.');

        $migration->down();
        $this->assertSame($avant, $lire(), 'Le retour remet le texte d’origine.');
    }

    public function test_la_reprise_de_tous_les_gabarits_retire_la_marque_et_pose_un_bouton(): void
    {
        $migration = require database_path('migrations/2026_11_24_100100_habillage_organisation_tous_les_gabarits.php');

        $avant = '<p>Bonjour</p><p><strong>A</strong> vous invite sur {{ app_name }}.</p><p><a href="{{ comment_url }}">Voir</a></p>'
            .'<p>À bientôt,<br>L’équipe {{ app_name }}</p><hr style="margin:24px 0;border:0;border-top:1px solid #eee">'
            .'<p style="font-size:12px;color:#777">Pour ne plus recevoir cet email, allez dans <a href="{{ preferences_url }}">Réglages des notifications</a>.</p>';
        $apres = $migration::transformer($avant);

        $this->assertSame('<p>Bonjour</p><p><strong>A</strong> vous invite.</p><p><a href="{{ comment_url }}" style="'.HabillageOrganisation::BOUTON.'">Voir</a></p>', $apres);
        $this->assertSame($apres, $migration::transformer($apres), 'Idempotente.');

        // Le lien de desinscription de la newsletter ne devient jamais un bouton.
        $lettre = '<p><a href="{{ unsubscribe_url }}">Se désinscrire</a></p>';
        $this->assertSame($lettre, $migration::transformer($lettre));
    }
}
