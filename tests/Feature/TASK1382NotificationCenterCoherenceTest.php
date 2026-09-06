<?php

namespace Tests\Feature;

use App\Models\MemberNotification;
use App\Models\Organization;
use App\Models\User;
use App\Support\Notifications\NotificationCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * TASK-1382 — le Centre ne doit pas se contredire lui-meme.
 *
 * Le badge du rail et l'ecran lisent la MEME expression
 * (`unreadNotificationsCount`). Ils ne peuvent donc pas diverger sur le COMPTE.
 * Ils peuvent en revanche diverger sur le MESSAGE, et c'est ce qui est mesure
 * ici : une page hors borne rend une collection vide, que la vue interprete
 * comme « tout est lu ».
 *
 * Un badge qui affiche 12 au-dessus d'un ecran qui dit « tout est lu » n'est pas
 * un defaut cosmetique : c'est ce qui apprend au membre a ne plus regarder le
 * badge.
 */
class TASK1382NotificationCenterCoherenceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $membre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->membre = User::factory()->create(['organization_id' => $this->org->id]);
    }

    private function creerNotifications(int $combien): void
    {
        foreach (range(1, $combien) as $rang) {
            MemberNotification::create([
                'organization_id' => $this->org->id,
                'recipient_id' => $this->membre->id,
                'notification_key' => NotificationCatalogue::LOOP_INVITATION,
                'event_id' => (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'task1382:'.$rang),
                'object_type' => NotificationCatalogue::OBJECT_LOOP_INVITATION,
                'object_id' => (string) Str::uuid(),
            ]);
        }
    }

    // =====================================================================
    // Le defaut
    // =====================================================================

    /**
     * Une page hors borne ne doit pas annoncer « tout est lu ».
     *
     * Le scenario est ordinaire, pas construit : un membre a 30 non-lues, va
     * page 2, ouvre les six qui l'interessent. Il en reste 24, donc UNE page.
     * La page 2 devient hors borne — et `paginate()` rend simplement une
     * collection vide, sans rien signaler.
     *
     * L'ecran affiche alors l'etat vide du filtre : « Tout est lu / Aucune
     * notification non lue pour le moment », pendant que le badge du rail
     * affiche 24. Les deux lisent pourtant la meme base.
     */
    public function test_an_out_of_range_page_does_not_claim_everything_is_read(): void
    {
        $this->creerNotifications(30);

        // La page 2 existe et porte bien 5 lignes : le point de depart n'est pas
        // deja casse.
        $this->actingAs($this->membre)
            ->get(route('notifications.index', ['filtre' => 'non-lues', 'page' => 2]))
            ->assertOk()
            ->assertDontSee('data-notifications-filter-empty', escape: false);

        // Le membre en ouvre six. Il en reste 24 : tout tient desormais sur une
        // seule page, et la page 2 devient hors borne.
        MemberNotification::query()
            ->where('recipient_id', $this->membre->id)
            ->limit(6)
            ->get()
            ->each(fn (MemberNotification $n) => $n->markAsReadFor((string) $this->org->id, (string) $this->membre->id));

        $this->assertSame(24, $this->membre->unreadNotificationsCount((string) $this->org->id));

        $reponse = $this->actingAs($this->membre)
            ->get(route('notifications.index', ['filtre' => 'non-lues', 'page' => 2]));

        // Le membre est ramene sur la derniere page REELLE, et le filtre suit —
        // corriger la page ne doit pas changer ce qu'il regardait.
        $reponse->assertRedirect();
        $cible = $reponse->headers->get('Location');
        $this->assertStringContainsString('filtre=non-lues', $cible);
        $this->assertStringContainsString('page=1', $cible);

        // Et ce qu'il voit en arrivant contredit le defaut : des non-lues, pas
        // « tout est lu ».
        $this->actingAs($this->membre)
            ->get($cible)
            ->assertOk()
            ->assertDontSee('data-notifications-filter-empty', escape: false)
            ->assertSee('data-notification-unread', escape: false);
    }

    /**
     * La redirection ne doit pas faire sortir le membre de son tenant.
     *
     * Le Centre existe en DEUX routes : la courte et l'org-scopee sous
     * `/org/{slug}`. Une correction de page construite a partir d'un nom de
     * route plutot que du chemin courant renverrait un membre venu de la
     * variante prefixee vers la variante courte — laquelle resout l'Organization
     * par defaut de la plateforme. La reparation deviendrait alors une sortie de
     * tenant silencieuse.
     */
    public function test_the_correction_stays_on_the_org_scoped_route(): void
    {
        $this->creerNotifications(30);

        MemberNotification::query()
            ->where('recipient_id', $this->membre->id)
            ->limit(6)
            ->get()
            ->each(fn (MemberNotification $n) => $n->markAsReadFor((string) $this->org->id, (string) $this->membre->id));

        $reponse = $this->actingAs($this->membre)->get(route('organization.notifications.index', [
            'organization' => $this->org->slug,
            'filtre' => 'non-lues',
            'page' => 2,
        ]));

        $reponse->assertRedirect();
        $this->assertStringContainsString('/org/'.$this->org->slug.'/notifications', $reponse->headers->get('Location'));
    }

    /**
     * Le contre-exemple, sans lequel le test precedent se satisferait d'un ecran
     * qui n'afficherait JAMAIS d'etat vide.
     *
     * Ici il n'y a vraiment rien a lire, et le message doit apparaitre.
     */
    public function test_a_genuinely_empty_filter_still_says_everything_is_read(): void
    {
        $this->creerNotifications(3);

        MemberNotification::query()
            ->where('recipient_id', $this->membre->id)
            ->get()
            ->each(fn (MemberNotification $n) => $n->markAsReadFor((string) $this->org->id, (string) $this->membre->id));

        $this->actingAs($this->membre)
            ->get(route('notifications.index', ['filtre' => 'non-lues']))
            ->assertOk()
            ->assertSee('data-notifications-filter-empty', escape: false);
    }

    /**
     * Et une boite reellement vide dit « aucune notification », pas « tout est
     * lu » : les deux etats vides restent distincts.
     */
    public function test_an_empty_inbox_keeps_its_own_message(): void
    {
        $this->actingAs($this->membre)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('data-notifications-empty', escape: false)
            ->assertDontSee('data-notifications-filter-empty', escape: false);
    }

    // =====================================================================
    // Le repli de libelle, et le piege qu'il cache
    // =====================================================================

    /**
     * `Lang::has()` HONORE LA LOCALE DE REPLI.
     *
     * La vue s'en sert pour decider si une cle du catalogue a un libelle. Sous
     * une locale ou la cle manque, `Lang::has()` rend malgre tout `true` en
     * passant par `fr` — et le membre lit du FRANCAIS dans une interface
     * anglaise, au lieu du repli generique prevu pour ce cas.
     *
     * Le defaut est aujourd'hui INATTEIGNABLE par les cles reelles : la garde
     * de TASK-1379 exige un libelle dans les deux locales. Ce test mesure donc
     * le MECANISME sur une cle inventee, pour que le repli reste correct le jour
     * ou une cle arrivera sans sa traduction — ce qui est precisement ce que le
     * repli existe pour couvrir.
     */
    public function test_lang_has_honours_the_fallback_and_the_view_must_not_rely_on_it(): void
    {
        $ancienne = app()->getLocale();

        try {
            app()->setLocale('en');

            Lang::addLines(['notifications.keys.task1382_fictive' => 'Libelle francais'], 'fr');

            // La demonstration du piege : la cle n'existe PAS en anglais, et
            // `Lang::has()` repond pourtant `true`.
            $this->assertTrue(Lang::has('notifications.keys.task1382_fictive'));
            $this->assertSame('Libelle francais', __('notifications.keys.task1382_fictive'));

            // Desactiver le repli, et elle repond enfin ce qu'on lui demande.
            $this->assertFalse(Lang::has('notifications.keys.task1382_fictive', null, false));
        } finally {
            app()->setLocale($ancienne);
        }
    }

    /**
     * Et le CHEMIN DE LA VUE, pas seulement le mecanisme.
     *
     * Mesure faite : la version precedente de ce fichier ne testait que `Lang`,
     * et remettre le repli dans la vue ne la faisait PAS rougir. Un correctif
     * qu'aucun test ne peut infirmer n'est pas un correctif, c'est une opinion.
     *
     * Le cas est inatteignable par les cles reelles — la garde de TASK-1379
     * exige un libelle dans les deux locales. On fabrique donc la situation :
     * une ligne inseree en base BRUTE (l'emetteur refuserait une cle hors
     * catalogue, a juste titre) portant une cle traduite en francais seulement,
     * lue sous une interface anglaise.
     *
     * Sans le correctif, le membre lit « Libelle francais » dans une interface
     * anglaise. Avec, il lit le repli generique — ce que ce repli existe pour
     * faire.
     */
    public function test_a_key_translated_in_one_locale_only_falls_back_to_the_generic_label(): void
    {
        Lang::addLines(['notifications.keys.task1382_fictive' => 'Libelle francais uniquement'], 'fr');

        $this->membre->forceFill(['preferred_locale' => 'en'])->saveQuietly();

        DB::table('member_notifications')->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => $this->org->id,
            'recipient_id' => $this->membre->id,
            'notification_key' => 'task1382.fictive',
            'event_id' => (string) Str::uuid(),
            'object_type' => 'task1382_fictif',
            'object_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // `data-notification-id` plutot que le texte du repli : « Notification »
        // apparait ailleurs dans la page (titre, navigation) et passerait donc
        // pour la mauvaise raison. Cette assertion prouve que la LIGNE est bien
        // rendue ; c'est la suivante qui discrimine.
        $this->actingAs($this->membre->fresh())
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('data-notification-id', escape: false)
            ->assertDontSee('Libelle francais uniquement', escape: false);
    }
}
