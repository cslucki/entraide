<?php

namespace Tests\Feature;

use App\Http\Controllers\NotificationCenterController;
use App\Models\MemberNotification;
use App\Models\Organization;
use App\Models\User;
use App\Support\Notifications\NotificationCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * TASK-1780 — l'onglet « Toutes » doit mener a « Toutes ».
 *
 * ## Le defaut, et pourquoi aucun test ne l'a vu
 *
 * Le constructeur d'URL de la vue omettait le parametre pour « Toutes » :
 *
 * ```php
 * $params = $f === FILTRE_TOUTES ? [] : ['filtre' => $f];
 * ```
 *
 * C'etait JUSTE tant que `NotificationCenterController::filtre()` rendait
 * `FILTRE_TOUTES` en l'absence de parametre. **T1775 a inverse ce defaut vers
 * `FILTRE_NON_LUES` sans toucher a ce constructeur.** Des lors, le lien
 * « Toutes » pointait vers une URL nue, que le controleur interpretait comme
 * « Non lues » : **cliquer sur « Toutes » ramenait sur « Non lues », et
 * l'historique devenait inatteignable depuis l'interface.**
 *
 * Pour un membre dont tout est lu — c'etait le cas de l'administrateur
 * d'OFSH, 6 notifications toutes lues — le Centre paraissait donc
 * definitivement vide, alors que les six lignes etaient en base.
 *
 * ## Ce que la suite existante verifiait, et ce qu'elle ne verifiait pas
 *
 * Les suites T1373 et T1382 couvrent largement le comportement du filtre
 * **quand le parametre est fourni** (`?filtre=toutes` montre lu et non lu,
 * un filtre inconnu retombe sur non-lues, l'etat vide se distingue…). Aucune
 * ne verifiait **la CIBLE du lien** que la vue fabrique. Verifier qu'un onglet
 * est RENDU ne dit rien de l'endroit ou il mene.
 *
 * C'est la forme du defaut qu'il faut retenir : **un lien qui s'appuie sur le
 * DEFAUT d'un controleur se casse en silence le jour ou ce defaut change.**
 * La correction transmet donc toujours la valeur, pour les deux onglets — ce
 * qui la rend independante du defaut suivant, quel qu'il soit.
 */
class TASK1780NotificationFilterLinkTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $membre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['is_active' => true]);
        $this->membre = User::factory()->create(['organization_id' => $this->org->id]);
    }

    // =====================================================================
    // 1. LE DEFAUT, reproduit exactement
    // =====================================================================

    /**
     * **Le test qui rougit sans le correctif.**
     *
     * On n'asserte pas « le mot Toutes apparait » — il apparaissait deja. On
     * asserte que **son lien porte `filtre=toutes`**, ce qui est precisement
     * ce qui manquait.
     */
    public function test_le_lien_de_l_onglet_TOUTES_transmet_explicitement_le_filtre(): void
    {
        $this->creerNotification(lue: true);

        $html = $this->actingAs($this->membre)->get('/notifications')->assertOk()->getContent();

        $this->assertStringContainsString(
            'filtre='.NotificationCenterController::FILTRE_TOUTES,
            $html,
            'Sans ce parametre, le lien « Toutes » retombe sur le defaut du controleur, qui est « Non lues » depuis T1775.'
        );
    }

    /**
     * **La preuve de bout en bout : suivre le lien doit CHANGER d'onglet.**
     *
     * C'est la mesure qui compte vraiment. Un membre dont tout est lu part
     * d'un ecran vide ; il clique « Toutes » ; il doit voir son historique.
     * Avant le correctif, il revenait sur le meme ecran vide.
     */
    public function test_SUIVRE_le_lien_Toutes_affiche_l_historique_deja_lu(): void
    {
        $notification = $this->creerNotification(lue: true);

        $depart = $this->actingAs($this->membre)->get('/notifications')->assertOk();

        // L'ecran de depart est bien vide, et le dit : « Tout est lu ».
        $depart->assertSee('data-notifications-filter-empty', escape: false);

        $lien = $this->lienDeLOnglet($depart->getContent(), NotificationCenterController::FILTRE_TOUTES);

        $this->actingAs($this->membre)->get($lien)->assertOk()
            ->assertDontSee('data-notifications-filter-empty', escape: false)
            ->assertDontSee('data-notifications-empty', escape: false)
            ->assertSee('data-notification-id="'.$notification->id.'"', escape: false);
    }

    // =====================================================================
    // 2. Le defaut « Non lues » est CONSERVE
    // =====================================================================

    /** Sans parametre, on reste sur « Non lues » — l'arbitrage de T1775 n'est pas touche. */
    public function test_le_defaut_reste_NON_LUES(): void
    {
        $lue = $this->creerNotification(lue: true);
        $nonLue = $this->creerNotification(lue: false);

        $this->actingAs($this->membre)->get('/notifications')->assertOk()
            ->assertSee('data-notification-id="'.$nonLue->id.'"', escape: false)
            ->assertDontSee('data-notification-id="'.$lue->id.'"', escape: false);
    }

    /** Et le lien « Non lues » transmet lui aussi sa valeur, pour la meme raison. */
    public function test_le_lien_de_l_onglet_NON_LUES_transmet_aussi_sa_valeur(): void
    {
        $this->creerNotification(lue: false);

        $html = $this->actingAs($this->membre)->get('/notifications')->assertOk()->getContent();

        $this->assertStringContainsString('filtre='.NotificationCenterController::FILTRE_NON_LUES, $html);
    }

    // =====================================================================
    // 3. L'etat actif du bouton
    // =====================================================================

    /**
     * `aria-current="page"` doit suivre le filtre REELLEMENT applique.
     *
     * Le defaut produisait aussi une incoherence d'accessibilite : on
     * atterrissait sur « Non lues » alors qu'on avait clique « Toutes », donc
     * le marqueur se posait sur l'onglet qu'on n'avait pas demande.
     */
    public function test_aria_current_suit_le_filtre_applique(): void
    {
        $this->creerNotification(lue: true);

        $parDefaut = $this->actingAs($this->membre)->get('/notifications')->assertOk()->getContent();
        $this->assertSame(
            NotificationCenterController::FILTRE_NON_LUES,
            $this->ongletMarqueCourant($parDefaut),
            'Sans parametre, c est « Non lues » qui doit porter aria-current.'
        );

        $toutes = $this->actingAs($this->membre)
            ->get('/notifications?filtre='.NotificationCenterController::FILTRE_TOUTES)
            ->assertOk()->getContent();
        $this->assertSame(
            NotificationCenterController::FILTRE_TOUTES,
            $this->ongletMarqueCourant($toutes),
            'Avec ?filtre=toutes, c est « Toutes » qui doit porter aria-current.'
        );
    }

    // =====================================================================
    // 4. Les DEUX routes
    // =====================================================================

    /**
     * La route org-scopee doit se comporter a l'identique.
     *
     * `$urlFiltre` est construit sur `request()->url()` : il doit donc rendre
     * un lien qui reste sur la route courante, sans retomber sur la forme
     * root-level.
     */
    public function test_la_route_org_scopee_rend_le_meme_lien_sur_SA_propre_url(): void
    {
        $notification = $this->creerNotification(lue: true);
        $url = '/org/'.$this->org->slug.'/notifications';

        $reponse = $this->actingAs($this->membre)->get($url)->assertOk();
        $lien = $this->lienDeLOnglet($reponse->getContent(), NotificationCenterController::FILTRE_TOUTES);

        $this->assertStringContainsString($url, $lien, 'Le lien doit rester sur la route org-scopee.');
        $this->assertStringContainsString('filtre='.NotificationCenterController::FILTRE_TOUTES, $lien);

        $this->actingAs($this->membre)->get($lien)->assertOk()
            ->assertSee('data-notification-id="'.$notification->id.'"', escape: false);
    }

    // =====================================================================
    // 5. La pagination garde le filtre
    // =====================================================================

    /**
     * Le filtre survit a la pagination.
     *
     * `paginate()->withQueryString()` s'en charge, mais c'est precisement le
     * genre de garantie qu'on croit acquise : si le lien de page perdait
     * `filtre=toutes`, la page 2 de l'historique rebasculerait sur « Non lues »
     * et le defaut reapparaitrait un cran plus loin.
     */
    public function test_la_pagination_conserve_le_filtre_toutes(): void
    {
        foreach (range(1, 30) as $rang) {
            $this->creerNotification(lue: true, rang: $rang);
        }

        $html = $this->actingAs($this->membre)
            ->get('/notifications?filtre='.NotificationCenterController::FILTRE_TOUTES)
            ->assertOk()->getContent();

        $this->assertStringContainsString('page=2', $html, '30 notifications pour 25 par page : la page 2 doit exister.');

        preg_match('/href="([^"]*page=2[^"]*)"/', $html, $m);
        $this->assertNotEmpty($m, 'Un lien vers la page 2 doit etre rendu.');
        $this->assertStringContainsString(
            'filtre='.NotificationCenterController::FILTRE_TOUTES,
            html_entity_decode($m[1]),
            'Le lien de pagination doit conserver le filtre, sinon la page 2 rebascule sur « Non lues ».'
        );
    }

    // ── Outillage ───────────────────────────────────────────────────────────

    private function creerNotification(bool $lue, int $rang = 0): MemberNotification
    {
        $notification = MemberNotification::create([
            'organization_id' => $this->org->id,
            'recipient_id' => $this->membre->id,
            'notification_key' => NotificationCatalogue::LOOP_INVITATION,
            'event_id' => (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'task1780:'.$rang.':'.($lue ? 'lue' : 'non')),
            'object_type' => NotificationCatalogue::OBJECT_LOOP_INVITATION,
            'object_id' => (string) Str::uuid(),
        ]);

        if ($lue) {
            $notification->markAsReadFor((string) $this->org->id, (string) $this->membre->id);
        }

        return $notification->refresh();
    }

    /** Le `href` de l'onglet demande, extrait du HTML rendu. */
    private function lienDeLOnglet(string $html, string $filtre): string
    {
        preg_match_all('/href="([^"]*notifications[^"]*)"/', $html, $m);

        foreach ($m[1] as $href) {
            $href = html_entity_decode($href);

            if (str_contains($href, 'filtre='.$filtre)) {
                return $href;
            }
        }

        // Pas de lien portant ce filtre : c'est EXACTEMENT le defaut de T1780.
        // On rend l'URL nue, ce que la vue fabriquait avant le correctif, pour
        // que le test echoue sur l'assertion metier et non sur une exception.
        return '/notifications';
    }

    /** Lequel des deux onglets porte `aria-current="page"`. */
    private function ongletMarqueCourant(string $html): ?string
    {
        foreach ([NotificationCenterController::FILTRE_TOUTES, NotificationCenterController::FILTRE_NON_LUES] as $f) {
            if (preg_match('/href="[^"]*filtre='.preg_quote($f, '/').'[^"]*"[^>]*aria-current="page"/', $html)) {
                return $f;
            }
        }

        return null;
    }
}
