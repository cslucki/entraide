<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Support\Notifications\NotificationCatalogue;
use App\Support\Notifications\NotificationEmitter;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-1625 — la navigation mobile.
 *
 * CE QUI SE MESURE ICI, ET CE QUI NE S'Y MESURE PAS.
 *
 * Le rendu reel — largeur des onglets a 390 px, defilement effectif, menu non
 * tronque — ne se mesure qu'avec un VRAI viewport, et c'est le role du spec
 * Playwright `tests/e2e/task-1625-mobile-nav.spec.js`. Le dépôt a paye cette
 * lecon : trois TASKs ont annonce « responsive verifie » sur un
 * redimensionnement de fenetre qui laissait le viewport a 1920.
 *
 * Ce banc-ci mesure ce qu'un rendu serveur PEUT prouver, et rien de plus :
 * quelles destinations sont ATTEIGNABLES, quelle route porte chaque lien, quel
 * compteur s'affiche, et les trois contrats de structure dont la disparition
 * ramenerait un defaut deja constate. Aucune assertion decorative : chaque
 * garde ci-dessous rougit sur un defaut que la recette a reellement vu.
 */
class TASK1625MobileNavigationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $membre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['name' => 'T1625', 'is_active' => true]);
        $this->organization->update(['is_default' => true]);

        $this->membre = User::factory()->create(['organization_id' => $this->organization->id]);
    }

    // ── 1. TEMOIN D'INSTRUMENT ──────────────────────────────────────────────
    //
    // Sans lui, une 302 ou une page sans barre ferait passer toutes les gardes
    // negatives : pas de barre, pas d'onglet, pas de defaut.

    public function test_la_barre_mobile_est_bien_rendue(): void
    {
        $barre = $this->barre($this->page());

        $this->assertStringContainsString('data-bp-mobile-nav-track', $barre);
        $this->assertStringContainsString('data-bp-nav-item="loops"', $barre);
    }

    // ── 2. LA BARRE DEFILE AU LIEU DE COMPRIMER ─────────────────────────────

    public function test_la_piste_defile_horizontalement_et_ne_replie_jamais(): void
    {
        $barre = $this->barre($this->page());

        // Le couple qui fait la bande : elle deborde au lieu de se replier.
        $this->assertStringContainsString('overflow-x-auto', $barre);

        // `flex-wrap` ramenerait une seconde rangee sous la premiere, qui
        // passerait sous le bord de l'ecran : la barre est `h-16`.
        $this->assertStringNotContainsString('flex-wrap', $barre);

        // LE defaut d'origine : `flex-1` forcait les onglets a se partager la
        // largeur, donc a se comprimer, donc a plafonner leur nombre. C'est
        // exactement ce que cette TASK retire.
        $this->assertStringNotContainsString('flex-1', $barre);
    }

    public function test_la_hauteur_de_la_barre_ne_bouge_pas(): void
    {
        // `h-16` n'est pas un choix de style, c'est un CONTRAT : trois choses
        // s'y alignent et se decaleraient ensemble — la reserve de defilement
        // `.mobile-safe-bottom-auth` (4rem), le FAB « + » (`bottom-20`) et le
        // FAB IA (`bottom-36`). Un test existant epingle d'ailleurs le FAB par
        // `button[class*="bottom-20"]` (TASK1403).
        $this->assertStringContainsString('h-16', $this->barre($this->page()));
    }

    // ── 3. LES DESTINATIONS ATTEIGNABLES ────────────────────────────────────

    public function test_les_destinations_de_premier_niveau_sont_enfin_atteignables(): void
    {
        $barre = $this->barre($this->page());

        // Avant TASK-1625, Agenda et Blog n'etaient joignables par AUCUN
        // chemin sur telephone : ni barre, ni topbar, ni menu avatar.
        foreach (['loops', 'exchanges', 'messages', 'members', 'agenda', 'blog'] as $cle) {
            $this->assertStringContainsString('data-bp-nav-item="'.$cle.'"', $barre, "Destination absente : {$cle}");
        }
    }

    public function test_chaque_onglet_pointe_vers_une_route_reelle_et_non_vers_un_diese(): void
    {
        $barre = $this->barre($this->page());

        preg_match_all('/<a href="([^"]*)"[^>]*data-bp-nav-item="([^"]+)"/', $barre, $liens, PREG_SET_ORDER);

        $this->assertNotEmpty($liens, 'Aucun onglet trouve : la mesure serait vide.');

        foreach ($liens as [, $url, $cle]) {
            $this->assertNotSame('#', $url, "L'onglet {$cle} ne mene nulle part.");
            $this->assertStringStartsWith('http', $url, "L'onglet {$cle} n'a pas d'URL absolue.");
        }
    }

    public function test_dossiers_resout_bien_la_route_scopee_au_lieu_d_un_lien_mort(): void
    {
        // MESURE, contre une supposition : je croyais Dossiers absent hors
        // d'une URL scopee. Il est rendu — parce que le slug retombe sur
        // l'Organization du membre, EXACTEMENT comme le rail desktop le fait
        // (meme ligne, `app-side-nav.blade.php:8`). Les deux surfaces sont
        // donc coherentes, et ce qui compte se verifie ici : le repli produit
        // une vraie route, pas le `'#'` que la garde de visibilite sert a
        // eviter.
        $onglet = $this->onglet($this->barre($this->page()), 'dossiers');

        $this->assertStringContainsString(
            route('organization.dossiers.index', ['organization' => $this->organization->slug]),
            $onglet,
        );
    }

    // ── 4. L'ICONE DE « BOUCLES » ───────────────────────────────────────────

    public function test_boucles_ne_montre_plus_une_feuille_de_papier(): void
    {
        $onglet = $this->onglet($this->barre($this->page()), 'loops');

        // L'ancien trace : document-text. Il ne disait ni groupe, ni cercle,
        // ni collaboration.
        $this->assertStringNotContainsString('M9 12h6m-6 4h6m2 5H7a2 2', $onglet);

        // Le nouveau trace EST le logo : huit cercles en anneau. On epingle
        // leur NOMBRE, pas leurs coordonnees — un ajustement de rayon reste
        // libre, passer de huit a autre chose ne l'est pas.
        $this->assertSame(8, substr_count($this->tracee($onglet), 'a2.55 2.55 0 1 0') / 2);

        // Le trait est plus fin POUR CET ONGLET : a 1.8 les huit cercles se
        // rejoignent et la rosette redevient un disque.
        $this->assertStringContainsString('stroke-width="1.1"', $onglet);
    }

    public function test_l_icone_de_boucles_ne_se_confond_avec_aucune_voisine(): void
    {
        $barre = $this->barre($this->page());
        $boucles = $this->onglet($barre, 'loops');

        // Une icone qui vaut pour deux destinations n'aide personne. Le rail
        // desktop montre justement une bulle ronde pour Boucles, indiscernable
        // de Messagerie a 24 px — c'est ce piege qu'on evite ici.
        foreach (['messages', 'members'] as $voisin) {
            $traceVoisin = $this->tracee($this->onglet($barre, $voisin));
            $this->assertNotSame($traceVoisin, $this->tracee($boucles), "Boucles partage son trace avec {$voisin}.");
        }
    }

    // ── 5. NOTIFICATIONS DANS LE HEADER ─────────────────────────────────────

    public function test_les_notifications_sont_a_un_seul_tap_dans_le_header(): void
    {
        $page = $this->page();

        $this->assertStringContainsString('data-mobile-topbar-notifications', $page);
        // La route EXISTANTE, pas une nouvelle.
        $this->assertStringContainsString(route('notifications.index'), $page);
    }

    public function test_le_badge_compte_les_non_lues_et_disparait_a_zero(): void
    {
        $this->assertStringNotContainsString(
            'data-mobile-topbar-notifications-unread',
            $this->page(),
            'Un badge a zero est un point rouge qui ne veut rien dire.',
        );

        $this->creerNonLues(3);
        $this->assertStringContainsString('data-mobile-topbar-notifications-unread="3"', $this->page());
    }

    public function test_le_badge_plafonne_a_neuf_plus(): void
    {
        $this->creerNonLues(12);

        $page = $this->page();

        // La valeur BRUTE reste lisible par une machine...
        $this->assertStringContainsString('data-mobile-topbar-notifications-unread="12"', $page);
        // ...mais l'humain lit « 9+ » : « 12 » deborde d'une pastille de 16 px.
        $this->assertStringContainsString('9+', $this->entreeNotifications($page));
    }

    // ── 6. LE MENU AVATAR PASSE DEVANT ──────────────────────────────────────
    //
    // CAUSE RACINE : le header est `fixed` AVEC un `z-index`, il ouvre donc un
    // contexte d'empilement et plafonne le `z-50` du panneau. Les FAB (z-50)
    // et la barre basse passaient devant. Monter le z-index du panneau n'y
    // pouvait rien : un enfant ne sort pas du contexte de son parent.

    public function test_le_header_monte_d_une_couche_pendant_que_son_menu_est_ouvert(): void
    {
        $header = $this->header($this->page());

        // Il ECOUTE l'etat du menu...
        $this->assertStringContainsString('dropdown-open-changed', $header);
        // ...et sa couche en DEPEND, au lieu d'etre figee a z-40.
        $this->assertStringContainsString("menuOuvert ? 'z-[60]' : 'z-40'", $header);
    }

    public function test_le_header_ne_monte_pas_en_permanence(): void
    {
        // Un `z-[60]` fixe passerait aussi devant les vraies modales
        // `fixed inset-0 z-50`. La couche haute doit rester CONDITIONNELLE.
        $header = $this->header($this->page());

        $this->assertStringNotContainsString('inset-x-0 z-[60]', $header);
        $this->assertStringContainsString('inset-x-0 z-40', $header);
    }

    public function test_le_menu_reste_atteignable_sur_un_petit_ecran(): void
    {
        // Le menu compte ~16 entrees. Sans plafond de hauteur ni defilement
        // interne, et sous un ancetre `fixed` que le defilement de page ne
        // rattrape pas, les dernieres entrees — dont Deconnexion — etaient
        // physiquement hors de l'ecran.
        $page = $this->page();

        $this->assertStringContainsString('max-h-[calc(100dvh-5rem)]', $page);
        $this->assertStringContainsString('overflow-y-auto', $page);
    }

    // ── Fixtures et mesures ─────────────────────────────────────────────────

    private function page(): string
    {
        return $this->actingAs($this->membre)->get(route('dashboard'))->assertOk()->getContent();
    }

    /** La barre basse seule. */
    private function barre(string $html): string
    {
        $debut = strpos($html, '<nav data-bp-mobile-nav');
        $this->assertNotFalse($debut, 'La barre mobile n\'est pas rendue.');

        $fin = strpos($html, '</nav>', $debut);
        $this->assertNotFalse($fin);

        return substr($html, $debut, $fin - $debut);
    }

    /** Le header mobile seul. */
    private function header(string $html): string
    {
        $debut = strpos($html, '<header x-data="{ menuOuvert: false }"');
        $this->assertNotFalse($debut, 'Le header mobile n\'est pas rendu.');

        $fin = strpos($html, '</header>', $debut);
        $this->assertNotFalse($fin);

        return substr($html, $debut, $fin - $debut);
    }

    /** Un onglet de la barre, du marqueur a sa fermeture. */
    private function onglet(string $barre, string $cle): string
    {
        $marqueur = strpos($barre, 'data-bp-nav-item="'.$cle.'"');
        $this->assertNotFalse($marqueur, "Onglet {$cle} absent.");

        $debut = strrpos(substr($barre, 0, $marqueur), '<a ');
        $fin = strpos($barre, '</a>', $marqueur);

        return substr($barre, $debut, $fin - $debut);
    }

    /** Le seul attribut `d` d'un onglet — son trace. */
    private function tracee(string $onglet): string
    {
        preg_match('/<path d="([^"]+)"/', $onglet, $m);
        $this->assertNotEmpty($m, 'Onglet sans trace.');

        return $m[1];
    }

    private function entreeNotifications(string $html): string
    {
        $marqueur = strpos($html, 'data-mobile-topbar-notifications');
        $this->assertNotFalse($marqueur, 'Entree Notifications absente du header.');

        $debut = strrpos(substr($html, 0, $marqueur), '<a ');
        $fin = strpos($html, '</a>', $marqueur);

        return substr($html, $debut, $fin - $debut);
    }

    /**
     * Par l'EMETTEUR du depot, jamais par un `create()` direct : les
     * invariants de notification imposent une cle declaree au catalogue, et
     * une ligne forgee a la main les contourne — elle mesurerait alors un
     * compteur que la production ne produit jamais.
     */
    private function creerNonLues(int $combien): void
    {
        $emetteur = new NotificationEmitter;

        for ($i = 0; $i < $combien; $i++) {
            $emetteur->emit(
                notificationKey: NotificationCatalogue::LOOP_INVITATION,
                organization: $this->organization,
                recipient: $this->membre,
                eventId: (string) Str::uuid(),
                objectType: 'loop_invitation',
                objectId: (string) Str::uuid(),
            );
        }
    }
}
