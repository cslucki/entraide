<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Models\Workshop;
use App\Models\WorkshopSession;
use App\Services\Workshops\PublicWorkshopListing;
use App\Services\Workshops\WorkshopService;
use App\Services\Workshops\WorkshopSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * TASK-1611 — le prochain atelier est visible SANS DESCENDRE, dans le HERO de
 * l'accueil BouclePro (`bouclepro_hero_v2`).
 *
 * Un seul composant, quatre situations commandees par la donnee :
 *   0 session a venir        => rien, et l'orbite d'origine garde la colonne droite ;
 *   1 session, sans flyer    => etat A « solo » ;
 *   1 session, AVEC flyer    => etat B « media » ;
 *   2 sessions ou plus       => etat C « liste », les 2 plus proches, sans flyer.
 *
 * La selection reste celle de TASK-1463 (atelier publie de CETTE Organization,
 * session publiee a venir) : ce test la reprouve depuis le HERO — brouillon,
 * passe, annule et voisin de tenant restent invisibles.
 */
class TASK1611HeroNextEventsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $a;

    private Organization $b;

    private User $adminA;

    private User $adminB;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-20 10:00:00');

        $this->a = Organization::factory()->create(['slug' => 'org-a-1611', 'name' => 'A Guild', 'is_active' => true, 'is_public' => true, 'locale' => 'fr', 'homepage_template' => 'bouclepro_hero_v2']);
        $this->b = Organization::factory()->create(['slug' => 'org-b-1611', 'name' => 'B Guild', 'is_active' => true, 'is_public' => true, 'locale' => 'fr', 'homepage_template' => 'bouclepro_hero_v2']);
        $this->adminA = User::factory()->create(['organization_id' => $this->a->id]);
        $this->a->update(['admin_id' => $this->adminA->id]);
        $this->adminB = User::factory()->create(['organization_id' => $this->b->id]);
        $this->b->update(['admin_id' => $this->adminB->id]);
    }

    /** Un atelier PUBLIE et, pour chaque date fournie, une session PUBLIEE. */
    private function publishedWorkshop(Organization $organization, User $admin, array $attributes, array $startsAt = []): Workshop
    {
        $workshop = app(WorkshopService::class)->create($organization, $attributes + ['format' => 'online', 'locale' => 'fr'], $admin);
        app(WorkshopService::class)->publish($workshop, $admin);

        foreach ($startsAt as $start) {
            $session = app(WorkshopSessionService::class)->create($workshop, ['starts_at' => $start, 'ends_at' => Carbon::parse($start)->addMinutes(45)->format('Y-m-d H:i'), 'timezone' => 'Europe/Paris'], $admin);
            app(WorkshopSessionService::class)->publish($session, $admin);
        }

        return $workshop->refresh();
    }

    private function homeHtml(Organization $organization, string $query = ''): string
    {
        return $this->withCredentials()->get(route('organization.home', ['organization' => $organization->slug]).$query)->assertOk()->getContent();
    }

    public function test_without_any_upcoming_session_the_hero_keeps_its_original_right_hand_visual(): void
    {
        // Un atelier publie SANS session, et un atelier publie dont la seule session est PASSEE.
        $this->publishedWorkshop($this->a, $this->adminA, ['title' => 'Sans date', 'slug' => 'sans-date']);
        $this->publishedWorkshop($this->a, $this->adminA, ['title' => 'Atelier passé', 'slug' => 'passe'], ['2026-09-01 18:30']);

        $html = $this->homeHtml($this->a);

        $this->assertStringNotContainsString('data-hero-next', $html, 'aucune session a venir : le composant ne rend rien');
        $this->assertStringNotContainsString('hero--next', $html, 'et le HERO ne bascule pas en mode « prochain atelier »');
        $this->assertStringContainsString('class="orbit"', $html, 'la colonne droite garde son visuel d\'origine — jamais vide');
        $this->assertStringContainsString('rings-img', $html);
        $this->assertStringNotContainsString('Atelier passé', $html);
        $this->assertStringNotContainsString('Sans date', $html);
    }

    public function test_a_single_upcoming_session_renders_the_solo_state_with_its_date_time_status_and_bounded_link(): void
    {
        $this->publishedWorkshop($this->a, $this->adminA, [
            'title' => 'Dogfood 1450 : découvrir BouclePro',
            'slug' => 'dogfood-1450',
            'promise' => 'Comprendre en 45 minutes ce que BouclePro fait pour vous.',
            'duration_minutes' => 45,
        ], ['2026-10-22 18:30']);

        $html = $this->homeHtml($this->a, '?shortcut=demo&utm_source=newsletter&journey=forged&foo=bar');

        $this->assertStringContainsString('bp-next--solo', $html);
        $this->assertStringNotContainsString('bp-next--list', $html);
        $this->assertStringContainsString('Prochain atelier', $html);
        $this->assertStringContainsString('Dogfood 1450 : découvrir BouclePro', $html);
        $this->assertStringContainsString('Comprendre en 45 minutes', $html, 'la promesse, deja publique');
        $this->assertStringContainsString('>22<', $html, 'le quantieme en grand');
        $this->assertStringContainsString('18h30', $html, 'l\'heure de debut, dans le fuseau de la session');
        $this->assertStringContainsString('19h15', $html, 'et l\'heure de fin');
        $this->assertStringContainsString('En ligne', $html, 'le statut vient du format de l\'atelier');
        $this->assertStringContainsString('45 min', $html, 'la duree, deja portee par l\'atelier');
        $this->assertStringContainsString('2026-10-22T18:30:00+02:00', $html, 'la date machine du <time>');

        // L'orbite a cede la place, et le bloc bas de page ne repete plus la meme chose.
        $this->assertStringContainsString('hero--next', $html);
        $this->assertStringNotContainsString('class="orbit"', $html);
        $this->assertStringNotContainsString('data-org-workshops', $html);

        // Le lien : la page de l'atelier, avec le code de Shortcut et les UTM bornes — jamais la Journey ni un parametre inconnu.
        preg_match('/href="([^"]+)"[^>]*data-hero-next-link/', $html, $m);
        $this->assertNotEmpty($m, 'la carte entiere est UN lien vers le detail');
        $href = html_entity_decode($m[1]);
        $this->assertStringStartsWith(route('organization.workshop.show', ['organization' => $this->a->slug, 'workshop' => 'dogfood-1450']), $href);
        $this->assertStringContainsString('shortcut=demo', $href);
        $this->assertStringContainsString('utm_source=newsletter', $href);
        foreach (['journey=', 'foo='] as $never) {
            $this->assertStringNotContainsString($never, $href, "le lien ne transporte jamais : {$never}");
        }

        // Accessibilite : un seul <a> par carte, jamais de lien imbrique.
        preg_match('/<section class="bp-next.*?<\/section>/s', $html, $section);
        $this->assertSame(1, substr_count($section[0], '<a '), 'l\'etat solo n\'expose qu\'un seul lien');
        $this->assertStringContainsString('aria-labelledby="bp-next-title"', $section[0]);
    }

    public function test_a_single_upcoming_session_with_a_flyer_renders_the_media_state(): void
    {
        Storage::fake('public');

        $workshop = $this->publishedWorkshop($this->a, $this->adminA, ['title' => 'Atelier illustré', 'slug' => 'illustre'], ['2026-10-22 18:30']);

        // Sans visuel : etat A.
        $bare = $this->homeHtml($this->a);
        $this->assertStringContainsString('bp-next--solo', $bare);
        $this->assertStringNotContainsString('bp-next--media', $bare);
        $this->assertStringNotContainsString('data-hero-next-flyer', $bare);

        // Le MEME atelier, un flyer attache : etat B, sans qu'on ait rien choisi.
        app(WorkshopService::class)->attachFlyer($workshop, UploadedFile::fake()->image('flyer.jpg', 1200, 630), $this->adminA);

        $html = $this->homeHtml($this->a);
        $this->assertStringContainsString('bp-next--media', $html);
        $this->assertStringNotContainsString('bp-next--solo', $html);
        $this->assertStringContainsString('data-hero-next-flyer', $html);
        $this->assertStringContainsString($workshop->refresh()->flyerUrl(), $html);
        $this->assertStringContainsString('Flyer - Atelier illustré', $html, 'un alt qui nomme l\'atelier');
        $this->assertStringContainsString('jeudi 22 octobre 2026', $html, 'la date en toutes lettres remplace le quantieme en grand');
        $this->assertSame(1, substr_count($html, 'data-hero-next-link'), 'toujours un seul lien pour toute la carte');
    }

    public function test_the_compact_list_never_shows_a_flyer_even_when_the_workshops_carry_one(): void
    {
        Storage::fake('public');

        $first = $this->publishedWorkshop($this->a, $this->adminA, ['title' => 'Premier illustré', 'slug' => 'premier'], ['2026-10-22 18:30']);
        $second = $this->publishedWorkshop($this->a, $this->adminA, ['title' => 'Second illustré', 'slug' => 'second'], ['2026-10-28 10:00']);
        app(WorkshopService::class)->attachFlyer($first, UploadedFile::fake()->image('a.jpg', 1200, 630), $this->adminA);
        app(WorkshopService::class)->attachFlyer($second, UploadedFile::fake()->image('b.jpg', 1200, 630), $this->adminA);

        $html = $this->homeHtml($this->a);

        $this->assertStringContainsString('bp-next--list', $html);
        $this->assertStringNotContainsString('bp-next--media', $html);
        $this->assertStringNotContainsString('data-hero-next-flyer', $html);
        $this->assertStringNotContainsString($first->refresh()->flyer_path, $html, 'aucun chemin de visuel dans la liste compacte');
    }

    public function test_several_upcoming_sessions_render_the_compact_list_bounded_and_in_chronological_order(): void
    {
        $this->publishedWorkshop($this->a, $this->adminA, ['title' => 'Atelier du 28', 'slug' => 'le-28', 'duration_minutes' => 60], ['2026-10-28 10:00']);
        $this->publishedWorkshop($this->a, $this->adminA, ['title' => 'Atelier du 22', 'slug' => 'le-22', 'duration_minutes' => 45], ['2026-10-22 18:30']);
        $this->publishedWorkshop($this->a, $this->adminA, ['title' => 'Atelier du 30', 'slug' => 'le-30'], ['2026-10-30 09:00']);

        $html = $this->homeHtml($this->a);

        $this->assertStringContainsString('bp-next--list', $html);
        $this->assertStringNotContainsString('bp-next--solo', $html);
        $this->assertStringContainsString('Événements à venir', $html);
        $this->assertSame(PublicWorkshopListing::HERO_MAX, substr_count($html, 'data-hero-next-link'), 'le HERO en montre au plus deux');

        $this->assertStringContainsString('Atelier du 22', $html);
        $this->assertStringContainsString('Atelier du 28', $html);
        $this->assertStringNotContainsString('Atelier du 30', $html, 'le troisieme attend un index public, qui n\'existe pas encore');
        $this->assertLessThan(
            strpos($html, 'Atelier du 28'),
            strpos($html, 'Atelier du 22'),
            'la date la plus proche en premier'
        );

        // Aucun « Voir tous » tant qu'aucune route publique ne liste les ateliers.
        $this->assertSame([], array_values(array_filter(
            array_keys(app('router')->getRoutes()->getRoutesByName()),
            fn (string $name) => $name === 'organization.workshops'
        )), 'aucun index public d\'ateliers : le lien « Voir tous » n\'a pas de destination');
    }

    public function test_two_dates_of_the_same_workshop_are_two_events_and_each_one_opens_its_workshop(): void
    {
        $workshop = $this->publishedWorkshop($this->a, $this->adminA, ['title' => 'Dogfood 1450', 'slug' => 'dogfood-1450'], ['2026-10-22 18:30', '2026-10-29 18:30']);

        $html = $this->homeHtml($this->a);

        $this->assertStringContainsString('bp-next--list', $html, 'le HERO compte des DATES, pas des ateliers');
        $this->assertSame(2, substr_count($html, 'data-hero-next-workshop="'.$workshop->id.'"'));
        $this->assertStringContainsString('2026-10-22T18:30:00+02:00', $html);
        $this->assertStringContainsString('2026-10-29T18:30:00+01:00', $html, 'chaque date porte son propre decalage');
    }

    public function test_a_retired_or_draft_workshop_a_draft_or_cancelled_session_and_another_organization_never_reach_the_hero(): void
    {
        // Le seul atelier legitime de A.
        $this->publishedWorkshop($this->a, $this->adminA, ['title' => 'Atelier légitime', 'slug' => 'legitime'], ['2026-10-22 18:30']);

        // Un atelier RETIRE dont la session reste publiee et a venir : retire,
        // sa page publique rend 404 — le HERO ne doit pas y renvoyer.
        $retired = $this->publishedWorkshop($this->a, $this->adminA, ['title' => 'Atelier retiré', 'slug' => 'retire'], ['2026-09-30 09:00']);
        app(WorkshopService::class)->retire($retired, $this->adminA);

        // Un atelier NON PUBLIE dont la session est publiee et a venir.
        $draftWorkshop = app(WorkshopService::class)->create($this->a, ['title' => 'Brouillon secret', 'slug' => 'brouillon', 'format' => 'online', 'locale' => 'fr'], $this->adminA);
        $session = app(WorkshopSessionService::class)->create($draftWorkshop, ['starts_at' => '2026-10-01 09:00', 'timezone' => 'Europe/Paris'], $this->adminA);
        app(WorkshopSessionService::class)->publish($session, $this->adminA);

        // Un atelier PUBLIE dont la session la plus proche est restee BROUILLON, et un autre dont elle est ANNULEE.
        $withDraftSession = $this->publishedWorkshop($this->a, $this->adminA, ['title' => 'Session brouillon', 'slug' => 'session-brouillon']);
        app(WorkshopSessionService::class)->create($withDraftSession, ['starts_at' => '2026-10-02 09:00', 'timezone' => 'Europe/Paris'], $this->adminA);
        $withCancelled = $this->publishedWorkshop($this->a, $this->adminA, ['title' => 'Session annulée', 'slug' => 'session-annulee'], ['2026-10-03 09:00']);
        app(WorkshopSessionService::class)->cancel($withCancelled->sessions()->sole(), $this->adminA);

        // Et un atelier publie a venir chez le VOISIN.
        $this->publishedWorkshop($this->b, $this->adminB, ['title' => 'Atelier de B', 'slug' => 'chez-b'], ['2026-10-05 09:00']);

        $html = $this->homeHtml($this->a);

        $this->assertStringContainsString('Atelier légitime', $html);
        $this->assertStringContainsString('bp-next--solo', $html, 'un seul evenement survit a la selection');
        foreach (['Atelier retiré', 'Brouillon secret', 'Session brouillon', 'Session annulée', 'Atelier de B'] as $never) {
            $this->assertStringNotContainsString($never, $html, "jamais : {$never}");
        }

        // Et l'accueil de B ne montre que B.
        $htmlB = $this->homeHtml($this->b);
        $this->assertStringContainsString('Atelier de B', $htmlB);
        $this->assertStringNotContainsString('Atelier légitime', $htmlB, 'aucune fuite cross-tenant dans l\'autre sens');
    }

    public function test_the_other_home_templates_keep_the_open_workshops_block_and_a_private_organization_exposes_nothing(): void
    {
        $this->publishedWorkshop($this->a, $this->adminA, ['title' => 'Atelier partagé', 'slug' => 'partage'], ['2026-10-22 18:30']);

        // Le HERO remplace le bloc SUR CE GABARIT SEULEMENT.
        foreach (['artscilab_hero', null] as $template) {
            $this->a->forceFill(['homepage_template' => $template])->save();
            $html = $this->homeHtml($this->a);
            $this->assertStringContainsString('data-org-workshops', $html, "gabarit {$template} : le bloc « Ateliers ouverts » reste");
            $this->assertStringNotContainsString('data-hero-next', $html, "gabarit {$template} : aucun composant de HERO");
        }

        // Organization non publique : ni bloc, ni HERO d'ateliers, meme pour un membre.
        $this->a->forceFill(['homepage_template' => 'bouclepro_hero_v2', 'is_public' => false])->save();
        $private = $this->actingAs($this->adminA)->get(route('organization.home', ['organization' => $this->a->slug]));
        $this->assertContains($private->getStatusCode(), [200, 302]);
        $this->assertStringNotContainsString('data-hero-next', (string) $private->getContent());
        $this->assertStringNotContainsString('data-org-workshops', (string) $private->getContent());
    }

    public function test_hero_sessions_reads_the_published_selection_without_a_single_extra_query(): void
    {
        $this->publishedWorkshop($this->a, $this->adminA, ['title' => 'Un', 'slug' => 'un-atelier'], ['2026-10-22 18:30']);
        $this->publishedWorkshop($this->a, $this->adminA, ['title' => 'Deux', 'slug' => 'deux-ateliers'], ['2026-10-28 10:00']);

        $workshops = app(PublicWorkshopListing::class)->upcoming($this->a);

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });
        $sessions = PublicWorkshopListing::heroSessions($workshops);
        $this->assertSame(0, $queries, 'le HERO ne coute aucune requete de plus que la selection deja lue');

        $this->assertCount(2, $sessions);
        $this->assertInstanceOf(WorkshopSession::class, $sessions->first());
        $this->assertTrue($sessions->first()->relationLoaded('workshop'));
        $this->assertSame('Un', $sessions->first()->workshop->title);
        $this->assertTrue($sessions->first()->starts_at->lessThan($sessions->last()->starts_at));
    }
}
