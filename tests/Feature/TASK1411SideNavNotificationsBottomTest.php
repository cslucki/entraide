<?php

namespace Tests\Feature;

use App\Models\MemberNotification;
use App\Models\Organization;
use App\Models\User;
use App\Support\Notifications\NotificationCatalogue;
use App\Support\Notifications\NotificationEmitter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-1411 — Notifications vit dans la zone BASSE du rail gauche.
 *
 * Avant : l'entree etait un item de `$items`, rendue dans le `<nav>` metier au
 * milieu des entrees produit (Flux, Boucles, Agenda, Echanges…). C'est un
 * signal PERSONNEL, pas une destination de navigation : sa place est en bas,
 * avec les reglages et l'avatar.
 *
 * Ce que cette TASK doit PRESERVER, et que ce fichier mesure sur le HTML
 * servi :
 * - le badge avec sa valeur BRUTE (`data-nav-badge-notifications`) et son
 *   texte plafonne a « 9+ » — contrat de TASK-1373 ;
 * - l'etat actif sur les deux noms de route ;
 * - route + permissions (l'entree est sous `@auth`) ;
 * - l'accessibilite (`aria-label`, `aria-current`) ;
 * - aucune duplication : l'item a ete RETIRE du haut, pas copie.
 *
 * Le mobile n'est pas touche : `mobile-topbar` a sa propre entree.
 *
 * Les gardes de POSITION se mesurent sur le HTML du rail (`<aside>…</aside>`)
 * et jamais sur la page entiere : la page contient d'autres `<nav>` (topbar
 * mobile), et un `strpos` global ne prouverait rien.
 */
class TASK1411SideNavNotificationsBottomTest extends TestCase
{
    use RefreshDatabase;

    private const OBJET = 'loop_invitation';

    private Organization $orgDefaut;

    private Organization $orgMembre;

    private User $alice;

    private NotificationEmitter $emetteur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgDefaut = Organization::factory()->create(['name' => 'T1411 defaut', 'is_active' => true]);
        $this->orgMembre = Organization::factory()->create(['name' => 'T1411 membre', 'is_active' => true]);
        $this->orgDefaut->update(['is_default' => true]);

        $this->alice = User::factory()->create(['organization_id' => $this->orgMembre->id]);
        $this->emetteur = new NotificationEmitter;
    }

    protected function tearDown(): void
    {
        Organization::where('is_default', true)->update(['is_default' => false]);

        parent::tearDown();
    }

    // ── Position ────────────────────────────────────────────────────────────

    public function test_notifications_lives_in_the_bottom_zone_and_not_in_the_main_nav(): void
    {
        $rail = $this->rail($this->pageAs($this->alice));

        [$navDebut, $navFin] = $this->mainNavBounds($rail);
        $entree = strpos($rail, 'data-side-nav-notifications');

        $this->assertNotFalse($entree, 'L\'entree Notifications est absente du rail.');
        $this->assertGreaterThan($navFin, $entree, 'Notifications est encore DANS le <nav> metier, ou avant lui.');

        // Et elle precede bien la zone des reglages : c'est la premiere chose
        // de la zone basse, pas un appendice apres l'avatar.
        $theme = strpos($rail, '$store.visualTheme.next()');
        $this->assertNotFalse($theme);
        $this->assertLessThan($theme, $entree, 'Notifications devrait preceder le bouton de theme dans la zone basse.');
    }

    public function test_the_entry_is_rendered_exactly_once(): void
    {
        $rail = $this->rail($this->pageAs($this->alice));

        $this->assertSame(1, substr_count($rail, 'data-side-nav-notifications'));

        // Aucune duplication : l'href REELLEMENT rendu (surface courte ou
        // prefixee par l'Organization, selon le contexte — on ne presuppose
        // pas laquelle) n'apparait qu'une seule fois dans tout le rail.
        preg_match('/href="([^"]+)"/', $this->entree($rail), $m);
        $this->assertArrayHasKey(1, $m, 'L\'entree n\'a pas d\'href.');
        $this->assertStringContainsString('notifications', $m[1]);
        $this->assertSame(1, substr_count($rail, 'href="'.$m[1].'"'));
    }

    // ── Contrat du badge (TASK-1373), inchange ──────────────────────────────

    public function test_the_badge_keeps_its_raw_value_and_its_visible_cap(): void
    {
        foreach (range(1, 3) as $i) {
            $this->emettre();
        }

        $rail = $this->rail($this->pageAs($this->alice->fresh()));
        $this->assertStringContainsString('data-nav-badge-notifications="3"', $rail);

        foreach (range(1, 9) as $i) {
            $this->emettre();
        }

        $rail = $this->rail($this->pageAs($this->alice->fresh()));
        $this->assertStringContainsString('data-nav-badge-notifications="12"', $rail);
        $this->assertStringContainsString('9+', $this->entree($rail), 'Le texte visible du badge n\'est plus plafonne a 9+.');
    }

    public function test_the_badge_disappears_at_zero(): void
    {
        $rail = $this->rail($this->pageAs($this->alice));

        $this->assertStringNotContainsString('data-nav-badge-notifications', $rail);
    }

    // ── Etat actif et accessibilite ─────────────────────────────────────────

    public function test_the_entry_is_active_on_the_notifications_page_only(): void
    {
        $surNotifications = $this->entree($this->rail($this->pageAs($this->alice)));
        $this->assertStringContainsString('aria-current="page"', $surNotifications);

        $ailleurs = $this->entree($this->rail($this->actingAs($this->alice)->get(route('dashboard'))->assertOk()->getContent()));
        $this->assertStringNotContainsString('aria-current="page"', $ailleurs);
    }

    public function test_the_entry_is_labelled_for_assistive_tech(): void
    {
        $entree = $this->entree($this->rail($this->pageAs($this->alice)));

        $this->assertStringContainsString('aria-label="'.e(__('navigation.notifications')).'"', $entree);
        $this->assertStringContainsString('title="'.e(__('navigation.notifications_hint')).'"', $entree);
    }

    // ── Permissions ─────────────────────────────────────────────────────────

    /**
     * TASK-1479 (P0 privacy) — un invite n'obtient plus la page du tout.
     *
     * Ce test mesurait l'absence de l'entree dans un rail rendu a un invite sur
     * `/membres`. Cette page rendait alors 200 sans aucun cookie ; elle rend
     * desormais une redirection.
     *
     * La garantie est donc portee plus tot, et plus fort : il n'y a plus de rail
     * a inspecter parce qu'il n'y a plus de page. Le test le dit ainsi.
     *
     * **Le temoin d'instrument de ce fichier reste indispensable et n'a pas
     * bouge** : `test_the_probe_really_renders_the_rail_and_its_two_zones`
     * prouve qu'un MEMBRE obtient bien un rail avec ses deux zones. Sans lui,
     * cette garde negative serait satisfaite par une page vide — c'est
     * exactement le piege que ce fichier documente depuis TASK-1411, et la
     * raison pour laquelle on ne se contente pas ici d'un `assertDontSee` sur
     * une reponse de redirection.
     */
    public function test_a_guest_never_gets_the_entry(): void
    {
        app()->instance('current_organization', $this->orgMembre);

        $response = $this->get('/membres');

        $this->assertNotSame(200, $response->getStatusCode(), 'un invite n\'atteint plus l\'annuaire');
        $this->assertStringNotContainsString('data-side-nav-notifications', (string) $response->getContent());
        $this->assertStringNotContainsString('data-nav-badge-notifications', (string) $response->getContent());
    }

    // ── Temoin d'instrument ─────────────────────────────────────────────────

    /**
     * Sans lui, une page sans rail (ou une 302) ferait passer toutes les gardes
     * negatives : pas de rail, pas de nav, pas d'entree, pas de badge.
     */
    public function test_the_probe_really_renders_the_rail_and_its_two_zones(): void
    {
        $rail = $this->rail($this->pageAs($this->alice));

        [$navDebut, $navFin] = $this->mainNavBounds($rail);
        $this->assertGreaterThan($navDebut, $navFin);
        $this->assertStringContainsString('$store.visualTheme.next()', $rail);
        $this->assertStringContainsString('data-side-nav-notifications', $rail);
    }

    // ── Fixtures et mesures ─────────────────────────────────────────────────

    private function pageAs(User $user): string
    {
        return $this->actingAs($user)->get(route('notifications.index'))->assertOk()->getContent();
    }

    /** Le rail seul : `<aside … </aside>`. */
    private function rail(string $html): string
    {
        $debut = strpos($html, '<aside');
        $fin = strpos($html, '</aside>', $debut === false ? 0 : $debut);

        $this->assertNotFalse($debut, 'Aucun <aside> : le rail n\'est pas rendu.');
        $this->assertNotFalse($fin);

        return substr($html, $debut, $fin - $debut);
    }

    /** Les bornes du `<nav>` metier dans le rail, identifie par son aria-label. */
    private function mainNavBounds(string $rail): array
    {
        $debut = strpos($rail, 'aria-label="'.e(__('navigation.main_navigation')).'"');
        $this->assertNotFalse($debut, 'Le <nav> metier est introuvable dans le rail.');

        $fin = strpos($rail, '</nav>', $debut);
        $this->assertNotFalse($fin);

        return [$debut, $fin];
    }

    /** L'ancre Notifications seule : du marqueur a sa fermeture. */
    private function entree(string $rail): string
    {
        $marqueur = strpos($rail, 'data-side-nav-notifications');
        $this->assertNotFalse($marqueur, 'Entree Notifications absente.');

        $debut = strrpos(substr($rail, 0, $marqueur), '<a ');
        $fin = strpos($rail, '</a>', $marqueur);

        return substr($rail, $debut, $fin - $debut);
    }

    private function emettre(): MemberNotification
    {
        return $this->emetteur->emit(
            notificationKey: NotificationCatalogue::LOOP_INVITATION,
            organization: $this->orgMembre,
            recipient: $this->alice,
            eventId: (string) Str::uuid(),
            objectType: self::OBJET,
            objectId: (string) Str::uuid(),
        );
    }
}
