<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveUrlOrganization;
use App\Models\MemberNotification;
use App\Models\Organization;
use App\Models\User;
use App\Support\Notifications\NotificationCatalogue;
use App\Support\Notifications\NotificationEmitter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-1373 — le Centre de notifications et le badge du rail.
 *
 * ## Le montage est la moitie de la preuve
 *
 * DEUX Organizations, et **celle par defaut de la plateforme n'est PAS celle du
 * membre**. Avec une seule Organization, `$user->organization` et
 * `currentOrganization()` coincident : le test resterait vert meme si le code
 * lisait le mauvais tenant. C'est exactement le defaut que le depot a deja paye
 * ailleurs, et le montage est ce qui le rend visible.
 *
 * `is_default` porte, **sous PostgreSQL uniquement**, un index unique partiel :
 * poser deux Organizations par defaut est vert sous SQLite et rouge en CI. D'ou
 * le `tearDown` qui remet tout a `false`.
 */
class TASK1373NotificationCenterTest extends TestCase
{
    use RefreshDatabase;

    private const OBJET = 'loop_invitation';

    private Organization $orgDefaut;

    private Organization $orgMembre;

    private User $alice;

    private User $bob;

    private NotificationEmitter $emetteur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgDefaut = Organization::factory()->create(['name' => 'T1373 Organization par defaut', 'is_active' => true]);
        $this->orgMembre = Organization::factory()->create(['name' => 'T1373 Organization du membre', 'is_active' => true]);
        $this->orgDefaut->update(['is_default' => true]);

        $this->alice = User::factory()->create(['organization_id' => $this->orgMembre->id]);
        $this->bob = User::factory()->create(['organization_id' => $this->orgMembre->id]);

        $this->emetteur = new NotificationEmitter;
    }

    protected function tearDown(): void
    {
        Organization::where('is_default', true)->update(['is_default' => false]);

        parent::tearDown();
    }

    // =====================================================================
    // 1. Le membre est proprietaire de sa boite
    // =====================================================================

    /** La page ne montre que les notifications du membre, pas celles d'un voisin. */
    public function test_the_page_only_shows_the_members_own_notifications(): void
    {
        $sienne = $this->emettre(destinataire: $this->alice);
        $celleDeBob = $this->emettre(destinataire: $this->bob);

        $this->actingAs($this->alice)->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('data-notification-id="'.$sienne->id.'"', false)
            ->assertDontSee('data-notification-id="'.$celleDeBob->id.'"', false);
    }

    /**
     * Et jamais celles d'une AUTRE Organization, meme pour la meme personne.
     *
     * C'est le defaut precis que le docblock de `MemberNotification` interdit :
     * un `where('recipient_id')` nu. L'identite ne suffit pas, la frontiere de
     * tenant doit etre dans la meme requete.
     */
    public function test_the_page_never_crosses_the_organization_boundary(): void
    {
        $ailleurs = User::factory()->create(['organization_id' => $this->orgDefaut->id]);
        $sienne = $this->emettre(destinataire: $this->alice);
        $autreTenant = $this->emettre(organisation: $this->orgDefaut, destinataire: $ailleurs);

        $this->actingAs($this->alice)->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('data-notification-id="'.$sienne->id.'"', false)
            ->assertDontSee('data-notification-id="'.$autreTenant->id.'"', false);
    }

    /**
     * **La page et le badge ignorent une Organization PRECEDENTE.**
     *
     * C'est le seul montage qui mesure vraiment la frontiere de tenant. Un
     * voisin d'un autre tenant porte aussi un autre `recipient_id` : retirer le
     * filtre d'Organization ne changerait alors rien, et le test resterait vert
     * en donnant l'illusion de prouver quelque chose. **Mesure verifiee par
     * sabotage — c'est ce test qui rougit quand `forRecipient()` perd son
     * tenant, et lui seul.**
     *
     * Une appartenance se deplace : la suppression d'une Organization detache
     * ses membres, et un membre peut en rejoindre une autre. Les notifications
     * qu'il a recues ailleurs restent la ou elles ont ete emises.
     */
    public function test_the_page_and_the_badge_ignore_a_previous_organization(): void
    {
        $ancienne = $this->emettre(destinataire: $this->alice);
        $ancienneBis = $this->emettre(destinataire: $this->alice);

        $this->alice->forceFill(['organization_id' => $this->orgDefaut->id])->save();

        $nouvelle = $this->emettre(organisation: $this->orgDefaut, destinataire: $this->alice);

        $reponse = $this->actingAs($this->alice->fresh())->get(route('notifications.index'))->assertOk();

        $reponse->assertSee('data-notification-id="'.$nouvelle->id.'"', false);
        $reponse->assertDontSee('data-notification-id="'.$ancienne->id.'"', false);
        $reponse->assertDontSee('data-notification-id="'.$ancienneBis->id.'"', false);
        $reponse->assertSee('data-nav-badge-notifications="1"', false);
    }

    /**
     * **Garde-fou : `notifications` ne doit PAS rejoindre la liste des routes a
     * Organization par defaut.**
     *
     * `$user->organization ?? currentOrganization()` protege la page aujourd'hui
     * sans que rien ne le prouve : sur la route racine, les deux expressions
     * coincident, parce que `notifications` est absent de cette liste. Le jour
     * ou quelqu'un l'y ajoute « par symetrie », la page basculerait
     * silencieusement sur l'Organization par defaut de la plateforme.
     *
     * Ce test ne mesure donc pas la garde — il mesure la CONDITION qui la rend
     * aujourd'hui invisible, et il rougira au moment exact ou elle deviendra
     * necessaire.
     */
    public function test_notifications_never_resolve_the_platform_default_organization(): void
    {
        $this->assertNotContains(
            'notifications',
            ResolveUrlOrganization::$defaultOrganizationRoutes,
            'Ajouter « notifications » ici ferait basculer la page sur l\'Organization par defaut.'
        );
    }

    /**
     * **Le tenant de la page est celui du MEMBRE, pas le defaut de la plateforme.**
     *
     * Sur la route non prefixee, l'« Organization courante » est celle par
     * defaut. Un code qui lirait `currentOrganization()` seul afficherait donc
     * une boite vide — ou pire, celle d'un tenant etranger.
     */
    public function test_the_page_tenant_is_the_members_not_the_platform_default(): void
    {
        $sienne = $this->emettre(destinataire: $this->alice);

        $this->actingAs($this->alice)->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('data-notification-id="'.$sienne->id.'"', false);
    }

    /**
     * **A la meme seconde, c'est l'identifiant qui departage.**
     *
     * `created_at` est un `timestamp` a la seconde. Trois precedents dans ce
     * depot ont montre que des lignes creees dans le meme test la partagent, et
     * qu'un `latest('created_at')` seul rend alors un ordre dependant du
     * moteur — vert sous SQLite, capricieux ailleurs. Le depart sur `id` rend
     * l'ordre total.
     */
    public function test_notifications_created_within_the_same_second_keep_a_stable_order(): void
    {
        $instant = now()->startOfSecond();

        $lignes = collect(range(1, 3))->map(function () use ($instant) {
            $n = $this->emettre(destinataire: $this->alice);
            $n->forceFill(['created_at' => $instant, 'updated_at' => $instant])->saveQuietly();

            return $n->fresh();
        });

        $attendu = $lignes->sortByDesc('id')->pluck('id')->values()->all();

        $html = $this->actingAs($this->alice)->get(route('notifications.index'))->assertOk()->getContent();

        $rendu = [];
        foreach ($lignes as $ligne) {
            $rendu[strpos($html, 'data-notification-id="'.$ligne->id.'"')] = $ligne->id;
        }
        ksort($rendu);

        $this->assertSame($attendu, array_values($rendu), 'A egalite de seconde, l\'identifiant doit departager.');
    }

    /** Un invite n'a pas de boite : il est renvoye vers la connexion. */
    public function test_a_guest_is_sent_to_the_login_page(): void
    {
        $this->get(route('notifications.index'))->assertRedirect(route('login'));
    }

    // =====================================================================
    // 2. Le badge du rail
    // =====================================================================

    /**
     * Le badge porte le compte du membre.
     *
     * On asserte l'attribut, qui porte la valeur BRUTE. Un `assertSee('3')`
     * passerait pour n'importe quel « 3 » de la page, et le texte visible est
     * plafonne a « 9+ » : l'asserter reviendrait a tester le plafond.
     */
    public function test_the_rail_badge_carries_the_members_unread_count(): void
    {
        foreach (range(1, 3) as $i) {
            $this->emettre(destinataire: $this->alice);
        }

        $this->actingAs($this->alice)->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('data-nav-badge-notifications="3"', false);
    }

    /** Il ne compte pas les notifications d'une autre Organization. */
    public function test_the_badge_ignores_another_organization(): void
    {
        $ailleurs = User::factory()->create(['organization_id' => $this->orgDefaut->id]);

        foreach (range(1, 2) as $i) {
            $this->emettre(destinataire: $this->alice);
        }
        foreach (range(1, 5) as $i) {
            $this->emettre(organisation: $this->orgDefaut, destinataire: $ailleurs);
        }

        $this->actingAs($this->alice)->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('data-nav-badge-notifications="2"', false);
    }

    /** Il ne compte pas les notifications DEJA LUES. */
    public function test_the_badge_ignores_read_notifications(): void
    {
        $lue = $this->emettre(destinataire: $this->alice);
        $lue->markAsReadFor((string) $this->orgMembre->id, (string) $this->alice->id);
        $this->emettre(destinataire: $this->alice);

        $this->actingAs($this->alice)->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('data-nav-badge-notifications="1"', false);
    }

    /** A zero, la pastille disparait — elle n'affiche pas « 0 ». */
    public function test_the_badge_disappears_at_zero(): void
    {
        $this->actingAs($this->alice)->get(route('notifications.index'))
            ->assertOk()
            ->assertDontSee('data-nav-badge-notifications', false);
    }

    /**
     * Au-dela de neuf, le TEXTE plafonne mais la valeur reste exacte.
     *
     * Les deux comptent : le plafond est une decision d'affichage, la valeur
     * brute est ce que la mesure doit pouvoir lire.
     */
    public function test_the_badge_caps_its_text_but_not_its_value(): void
    {
        foreach (range(1, 12) as $i) {
            $this->emettre(destinataire: $this->alice);
        }

        $reponse = $this->actingAs($this->alice)->get(route('notifications.index'))->assertOk();

        $reponse->assertSee('data-nav-badge-notifications="12"', false);
        $reponse->assertSee('9+');
    }

    // =====================================================================
    // 3. Les filtres
    // =====================================================================

    /** « Non lues » ne montre que les non lues. */
    public function test_the_unread_filter_only_shows_unread(): void
    {
        $lue = $this->emettre(destinataire: $this->alice);
        $lue->markAsReadFor((string) $this->orgMembre->id, (string) $this->alice->id);
        $nonLue = $this->emettre(destinataire: $this->alice);

        $this->actingAs($this->alice)->get(route('notifications.index', ['filtre' => 'non-lues']))
            ->assertOk()
            ->assertSee('data-notification-id="'.$nonLue->id.'"', false)
            ->assertDontSee('data-notification-id="'.$lue->id.'"', false);
    }

    /**
     * Un filtre inconnu retombe sur « toutes » — il n'est pas une erreur.
     *
     * La valeur vient du client : elle passe par une liste blanche plutot que
     * d'atteindre la requete.
     */
    public function test_an_unknown_filter_falls_back_to_all(): void
    {
        $lue = $this->emettre(destinataire: $this->alice);
        $lue->markAsReadFor((string) $this->orgMembre->id, (string) $this->alice->id);

        foreach (['<script>alert(1)</script>', 'n_importe_quoi', ''] as $bidon) {
            $reponse = $this->actingAs($this->alice)->get(route('notifications.index', ['filtre' => $bidon]))
                ->assertOk()
                ->assertSee('data-notification-id="'.$lue->id.'"', false);

            // Et l'ecran le DIT : « Toutes » redevient l'onglet courant. Sans la
            // liste blanche, la valeur brute traverserait et aucun onglet ne
            // serait marque — l'utilisateur ne saurait plus ce qu'il regarde.
            // C'est cette assertion qui mesure la garde ; le contenu de la liste,
            // lui, serait identique dans les deux cas.
            $reponse->assertSee('data-notifications-filter="toutes"', false);
            $this->assertStringContainsString(
                'aria-current="page"',
                $this->extraireOngletToutes($reponse->getContent()),
                'Un filtre inconnu doit rendre « Toutes » courant.'
            );
        }
    }

    // =====================================================================
    // 4. Les etats vides — DEUX, distincts
    // =====================================================================

    /** Une boite vide et un filtre vide ne disent pas la meme chose. */
    public function test_the_page_empty_state_and_the_filter_empty_state_are_distinct(): void
    {
        $this->actingAs($this->alice)->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('data-notifications-empty', false)
            ->assertDontSee('data-notifications-filter-empty', false);

        $lue = $this->emettre(destinataire: $this->alice);
        $lue->markAsReadFor((string) $this->orgMembre->id, (string) $this->alice->id);

        $this->actingAs($this->alice)->get(route('notifications.index', ['filtre' => 'non-lues']))
            ->assertOk()
            ->assertSee('data-notifications-filter-empty', false)
            ->assertDontSee('data-notifications-empty', false);
    }

    // =====================================================================
    // 5. Marquer lu
    // =====================================================================

    /** Marquer lu marque bien la sienne. */
    public function test_a_member_can_mark_their_own_notification_as_read(): void
    {
        $sienne = $this->emettre(destinataire: $this->alice);

        $this->actingAs($this->alice)
            ->from(route('notifications.index'))
            ->post(route('notifications.read', ['notification' => $sienne->id]))
            ->assertRedirect();

        $this->assertNotNull($sienne->fresh()->read_at);
    }

    /**
     * **Connaitre l'identifiant d'une notification n'est pas un droit sur elle.**
     *
     * Alice poste sur celle de Bob. C'est un 404 — jamais un 403 : distinguer
     * l'inexistant de l'interdit ferait de cette route un oracle sur les
     * notifications des autres.
     */
    public function test_a_member_cannot_mark_another_members_notification_as_read(): void
    {
        $celleDeBob = $this->emettre(destinataire: $this->bob);

        $this->actingAs($this->alice)
            ->post(route('notifications.read', ['notification' => $celleDeBob->id]))
            ->assertNotFound();

        $this->assertNull($celleDeBob->fresh()->read_at, 'La notification de Bob reste non lue.');
    }

    /** Ni celle d'une autre Organization, meme si elle lui est adressee. */
    public function test_a_member_cannot_mark_a_notification_from_another_organization(): void
    {
        $ailleurs = User::factory()->create(['organization_id' => $this->orgDefaut->id]);
        $autreTenant = $this->emettre(organisation: $this->orgDefaut, destinataire: $ailleurs);

        $this->actingAs($this->alice)
            ->post(route('notifications.read', ['notification' => $autreTenant->id]))
            ->assertNotFound();

        $this->assertNull($autreTenant->fresh()->read_at);
    }

    /**
     * Un identifiant qui n'est pas un UUID rend 404, jamais 500.
     *
     * Le controle de forme vient AVANT la requete : sous PostgreSQL la colonne
     * est un `uuid` natif et une chaine invalide y leve `22P02`, donc une erreur
     * serveur, la ou SQLite se contente de ne rien trouver.
     */
    public function test_a_malformed_identifier_is_a_404_not_a_500(): void
    {
        $this->actingAs($this->alice)
            ->post(route('notifications.read', ['notification' => 'pas-un-uuid']))
            ->assertNotFound();
    }

    /**
     * **Et la table n'est meme pas interrogee.**
     *
     * Le 404 seul ne prouve rien : sous SQLite, une chaine qui n'est pas un
     * UUID ne trouve simplement aucune ligne, et le test passerait pour la
     * mauvaise raison meme sans garde. C'est sous PostgreSQL que la colonne est
     * un `uuid` natif et que la requete leverait `22P02`, donc un 500.
     *
     * On mesure donc ce qui differe reellement : **la requete ne part pas**.
     * Cette assertion rougit sur les DEUX moteurs.
     */
    public function test_a_malformed_identifier_never_reaches_the_table(): void
    {
        DB::enableQueryLog();

        $this->actingAs($this->alice)
            ->post(route('notifications.read', ['notification' => 'pas-un-uuid']))
            ->assertNotFound();

        $requetes = collect(DB::getQueryLog())
            ->filter(fn (array $r) => str_contains($r['query'], 'member_notifications'));

        DB::disableQueryLog();

        $this->assertCount(0, $requetes, 'Une valeur malformee ne doit jamais atteindre la table.');
    }

    /** Marquer lu deux fois ne fait pas glisser la date de premiere lecture. */
    public function test_marking_read_twice_does_not_move_the_date(): void
    {
        $sienne = $this->emettre(destinataire: $this->alice);

        $this->actingAs($this->alice)->post(route('notifications.read', ['notification' => $sienne->id]));
        $premiere = $sienne->fresh()->read_at;

        $this->travel(2)->seconds();
        $this->actingAs($this->alice)->post(route('notifications.read', ['notification' => $sienne->id]));

        $this->assertNotNull($premiere);
        $this->assertEquals($premiere, $sienne->fresh()->read_at, 'La premiere lecture fait foi.');
    }

    // =====================================================================
    // 6. Tout marquer lu
    // =====================================================================

    /** Tout marquer lu ne touche que les siennes, dans cette Organization. */
    public function test_mark_all_read_only_touches_the_members_own_in_this_organization(): void
    {
        $ailleurs = User::factory()->create(['organization_id' => $this->orgDefaut->id]);

        $sienneA = $this->emettre(destinataire: $this->alice);
        $sienneB = $this->emettre(destinataire: $this->alice);
        $celleDeBob = $this->emettre(destinataire: $this->bob);
        $autreTenant = $this->emettre(organisation: $this->orgDefaut, destinataire: $ailleurs);

        $this->actingAs($this->alice)
            ->from(route('notifications.index'))
            ->post(route('notifications.read-all'))
            ->assertRedirect();

        $this->assertNotNull($sienneA->fresh()->read_at);
        $this->assertNotNull($sienneB->fresh()->read_at);
        $this->assertNull($celleDeBob->fresh()->read_at, 'Celle de Bob n\'est pas touchee.');
        $this->assertNull($autreTenant->fresh()->read_at, 'Celle de l\'autre tenant non plus.');
    }

    /** Et le badge tombe a zero ensuite. */
    public function test_the_badge_falls_to_zero_after_mark_all_read(): void
    {
        foreach (range(1, 3) as $i) {
            $this->emettre(destinataire: $this->alice);
        }

        $this->actingAs($this->alice)
            ->from(route('notifications.index'))
            ->post(route('notifications.read-all'));

        $this->actingAs($this->alice)->get(route('notifications.index'))
            ->assertOk()
            ->assertDontSee('data-nav-badge-notifications', false);
    }

    // =====================================================================
    // 7. Les deux langues
    // =====================================================================

    /** L'ecran existe en francais. */
    public function test_the_screen_speaks_french(): void
    {
        $this->alice->forceFill(['preferred_locale' => 'fr'])->saveQuietly();

        $this->actingAs($this->alice)->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Mes notifications')
            ->assertSee('Non lues');
    }

    /**
     * Et en anglais.
     *
     * On asserte la CHAINE, pas `assertOk()` : le repli sur le francais rendrait
     * la page sans erreur, et un test qui ne verifie que le code de statut
     * resterait vert avec un fichier de langue anglais absent.
     */
    public function test_the_screen_speaks_english(): void
    {
        $this->alice->forceFill(['preferred_locale' => 'en'])->saveQuietly();

        $this->actingAs($this->alice)->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('My notifications')
            ->assertSee('Unread');
    }

    /** Les deux fichiers de langue portent exactement les memes cles. */
    public function test_both_languages_carry_the_same_keys(): void
    {
        $fr = require lang_path('fr/notifications.php');
        $en = require lang_path('en/notifications.php');

        $aplatir = function (array $tableau, string $prefixe = '') use (&$aplatir): array {
            $plat = [];
            foreach ($tableau as $cle => $valeur) {
                $chemin = $prefixe === '' ? (string) $cle : $prefixe.'.'.$cle;
                $plat = is_array($valeur)
                    ? $plat + $aplatir($valeur, $chemin)
                    : $plat + [$chemin => $valeur];
            }

            return $plat;
        };

        $platFr = $aplatir($fr);
        $platEn = $aplatir($en);

        foreach ($platFr as $cle => $valeur) {
            $this->assertArrayHasKey($cle, $platEn, "[{$cle}] manque en anglais.");
            $this->assertNotSame('', trim((string) $platEn[$cle]), "[{$cle}] est vide en anglais.");
        }

        foreach ($platEn as $cle => $valeur) {
            $this->assertArrayHasKey($cle, $platFr, "[{$cle}] manque en francais.");
            $this->assertNotSame('', trim((string) $platFr[$cle]), "[{$cle}] est vide en francais.");
        }
    }

    // =====================================================================
    // 8. Le rendu ne fuit rien
    // =====================================================================

    /**
     * Le libelle vient du CATALOGUE, jamais de la ligne.
     *
     * Le stockage ne contient aucun texte : si l'ecran affichait quelque chose
     * qui ressemble a du contenu, c'est qu'il l'aurait invente ou tire d'ailleurs.
     */
    public function test_the_label_comes_from_the_catalogue(): void
    {
        $this->emettre(destinataire: $this->alice);

        $this->actingAs($this->alice)->get(route('notifications.index'))
            ->assertOk()
            ->assertSee(__('notifications.keys.loop_invitation'));
    }

    /** L'entree de navigation pointe vers le Centre. */
    public function test_the_navigation_entry_points_to_the_center(): void
    {
        $this->actingAs($this->alice)->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('href="'.route('notifications.index').'"', false);
    }

    // =====================================================================
    // Helper
    // =====================================================================

    /** Le fragment HTML de l'onglet « Toutes », pour y chercher `aria-current`. */
    private function extraireOngletToutes(string $html): string
    {
        $debut = strpos($html, 'data-notifications-filter="toutes"');

        if ($debut === false) {
            return '';
        }

        // On remonte a l'ouverture de la balise, puis on prend jusqu'a sa fin.
        $ouverture = strrpos(substr($html, 0, $debut), '<a ');
        $fin = strpos($html, '>', $debut);

        return substr($html, (int) $ouverture, (int) $fin - (int) $ouverture);
    }

    private function emettre(?Organization $organisation = null, ?User $destinataire = null): MemberNotification
    {
        return $this->emetteur->emit(
            notificationKey: NotificationCatalogue::LOOP_INVITATION,
            organization: $organisation ?? $this->orgMembre,
            recipient: $destinataire ?? $this->alice,
            eventId: (string) Str::uuid(),
            objectType: self::OBJET,
            objectId: (string) Str::uuid(),
        );
    }
}
