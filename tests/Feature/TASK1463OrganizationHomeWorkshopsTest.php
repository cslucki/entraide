<?php

namespace Tests\Feature;

use App\Models\AcquisitionJourney;
use App\Models\GuestVisitor;
use App\Models\Organization;
use App\Models\OrganizationShortcut;
use App\Models\User;
use App\Services\Acquisition\AcquisitionJourneyService;
use App\Services\Acquisition\GuestAttribution;
use App\Services\GuestShell\GuestVisitorResolver;
use App\Services\Workshops\PublicWorkshopListing;
use App\Services\Workshops\WorkshopService;
use App\Services\Workshops\WorkshopSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * TASK-1463 — audit OPUS final P1-1 (Growth V3 §8) : l'accueil public d'une
 * Organization propose ses ateliers (PUBLIES, avec une session PUBLIEE a
 * venir — la meme selection que workshops.runtime), sans cookie ni identite a
 * l'affichage ; chaque lien interne transporte SEULEMENT le code de Shortcut et
 * les UTM autorises, bornes — jamais une Journey ni une campagne, relues en
 * base au premier geste (GuestAttribution::resolve). Les trois gabarits
 * d'accueil portent le bloc ; aucune donnee privee.
 */
class TASK1463OrganizationHomeWorkshopsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $a;

    private Organization $b;

    private User $adminA;

    private User $superAdmin;

    private AcquisitionJourney $journey;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-08 10:00:00');
        $this->a = Organization::factory()->create(['slug' => 'org-a-1463', 'name' => 'A Guild', 'is_active' => true, 'is_public' => true, 'locale' => 'fr']);
        $this->b = Organization::factory()->create(['slug' => 'org-b-1463', 'name' => 'B Guild', 'is_active' => true, 'is_public' => true, 'locale' => 'fr']);
        $this->adminA = User::factory()->create(['organization_id' => $this->a->id]);
        $this->a->update(['admin_id' => $this->adminA->id]);
        $adminB = User::factory()->create(['organization_id' => $this->b->id]);
        $this->b->update(['admin_id' => $adminB->id]);
        $this->superAdmin = User::factory()->create(['organization_id' => $this->b->id, 'is_admin' => true]);

        $journeys = app(AcquisitionJourneyService::class);
        $this->journey = $journeys->createDraft($this->a, ['key' => 'rentree', 'name' => 'Rentrée', 'locale' => 'fr', 'conversion_goal' => AcquisitionJourney::GOAL_WORKSHOP_PARTICIPATION, 'campaign' => 'sept-2026'], $this->adminA);
        $journeys->publish($this->journey, $this->adminA);
        OrganizationShortcut::query()->create(['organization_id' => $this->a->id, 'code' => 'demo', 'destination' => 'organization_home', 'acquisition_journey_key' => 'rentree', 'campaign' => 'sept-2026', 'active' => true, 'created_by' => $this->superAdmin->id]);

        $workshops = app(WorkshopService::class);
        $sessions = app(WorkshopSessionService::class);
        $open = $workshops->create($this->a, ['title' => 'Découvrir l\'IA', 'slug' => 'ia-90', 'format' => 'online', 'locale' => 'fr', 'promise' => 'Comprendre l\'IA en 90 minutes', 'description' => 'DESCRIPTION LONGUE réservée à la page.'], $this->adminA);
        $workshops->publish($open, $this->adminA);
        $sessions->publish($sessions->create($open, ['starts_at' => '2026-10-01 18:30', 'timezone' => 'Europe/Paris', 'location' => 'https://meet.example.test/secret-room', 'capacity' => 7], $this->adminA), $this->adminA);
        foreach ([2, 3, 4] as $i) {
            $w = $workshops->create($this->a, ['title' => "Atelier n{$i}", 'slug' => "atelier-{$i}", 'format' => 'online', 'locale' => 'fr'], $this->adminA);
            $workshops->publish($w, $this->adminA);
            $sessions->publish($sessions->create($w, ['starts_at' => sprintf('2026-10-%02d 18:30', $i), 'timezone' => 'Europe/Paris'], $this->adminA), $this->adminA);
        }
        $draft = $workshops->create($this->a, ['title' => 'Brouillon secret', 'slug' => 'brouillon', 'format' => 'online', 'locale' => 'fr'], $this->adminA);
        $sessions->publish($sessions->create($draft, ['starts_at' => '2026-10-01 10:00', 'timezone' => 'Europe/Paris'], $this->adminA), $this->adminA);
        $past = $workshops->create($this->a, ['title' => 'Atelier passé', 'slug' => 'passe', 'format' => 'online', 'locale' => 'fr'], $this->adminA);
        $workshops->publish($past, $this->adminA);
        $sessions->publish($sessions->create($past, ['starts_at' => '2026-09-01 18:30', 'timezone' => 'Europe/Paris'], $this->adminA), $this->adminA);
        $noSession = $workshops->create($this->a, ['title' => 'Sans session', 'slug' => 'sans-session', 'format' => 'online', 'locale' => 'fr'], $this->adminA);
        $workshops->publish($noSession, $this->adminA);
        $foreign = $workshops->create($this->b, ['title' => 'Atelier de B', 'slug' => 'ia-90', 'format' => 'online', 'locale' => 'fr'], $adminB);
        $workshops->publish($foreign, $adminB);
        $sessions->publish($sessions->create($foreign, ['starts_at' => '2026-10-01 18:30', 'timezone' => 'Europe/Paris'], $adminB), $adminB);
    }

    private function home(Organization $organization, string $query = ''): TestResponse
    {
        return $this->withCredentials()->get(route('organization.home', ['organization' => $organization->slug]).$query);
    }

    /** @return array<int, string> les href des liens d'ateliers du bloc */
    private function links(string $html): array
    {
        preg_match_all('/href="([^"]+)"[^>]*data-org-workshop-link/', $html, $m);

        return array_map(fn (string $href) => html_entity_decode($href), $m[1]);
    }

    public function test_the_public_home_lists_the_open_workshops_with_bounded_attribution_links_and_creates_no_identity(): void
    {
        $response = $this->home($this->a, '?shortcut=demo&utm_source=newsletter&utm_medium=%3Cb%3Email%3C%2Fb%3E&journey=forged&campaign=forged&foo=bar')->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('data-org-workshops', $html);
        $this->assertStringContainsString('Découvrir l&#039;IA', $html);
        $this->assertStringContainsString('Comprendre l&#039;IA en 90 minutes', $html, 'la promesse courte, deja publique');
        $this->assertStringContainsString('jeudi 1 octobre 2026, 18h30', $html, 'la prochaine session en heure locale');
        $this->assertStringContainsString('Europe/Paris', $html);
        foreach (['Brouillon secret', 'Atelier passé', 'Sans session', 'Atelier de B', 'Atelier n4', 'secret-room', 'DESCRIPTION LONGUE', 'meet.'] as $never) {
            $this->assertStringNotContainsString($never, $html, "jamais : {$never}");
        }
        $this->assertSame(PublicWorkshopListing::MAX, substr_count($html, 'data-org-workshop="'), 'au plus N ateliers, les plus proches d\'abord');

        // Les liens internes : le code de Shortcut + les UTM autorises et bornes — jamais journey/campaign/inconnus.
        $links = $this->links($html);
        $this->assertCount(PublicWorkshopListing::MAX, $links);
        $first = $links[0];
        $this->assertStringStartsWith(route('organization.workshop.show', ['organization' => $this->a->slug, 'workshop' => 'ia-90']), $first);
        $this->assertStringContainsString('shortcut=demo', $first);
        $this->assertStringContainsString('utm_source=newsletter', $first);
        $this->assertStringContainsString('utm_medium=bmailb', $first, 'UTM borne et assaini');
        foreach (['journey=', 'campaign=', 'foo=', '%3C', '<'] as $never) {
            $this->assertStringNotContainsString($never, $first, "le lien ne transporte jamais : {$never}");
        }
        $this->assertSame(['shortcut' => 'demo', 'utm_source' => 'newsletter', 'utm_medium' => 'bmailb'], GuestAttribution::carry(['shortcut' => 'demo', 'utm_source' => 'newsletter', 'utm_medium' => '<b>mail</b>', 'journey' => 'forged', 'campaign' => 'forged', 'foo' => 'bar']));
        $this->assertSame([], GuestAttribution::carry(['shortcut' => '../../etc', 'journey' => 'x']), 'un code invalide n\'est pas transporte');

        // Lecture pure : aucun cookie Guest, aucune identite a l'affichage.
        $this->assertEmpty(array_filter($response->headers->getCookies(), fn ($c) => $c->getName() === GuestVisitorResolver::COOKIE), 'aucun cookie Guest pose a l\'affichage');
        $this->assertSame(0, GuestVisitor::query()->count());

        // Sans lien court ni UTM : des liens nus (aucun parametre invente).
        $bare = $this->links($this->home($this->a)->assertOk()->getContent());
        $this->assertSame(route('organization.workshop.show', ['organization' => $this->a->slug, 'workshop' => 'ia-90']), $bare[0]);

        // L'accueil de B ne montre que les ateliers de B — jamais ceux d'A.
        $htmlB = $this->home($this->b)->assertOk()->getContent();
        $this->assertStringContainsString('data-org-workshops', $htmlB);
        $this->assertStringContainsString('Atelier de B', $htmlB);
        $this->assertStringNotContainsString('Découvrir l&#039;IA', $htmlB, 'jamais un atelier d\'une autre Organization (« Découvrir les boucles » est un libellé générique de l\'accueil)');
    }

    public function test_the_attribution_survives_from_the_short_link_to_the_first_gesture_without_the_shell_and_the_browser_is_never_the_authority(): void
    {
        // /s/demo → accueil (attribution dans l'URL) → lien interne du bloc → page atelier → « Je choisis cette session ».
        $landing = $this->withCredentials()->get('/s/demo')->assertRedirect()->headers->get('Location');
        $this->assertStringContainsString('shortcut=demo', $landing);
        // Un navigateur qui falsifie la Journey et la campagne dans l'URL d'atterrissage : rien de tout cela n'est transporte ni cru.
        $forged = $landing.'&journey=autre&campaign=falsifiee&utm_campaign=externe';
        $link = $this->links($this->withCredentials()->get($forged)->assertOk()->getContent())[0];
        $this->assertStringContainsString('shortcut=demo', $link);
        $this->assertStringContainsString('utm_campaign=externe', $link, 'un UTM externe est transporte, borne');
        $this->assertStringNotContainsString('journey=', $link);
        $this->assertStringNotContainsString('campaign=falsifiee', $link);

        $page = $this->withCredentials()->get($link)->assertOk()->getContent();
        preg_match('/<form method="POST" action="([^"]+\/interest)"[^>]*>(.*?)<\/form>/s', $page, $form);
        $this->assertNotEmpty($form, 'le geste Guest « je choisis cette session » est present');
        preg_match_all('/name="attribution\[(\w+)\]" value="([^"]*)"/', $form[2], $hidden, PREG_SET_ORDER);
        $attribution = [];
        foreach ($hidden as [$all, $key, $value]) {
            $attribution[$key] = html_entity_decode($value);
        }
        $this->assertSame('demo', $attribution['shortcut']);
        $this->assertSame(0, GuestVisitor::query()->count(), 'toujours aucune identite avant le premier geste');

        $this->withCredentials()->post(html_entity_decode($form[1]), ['attribution' => $attribution])->assertRedirect();
        $visitor = GuestVisitor::forOrganization($this->a)->sole();
        $this->assertSame($this->journey->id, $visitor->acquisition_journey_id, 'la Journey EXACTE vient du Shortcut relu en base, jamais de l\'URL');
        $this->assertSame('sept-2026', $visitor->utm_campaign, 'la campagne canonique du Shortcut prime sur l\'UTM externe');
        $this->assertSame('demo', $visitor->shortcut);
    }

    public function test_the_three_home_templates_carry_the_block_and_a_private_or_inactive_organization_shows_nothing(): void
    {
        foreach (['bouclepro_hero_v2', 'artscilab_hero', null] as $template) {
            $this->a->forceFill(['homepage_template' => $template])->save();
            $html = $this->home($this->a, '?shortcut=demo')->assertOk()->getContent();
            $this->assertStringContainsString('data-org-workshops', $html, "gabarit {$template}");
            $this->assertStringContainsString('shortcut=demo', $this->links($html)[0], "gabarit {$template} : le lien transporte le code");
            $this->assertStringNotContainsString('secret-room', $html);
        }
        // Organization NON publique : son accueil n'est servi qu'a ses membres — et meme a eux, aucun bloc public (les pages atelier publiques sont fermees).
        $this->a->forceFill(['homepage_template' => null, 'is_public' => false])->save();
        $private = $this->actingAs($this->adminA)->get(route('organization.home', ['organization' => $this->a->slug]));
        $this->assertContains($private->getStatusCode(), [200, 302]);
        $this->assertStringNotContainsString('data-org-workshops', (string) $private->getContent(), 'une Organization non publique n\'expose rien');
        if ($private->getStatusCode() === 200) {
            $this->assertStringContainsString($this->a->name, $private->getContent(), 'la preuve porte sur un accueil reellement rendu');
        }
    }
}
