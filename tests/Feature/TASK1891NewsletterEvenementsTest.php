<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopEvent;
use App\Models\Organization;
use App\Models\SystemEmailTemplate;
use App\Models\User;
use App\Services\BulkEmailSendService;
use App\Support\Email\HelpDigestBlocks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1891 (demande Cyril, 23/11) — les prochains evenements de l'agenda,
 * EN TETE de la newsletter : `{evenements;N}`, nombre choisi a l'envoi.
 */
class TASK1891NewsletterEvenementsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Organization $autre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['is_active' => true, 'name' => 'Entraide Test']);
        $this->autre = Organization::factory()->create(['is_active' => true]);
        // Aucun tenant lie : la situation d'un job de la Managed Queue.
        app()->forgetInstance('current_organization');
    }

    private function evenement(Organization $org, string $titre, array $attributs = []): LoopEvent
    {
        $createur = User::factory()->create(['organization_id' => $org->id]);
        $loop = Loop::factory()->create(['organization_id' => $org->id, 'created_by' => $createur->id]);

        $event = new LoopEvent;
        $event->forceFill($attributs + [
            'organization_id' => $org->id,
            'loop_id' => $loop->id,
            'created_by' => $createur->id,
            'title' => $titre,
            'format' => LoopEvent::FORMAT_IN_PERSON,
            'location' => 'Salle A',
            'starts_at' => now()->addDays(2),
            'timezone' => 'Europe/Paris',
            'visibility' => LoopEvent::VISIBILITY_ORGANIZATION,
            'status' => LoopEvent::STATUS_SCHEDULED,
        ])->save();

        return $event;
    }

    public function test_les_prochains_evenements_ouverts_a_toute_l_organisation_seulement(): void
    {
        $blocs = new HelpDigestBlocks;
        $this->assertSame('<p>A</p><p>B</p>', $blocs->developper('<p>A</p>{evenements;3}<p>B</p>', $this->org, 'fr'), 'Sans evenement : rien, pas meme le titre.');

        $this->evenement($this->org, 'Plus tard', ['starts_at' => now()->addDays(9)]);
        $this->evenement($this->org, 'Bientot', ['starts_at' => now()->addHours(3)]);
        $this->evenement($this->org, 'Visio', ['starts_at' => now()->addDays(4), 'format' => LoopEvent::FORMAT_ONLINE, 'location' => null]);
        $this->evenement($this->org, 'En cours', ['starts_at' => now()->subHour(), 'ends_at' => now()->addHour()]);
        $this->evenement($this->org, 'Passe', ['starts_at' => now()->subDays(2)]);
        $this->evenement($this->org, 'Annule', ['status' => LoopEvent::STATUS_CANCELLED, 'cancelled_at' => now()]);
        $this->evenement($this->org, 'Reserve a la Boucle', ['visibility' => LoopEvent::VISIBILITY_LOOP]);
        $this->evenement($this->autre, 'Ailleurs');

        $html = (new HelpDigestBlocks)->developper('{evenements;1}', $this->org, 'fr', null, null, null, 3);

        $this->assertStringContainsString('À l’agenda', $html);
        $this->assertSame(3, substr_count($html, '<li'), 'Le nombre de l ecran remplace celui du gabarit.');
        $this->assertLessThan(strpos($html, 'Bientot'), strpos($html, 'En cours'));
        $this->assertLessThan(strpos($html, 'Visio'), strpos($html, 'Bientot'));
        $this->assertStringContainsString('en ligne', $html);
        $this->assertStringContainsString('Salle A', $html);
        $this->assertStringContainsString(route('organization.events.agenda', ['organization' => $this->org->slug]), $html);
        foreach (['Plus tard', 'Passe', 'Annule', 'Reserve a la Boucle', 'Ailleurs'] as $absent) {
            $this->assertStringNotContainsString($absent, $html);
        }

        $this->assertSame('', (new HelpDigestBlocks)->developper('{evenements;3}', $this->org, 'fr', null, null, null, 0));
        $this->assertStringContainsString('Coming up', (new HelpDigestBlocks)->developper('{evenements;3}', $this->org, 'en'));
        $this->assertTrue((new HelpDigestBlocks)->aDuContenu($this->org, 0, 0, 0, 1));
        $this->assertFalse((new HelpDigestBlocks)->aDuContenu($this->autre, 0, 0, 0, 0));
    }

    public function test_le_titre_de_l_evenement_est_echappe(): void
    {
        $this->evenement($this->org, '<script>x</script> Atelier');

        $html = (new HelpDigestBlocks)->developper('{evenements;3}', $this->org, 'fr');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_le_gabarit_met_les_evenements_en_premier(): void
    {
        foreach (['fr', 'en'] as $locale) {
            $html = SystemEmailTemplate::query()->whereNull('organization_id')
                ->where('slug', 'weekly_help_digest')->where('locale', $locale)->firstOrFail()->content_html;

            $this->assertSame(1, substr_count($html, '{evenements;3}'));
            $this->assertLessThan(strpos($html, '<h2'), strpos($html, '{evenements;3}'));
            $this->assertLessThan(strpos($html, '{demande_aide;3}'), strpos($html, '{evenements;3}'));
        }
    }

    public function test_la_migration_insere_sans_reecrire_un_gabarit_modifie_et_une_seule_fois(): void
    {
        $ligne = SystemEmailTemplate::query()->whereNull('organization_id')
            ->where('slug', 'weekly_help_digest')->where('locale', 'fr')->firstOrFail();
        $ligne->forceFill(['content_html' => '<p>Salut</p><p>Texte maison</p>{proposition_aide;2}'])->save();

        $migration = require database_path('migrations/2026_11_24_090100_add_events_block_to_weekly_help_digest_template.php');
        $migration->up();
        $migration->up();

        $this->assertSame('<p>Salut</p><p>Texte maison</p>{evenements;3}{proposition_aide;2}', $ligne->fresh()->content_html);

        $migration->down();
        $this->assertSame('<p>Salut</p><p>Texte maison</p>{proposition_aide;2}', $ligne->fresh()->content_html);
    }

    public function test_une_organisation_avec_seulement_des_evenements_recoit_la_newsletter(): void
    {
        $this->evenement($this->org, 'Assemblee');
        $membre = User::factory()->create(['organization_id' => $this->org->id]);

        $sans = app(BulkEmailSendService::class)->newsletterRecipients([$this->org->id], 0, 0, 0, 0)->pluck('id');
        $avec = app(BulkEmailSendService::class)->newsletterRecipients([$this->org->id], 0, 0, 0, 2)->pluck('id');

        $this->assertNotContains($membre->id, $sans);
        $this->assertContains($membre->id, $avec);
    }

    public function test_l_ecran_admin_propose_le_nombre_et_l_apercu_place_l_agenda_en_tete(): void
    {
        $this->evenement($this->org, 'Assemblee generale');
        $superadmin = User::factory()->create(['organization_id' => $this->org->id, 'is_admin' => true]);

        $this->actingAs($superadmin)->get(route('admin.newsletter'))
            ->assertOk()
            ->assertSee('data-admin-newsletter-event-count', false);

        $rendu = $this->actingAs($superadmin)->postJson(route('admin.newsletter.preview'), [
            'scope' => 'selection', 'organization_ids' => [$this->org->id], 'subject' => '',
            'demand_count' => 3, 'offer_count' => 3, 'blog_count' => 0, 'event_count' => 2, 'locale' => 'fr',
        ])->assertOk()->json();

        $this->assertStringContainsString('Assemblee generale', $rendu['html']);
        $this->assertLessThan(strpos($rendu['html'], 'Ils ont besoin d’aide'), strpos($rendu['html'], 'À l’agenda'));
        $this->assertGreaterThanOrEqual(1, $rendu['recipients']);
    }

    /** Correctif 2 — la newsletter parle au nom de l'organisation, en tete et en pied. */
    public function test_l_en_tete_et_le_pied_portent_le_nom_de_l_organisation(): void
    {
        config(['app.name' => 'BouclePro']);
        $this->evenement($this->org, 'Assemblee');
        $membre = User::factory()->create(['organization_id' => $this->org->id, 'preferred_locale' => 'fr']);
        $envoi = new \App\Models\BulkEmailSend(['system_template_slug' => BulkEmailSendService::NEWSLETTER_SLUG, 'demand_count' => 3, 'offer_count' => 3, 'blog_count' => 0, 'event_count' => 3]);

        $html = app(BulkEmailSendService::class)->newsletterPour($membre->load('organization'), $envoi)['body'];

        $entete = $this->entete($html);
        $this->assertStringContainsString('Entraide Test', $entete);
        $this->assertStringNotContainsString('BouclePro', $entete);
        $this->assertStringContainsString('© '.date('Y').' Entraide Test', $html);
        // Seul le `<title>` technique du `<head>` (jamais affiche) garde la marque.
        $this->assertStringNotContainsString('BouclePro', substr($html, strpos($html, '<body')));

        // Les autres courriels systeme gardent l'habillage de la plateforme.
        $autre = (string) app(\Illuminate\Mail\Markdown::class)->render('emails.system-email', ['html' => '<p>x</p>']);
        $this->assertStringContainsString('BouclePro', $this->entete($autre));
    }

    /** Correctif 3 — le rappel du dernier envoi montre l'objet avec le VRAI nom. */
    public function test_le_rappel_du_dernier_envoi_montre_le_vrai_nom_de_l_organisation(): void
    {
        $this->evenement($this->org, 'Assemblee');
        User::factory()->create(['organization_id' => $this->org->id]);
        $superadmin = User::factory()->create(['organization_id' => $this->org->id, 'is_admin' => true]);

        $envoi = app(BulkEmailSendService::class)->createNewsletterSend([
            'organization_ids' => [$this->org->id], 'subject' => 'Recette — {{ organization_name }} cette semaine',
            'demand_count' => 3, 'offer_count' => 3, 'blog_count' => 0, 'event_count' => 3,
        ], $superadmin);

        $page = $this->actingAs($superadmin)->get(route('admin.newsletter'))->assertOk()->getContent();
        $debut = strpos($page, 'data-admin-newsletter-repeat');
        $rappel = substr($page, $debut, strpos($page, '</label>', $debut) - $debut);

        $this->assertStringContainsString('« Recette — Entraide Test cette semaine »', html_entity_decode($rappel));
        $this->assertStringNotContainsString('organization_name', $rappel);

        $this->actingAs($superadmin)->get(route('admin.newsletter.show', $envoi))
            ->assertOk()
            ->assertSee('Recette — Entraide Test cette semaine');

        $toutes = new \App\Models\BulkEmailSend(['subject' => '{{organization_name}} cette semaine']);
        $this->assertSame('nom de chaque organisation cette semaine', $toutes->setRelation('organizations', collect())->objetLisible());
    }

    private function entete(string $html): string
    {
        $debut = strpos($html, 'class="header"');

        return substr($html, $debut, strpos($html, '</td>', $debut) - $debut);
    }
}
