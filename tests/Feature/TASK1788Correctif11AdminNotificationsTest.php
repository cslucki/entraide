<?php

namespace Tests\Feature;

use App\Models\MemberNotification;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1788, correctif 11 — la gestion des notifications par le SuperAdmin.
 *
 * Page `/admin/notifications` : filtres Organization et destinataire, tri sur
 * Date / Type / Destinataire, et trois gestes — Voir, Modifier, Supprimer.
 *
 * ## ⚠️ « Modifier » ne peut PAS corriger les faits, et ce n'est pas mon choix
 *
 * `MemberNotification` l'interdit, et il explique pourquoi :
 *
 *   « Une notification est un fait date : a qui, dans quel tenant, a propos de
 *     quoi. Cela ne se corrige pas, cela se remplace. Seul l'etat de lecture
 *     evolue. »
 *
 * `NotificationInvariants::IMMUTABLE` fige sept colonnes et un `update()`
 * dessus LEVE une exception. Un test le prouve ici plutot que de le croire :
 * si quelqu'un relache la garde un jour, c'est l'ecran d'administration qui
 * deviendrait trompeur en premier.
 *
 * ## Ce modele n'a AUCUN scope global — mesure, pas suppose
 *
 * `getGlobalScopes()` rend un tableau vide : ni tenant, ni suppression douce.
 * La page lit les deux tenants parce que rien ne l'en empeche, et la SEULE
 * garde est `AdminMiddleware`.
 */
class TASK1788Correctif11AdminNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private Organization $orgA;

    private Organization $orgB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orgA = Organization::factory()->create(['slug' => 'org-a-1788-11', 'is_active' => true, 'locale' => 'fr']);
        $this->orgB = Organization::factory()->create(['slug' => 'org-b-1788-11', 'is_active' => true, 'locale' => 'fr']);

        $this->superAdmin = User::factory()->create(['is_admin' => true, 'organization_id' => $this->orgA->id]);
    }

    /**
     * ⚠️ La cle ET le type d'objet doivent etre DECLARES au catalogue.
     *
     * `NotificationInvariants::assert()` refuse toute cle inconnue de
     * `NotificationCatalogue` — ma premiere version inventait des cles de
     * mesure (`aaa_premier_type`…) et les dix tests tombaient sur un
     * `InvalidArgumentException`. On prend donc de VRAIES cles, et leur
     * `object_type` avec : un couple invente serait refuse tout autant.
     */
    private function notification(
        Organization $org,
        User $destinataire,
        string $cle = 'entraide.comment.created',
        ?\DateTimeInterface $quand = null,
    ): MemberNotification {
        $typesParCle = [
            'entraide.comment.created' => 'entraide_comment',
            'blog.comment.created' => 'blog_comment',
            'loop.member_added' => 'loop',
            'transaction.proposal_created' => 'transaction',
        ];

        $ligne = MemberNotification::create([
            'organization_id' => $org->id,
            'recipient_id' => $destinataire->id,
            'notification_key' => $cle,
            'event_id' => (string) \Illuminate\Support\Str::uuid(),
            'object_type' => $typesParCle[$cle] ?? 'entraide_comment',
            'object_id' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        if ($quand !== null) {
            $ligne->forceFill(['created_at' => $quand])->save();
        }

        return $ligne->fresh();
    }

    /** Le corps du tableau seul — le menu des filtres n'en fait pas partie. */
    private function corpsDuTableau(string $html): string
    {
        $debut = strpos($html, '<tbody');
        $this->assertNotFalse($debut, 'Temoin absent : le tableau n\'est pas rendu.');

        return substr($html, $debut, strpos($html, '</tbody>', $debut) - $debut);
    }

    private function page(array $parametres = []): string
    {
        return $this->actingAs($this->superAdmin)
            ->get(route('admin.notifications', $parametres))
            ->assertOk()
            ->getContent();
    }

    // ── La porte ─────────────────────────────────────────────────────────────

    public function test_la_page_est_fermee_a_qui_n_est_pas_super_admin(): void
    {
        $adminOrg = User::factory()->create(['organization_id' => $this->orgA->id, 'is_admin' => false]);

        $this->actingAs($adminOrg)->get(route('admin.notifications'))->assertStatus(403);
        $this->get(route('admin.notifications'))->assertStatus(403);
    }

    /**
     * ⚠️ La garde du MODELE, prouvee plutot que supposee.
     *
     * Si elle tombait, l'ecran d'administration deviendrait trompeur en
     * premier : il annonce qu'on ne corrige pas les faits.
     */
    public function test_les_faits_d_une_notification_restent_incorrigibles(): void
    {
        $ligne = $this->notification($this->orgA, User::factory()->create(['organization_id' => $this->orgA->id]));

        $this->expectException(\RuntimeException::class);

        $ligne->update(['notification_key' => 'blog.comment.created']);
    }

    // ── Voir ─────────────────────────────────────────────────────────────────

    public function test_le_super_admin_voit_les_notifications_des_deux_tenants(): void
    {
        $this->notification($this->orgA, User::factory()->create(['organization_id' => $this->orgA->id, 'first_name' => 'Ottoline', 'name' => 'Kaminski']));
        $this->notification($this->orgB, User::factory()->create(['organization_id' => $this->orgB->id, 'first_name' => 'Brunhilde', 'name' => 'Bartholomew']));

        $html = $this->page();

        $this->assertStringContainsString('Ottoline', $html, 'Le tenant A manque.');
        $this->assertStringContainsString('Brunhilde', $html, 'Le tenant B manque.');
    }

    public function test_les_filtres_bornent_la_lecture(): void
    {
        $ottoline = User::factory()->create(['organization_id' => $this->orgA->id, 'first_name' => 'Ottoline', 'name' => 'Kaminski']);
        $brunhilde = User::factory()->create(['organization_id' => $this->orgA->id, 'first_name' => 'Brunhilde', 'name' => 'Bartholomew']);
        $etranger = User::factory()->create(['organization_id' => $this->orgB->id, 'first_name' => 'Etranger', 'name' => 'Dautretenant']);

        $this->notification($this->orgA, $ottoline);
        $this->notification($this->orgA, $brunhilde);
        $this->notification($this->orgB, $etranger);

        // ⚠️ Les assertions portent sur le TABLEAU, pas sur la page : le menu
        // du filtre liste legitimement tous les destinataires du tenant, et
        // leur nom y figure donc meme quand leur ligne est filtree. Ma
        // premiere version cherchait dans tout le document et rougissait sur
        // un filtre pourtant juste.
        $parOrg = $this->corpsDuTableau($this->page(['organization_id' => $this->orgA->id]));
        $this->assertStringContainsString('Ottoline', $parOrg, 'Temoin absent : le tenant choisi ne rend rien.');
        $this->assertStringNotContainsString('Etranger', $parOrg, 'Le filtre d\'Organization ne borne pas.');

        $parPersonne = $this->corpsDuTableau($this->page(['user_id' => $ottoline->id]));
        $this->assertStringContainsString('Ottoline', $parPersonne, 'Temoin absent : le destinataire choisi ne rend rien.');
        $this->assertStringNotContainsString('Brunhilde', $parPersonne, 'Le filtre par destinataire ne borne pas.');
    }

    public function test_la_fiche_montre_ce_que_la_ligne_porte(): void
    {
        $destinataire = User::factory()->create(['organization_id' => $this->orgB->id, 'first_name' => 'Ottoline', 'name' => 'Kaminski']);
        $ligne = $this->notification($this->orgB, $destinataire, 'blog.comment.created');

        $html = $this->actingAs($this->superAdmin)
            ->get(route('admin.notifications.show', $ligne->id))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-marqueur="fiche-notification"', $html, 'La fiche n\'est pas rendue.');
        $this->assertStringContainsString('blog.comment.created', $html, 'Le type manque sur la fiche.');
        $this->assertStringContainsString('Ottoline', $html, 'Le destinataire manque sur la fiche.');
        $this->assertStringContainsString(e(__('admin.notifications_immutable_hint')), $html,
            'La fiche ne dit pas pourquoi les champs ne se corrigent pas.');
    }

    // ── Les tris ─────────────────────────────────────────────────────────────

    public function test_le_tri_par_date_se_renverse(): void
    {
        $qui = User::factory()->create(['organization_id' => $this->orgA->id, 'first_name' => 'Ottoline', 'name' => 'Kaminski']);

        $this->notification($this->orgA, $qui, 'blog.comment.created', now()->subDays(3));
        $this->notification($this->orgA, $qui, 'transaction.proposal_created', now());

        $descendant = $this->page();
        $this->assertLessThan(strpos($descendant, 'blog.comment.created'), strpos($descendant, 'transaction.proposal_created'),
            'Par defaut, la plus recente doit venir en premier.');

        $ascendant = $this->page(['tri' => 'date', 'sens' => 'asc']);
        $this->assertLessThan(strpos($ascendant, 'transaction.proposal_created'), strpos($ascendant, 'blog.comment.created'),
            'Le tri croissant par date ne renverse pas la liste.');
    }

    public function test_le_tri_par_type_ordonne_les_cles(): void
    {
        $qui = User::factory()->create(['organization_id' => $this->orgA->id]);

        $this->notification($this->orgA, $qui, 'blog.comment.created');
        $this->notification($this->orgA, $qui, 'transaction.proposal_created');

        $html = $this->page(['tri' => 'type', 'sens' => 'asc']);

        $this->assertLessThan(strpos($html, 'transaction.proposal_created'), strpos($html, 'blog.comment.created'),
            'Le tri par type n\'ordonne pas les cles.');
    }

    /** ⚠️ Trier « par utilisateur » doit trier sur le NOM, jamais sur l'UUID. */
    public function test_le_tri_par_destinataire_ordonne_les_noms_et_non_les_identifiants(): void
    {
        $aaa = User::factory()->create(['organization_id' => $this->orgA->id, 'first_name' => 'Aaa', 'name' => 'Premiere']);
        $zzz = User::factory()->create(['organization_id' => $this->orgA->id, 'first_name' => 'Zzz', 'name' => 'Derniere']);

        $this->notification($this->orgA, $zzz);
        $this->notification($this->orgA, $aaa);

        $html = $this->page(['tri' => 'user', 'sens' => 'asc']);

        $this->assertLessThan(strpos($html, 'Zzz'), strpos($html, 'Aaa'),
            'Le tri par destinataire ne suit pas les noms.');
    }

    /** Les en-tetes sont des liens, et ils CONSERVENT les filtres. */
    public function test_les_entetes_de_tri_conservent_les_filtres(): void
    {
        $qui = User::factory()->create(['organization_id' => $this->orgA->id]);
        $this->notification($this->orgA, $qui);

        $html = $this->page(['organization_id' => $this->orgA->id]);

        foreach (['date', 'type', 'user'] as $colonne) {
            $this->assertStringContainsString('data-marqueur="tri-'.$colonne.'"', $html,
                "L'en-tete « {$colonne} » n'est pas un lien de tri.");
        }

        $this->assertStringContainsString(
            e(route('admin.notifications', ['organization_id' => $this->orgA->id, 'tri' => 'type', 'sens' => 'desc'])),
            $html,
            'Trier elargirait la selection : le filtre d\'Organization ne voyage pas avec le tri.'
        );
    }

    // ── Modifier (l'etat de lecture) et supprimer ────────────────────────────

    public function test_le_super_admin_bascule_l_etat_de_lecture(): void
    {
        $ligne = $this->notification($this->orgB, User::factory()->create(['organization_id' => $this->orgB->id]));

        $this->assertFalse($ligne->isRead(), 'Precondition : la notification doit etre non lue.');

        $this->actingAs($this->superAdmin)->put(route('admin.notifications.read', $ligne->id))->assertRedirect();
        $this->assertTrue($ligne->fresh()->isRead(), 'La notification n\'a pas ete marquee lue.');

        $this->actingAs($this->superAdmin)->put(route('admin.notifications.read', $ligne->id))->assertRedirect();
        $this->assertFalse($ligne->fresh()->isRead(), 'La notification n\'est pas revenue en non lue.');
    }

    public function test_le_super_admin_supprime_une_notification(): void
    {
        $ligne = $this->notification($this->orgB, User::factory()->create(['organization_id' => $this->orgB->id]));

        $this->actingAs($this->superAdmin)
            ->delete(route('admin.notifications.destroy', $ligne->id))
            ->assertRedirect(route('admin.notifications'));

        // Pas de suppression douce sur ce modele : la ligne disparait.
        $this->assertDatabaseMissing('member_notifications', ['id' => $ligne->id]);
    }

    // ── Le rail ──────────────────────────────────────────────────────────────

    /**
     * ⚠️ Depuis la demande suivante de Cyril, les trois ecrans qui parlent de
     * notifications tiennent dans une section DEDIEE du rail (« Notifications »)
     * plutot que dispersee entre Echanges, Emailer et Outils.
     */
    public function test_le_rail_porte_les_trois_entrees_dans_une_section_notifications(): void
    {
        $html = $this->page();

        $this->assertStringContainsString(e(route('admin.notifications')), $html,
            'Le rail ne porte pas l\'entree « Notifications ».');

        // Distincte du COCKPIT des notifications, qui mesure les LIVRAISONS :
        // les deux doivent coexister, desormais dans la meme section.
        $this->assertStringContainsString(e(route('admin.notifications-cockpit')), $html,
            'Le cockpit des notifications a disparu du rail.');

        // Et l'outil de TEST, qui envoie un essai plutot que d'en rendre compte.
        $this->assertStringContainsString(e(route('admin.outils.test-notification')), $html,
            'L\'outil de test Web Push a disparu du rail.');

        // ⚠️ PAS une recherche du texte brut « Notifications » : la page
        // `/admin/notifications` porte elle-meme un `<title>` et un `<h1>`
        // du meme nom, qui auraient fait reussir cette assertion sans jamais
        // lire le rail. Mesure directe : sabotage confirme (titre renomme
        // « NotificationsX ») laissait les deux premieres assertions vertes.
        $this->assertStringContainsString('data-marqueur="rail-section-notifications"', $html,
            'Le titre de la section « Notifications » n\'est pas rendu.');
    }
}
