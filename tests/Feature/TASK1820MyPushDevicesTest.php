<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Push\PushSubscriptionStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TASK-1820 — « Mes appareils Push » : inspecter et retirer
 * INDIVIDUELLEMENT les abonnements du SuperAdmin CONNECTE, et lui seul.
 *
 * ## Le defaut constate
 *
 * Cyril voit deux abonnements Android pour un seul telephone principalement
 * utilise. L'unicite par `endpoint_hash` fonctionne ; le navigateur peut
 * simplement produire plusieurs endpoints SUCCESSIFS pour le meme appareil
 * (T1739/T1740/T1745), chacun restant en base jusqu'a son retrait. Rien ne
 * permettait de nettoyer cela depuis l'administration, en production.
 *
 * ## Ce que ces tests NE prouvent PAS, et pourquoi
 *
 * L'identification de « Cet appareil » est un calcul CLIENT (SHA-256 de
 * `pushManager.getSubscription().endpoint`, dans le navigateur) : aucun test
 * PHP ne peut l'exercer. Verifie en navigateur reel, local, mobile compris.
 */
class TASK1820MyPushDevicesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        // Clef VAPID DETERMINISTE, posee explicitement : aucun CI (SQLite ni
        // PostgreSQL) ne configure `VAPID_PUBLIC_KEY`. Sans cette ligne,
        // `PushSubscription::empreinteCleVapid()` rend `null` partout en CI, et
        // `etatCleVapid()` ne peut alors JAMAIS rendre autre chose que
        // VAPID_INCONNUE — meme pour un abonnement cree avec une clef
        // explicitement DIVERGENTE. Trouve a l'echec CI (SQLite ET
        // PostgreSQL), pas en local : mon `.env` local porte une vraie clef,
        // qui masquait ce defaut de fixture.
        config(['webpush.vapid.public_key' => 'clef-vapid-de-test-task-1820']);

        $this->organization = Organization::factory()->create(['is_active' => true]);
    }

    private function superadmin(): User
    {
        return User::factory()->create([
            'organization_id' => $this->organization->id,
            'is_admin' => true,
        ]);
    }

    private function membre(): User
    {
        return User::factory()->create([
            'organization_id' => $this->organization->id,
            'is_admin' => false,
        ]);
    }

    private function appareil(User $proprietaire, ?string $libelle = 'Android', ?string $endpoint = null): PushSubscription
    {
        return app(PushSubscriptionStore::class)->revendiquer(
            $proprietaire,
            $endpoint ?? 'https://fcm.googleapis.com/fcm/send/t1820-'.bin2hex(random_bytes(8)),
            str_repeat('A', 87),
            str_repeat('B', 22),
            $libelle,
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // 1. Superadmin autorise, autres roles refuses
    // ═══════════════════════════════════════════════════════════════════

    public function test_le_superadmin_peut_ouvrir_la_page(): void
    {
        $this->actingAs($this->superadmin())
            ->get(route('admin.outils.my-push-devices'))
            ->assertOk();
    }

    public function test_un_membre_ordinaire_est_refuse(): void
    {
        $this->actingAs($this->membre())
            ->get(route('admin.outils.my-push-devices'))
            ->assertForbidden();
    }

    public function test_un_visiteur_non_connecte_est_redirige(): void
    {
        $this->get(route('admin.outils.my-push-devices'))
            ->assertRedirect();
    }

    public function test_un_membre_ordinaire_ne_peut_pas_appeler_la_suppression(): void
    {
        $admin = $this->superadmin();
        $appareil = $this->appareil($admin);

        $this->actingAs($this->membre())
            ->delete(route('admin.outils.my-push-devices.destroy', ['subscription' => $appareil->id]))
            ->assertForbidden();

        $this->assertNotNull(PushSubscription::query()->find($appareil->id), 'Un membre refuse ne doit rien avoir supprime.');
    }

    // ═══════════════════════════════════════════════════════════════════
    // 2. Un utilisateur ne peut JAMAIS supprimer l'abonnement d'un autre
    // ═══════════════════════════════════════════════════════════════════

    /**
     * TASK-1821 REMPLACE cette regle, deliberement.
     *
     * T1820 interdisait a un administrateur de retirer la ligne d'un autre
     * compte : l'ecran etait borne au compte connecte. L'arbitrage T1821 en
     * fait une console GLOBALE — retirer la ligne d'autrui est desormais la
     * fonction, plus l'attaque. La garde a change de nature : elle ne
     * demande plus « est-ce la mienne ? » mais « ai-je le droit de gerer
     * celles de tout le monde ? » (`can:superadmin-push-all-devices`).
     *
     * Ce que ce test atteste donc maintenant : la primitive BORNEE au
     * proprietaire, `PushSubscriptionStore::renoncerParId()`, est restee
     * intacte et refuse toujours la ligne d'un autre. C'est elle que le
     * self-service emploie ; l'ecran d'administration passe par un chemin
     * DISTINCT (`retirerParIdPourSuperadmin()`).
     */
    public function test_la_primitive_bornee_au_proprietaire_refuse_toujours_la_ligne_d_un_autre(): void
    {
        $victime = $this->superadmin();
        $attaquant = $this->superadmin();
        $appareilVictime = $this->appareil($victime);

        $retire = app(PushSubscriptionStore::class)->renoncerParId($attaquant, $appareilVictime->id);

        $this->assertFalse($retire);
        $this->assertNotNull(
            PushSubscription::query()->find($appareilVictime->id),
            "`renoncerParId()` ne doit JAMAIS retirer la ligne d'un autre compte, meme en connaissant son identifiant exact."
        );
    }

    /**
     * TASK-1821 — et l'ECRAN, lui, le peut : c'est le sens de l'arbitrage.
     * Le test vit ici pour que la bascule soit lisible a cote de la regle
     * qu'elle remplace ; la couverture complete est dans
     * `TASK1821AllPushDevicesConsoleTest`.
     */
    public function test_l_ecran_retire_desormais_la_ligne_d_un_autre_compte(): void
    {
        $superadmin = $this->superadmin();
        $appareilDUnAutre = $this->appareil($this->membre());

        $this->actingAs($superadmin)
            ->delete(route('admin.outils.my-push-devices.destroy', ['subscription' => $appareilDUnAutre->id]))
            ->assertRedirect();

        $this->assertNull(PushSubscription::query()->find($appareilDUnAutre->id));
    }

    // ═══════════════════════════════════════════════════════════════════
    // 3. Les appareils affiches appartiennent UNIQUEMENT au compte connecte
    // ═══════════════════════════════════════════════════════════════════

    /**
     * TASK-1821 REMPLACE cette regle, deliberement.
     *
     * T1820 n'affichait que les appareils du compte connecte. C'est
     * precisement ce qui a invalide la recette : un abonnement cree depuis
     * un telephone, sous un AUTRE compte, restait invisible — exact, et
     * inutile. L'ecran montre desormais TOUT, avec son proprietaire.
     */
    public function test_la_page_liste_desormais_les_appareils_de_tous_les_comptes(): void
    {
        $moi = $this->superadmin();
        $unAutre = $this->membre();

        $monAppareil = $this->appareil($moi, 'Mon telephone');
        $sonAppareil = $this->appareil($unAutre, 'Son telephone');

        $html = $this->actingAs($moi)
            ->get(route('admin.outils.my-push-devices'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($monAppareil->endpoint_hash, $html);
        $this->assertStringContainsString($sonAppareil->endpoint_hash, $html);
        $this->assertStringContainsString('Son telephone', $html);
        // Et le proprietaire est NOMME : retirer une ligne sans savoir a qui
        // elle est serait une action a l'aveugle.
        $this->assertStringContainsString($unAutre->email, $html);
    }

    public function test_le_total_affiche_couvre_tous_les_comptes(): void
    {
        $moi = $this->superadmin();
        $unAutre = $this->membre();

        $this->appareil($moi);
        $this->appareil($moi);
        $this->appareil($unAutre);
        $this->appareil($unAutre);
        $this->appareil($unAutre);

        $html = $this->actingAs($moi)->get(route('admin.outils.my-push-devices'))->getContent();

        $this->assertStringContainsString(
            trans_choice('admin.push_devices_total', 5, ['count' => 5]),
            $html,
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // 4. Aucune cle sensible ni endpoint complet dans le HTML
    // ═══════════════════════════════════════════════════════════════════

    public function test_aucune_cle_ni_endpoint_complet_n_apparait_dans_le_html(): void
    {
        $moi = $this->superadmin();
        $endpoint = 'https://fcm.googleapis.com/fcm/send/t1820-secret-path-identifier-xyz';
        $appareil = $this->appareil($moi, 'Android', $endpoint);

        $html = $this->actingAs($moi)->get(route('admin.outils.my-push-devices'))->getContent();

        // L'ENDPOINT COMPLET ne doit jamais apparaitre.
        $this->assertStringNotContainsString($endpoint, $html);
        $this->assertStringNotContainsString('secret-path-identifier-xyz', $html);
        // Les CLEFS (p256dh, auth) ne doivent jamais apparaitre.
        $this->assertStringNotContainsString(str_repeat('A', 87), $html);
        $this->assertStringNotContainsString(str_repeat('B', 22), $html);
        // L'empreinte COMPLETE (64 car.) ne doit PAS etre un texte VISIBLE —
        // elle ne doit exister que dans l'attribut `data-fingerprint-full`,
        // jamais affichee comme contenu de page. On verifie que le texte
        // visible ne porte que les 8 premiers caracteres.
        $this->assertStringContainsString(mb_substr($appareil->endpoint_hash, 0, 8), $html);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 5. Suppression individuelle effective
    // ═══════════════════════════════════════════════════════════════════

    public function test_la_suppression_individuelle_est_effective(): void
    {
        $moi = $this->superadmin();
        $appareil = $this->appareil($moi);

        $reponse = $this->actingAs($moi)
            ->delete(route('admin.outils.my-push-devices.destroy', ['subscription' => $appareil->id]));

        $reponse->assertRedirect();
        $reponse->assertSessionHas('push_devices_resultat');
        $this->assertNull(PushSubscription::query()->find($appareil->id));
    }

    // ═══════════════════════════════════════════════════════════════════
    // 6. Gestion d'un abonnement deja supprime
    // ═══════════════════════════════════════════════════════════════════

    public function test_supprimer_un_abonnement_deja_supprime_n_est_pas_une_erreur(): void
    {
        $moi = $this->superadmin();
        $appareil = $this->appareil($moi);
        $id = $appareil->id;

        $this->actingAs($moi)->delete(route('admin.outils.my-push-devices.destroy', ['subscription' => $id]))->assertRedirect();
        $this->assertNull(PushSubscription::query()->find($id));

        // Second retrait, meme identifiant : pas d'exception, un message honnete.
        $reponse = $this->actingAs($moi)->delete(route('admin.outils.my-push-devices.destroy', ['subscription' => $id]));

        $reponse->assertRedirect();
        $reponse->assertSessionHas('push_devices_resultat', __('admin.push_devices_already_removed'));
    }

    public function test_supprimer_un_identifiant_inexistant_n_est_pas_une_erreur(): void
    {
        $moi = $this->superadmin();

        $reponse = $this->actingAs($moi)
            ->delete(route('admin.outils.my-push-devices.destroy', ['subscription' => (string) \Illuminate\Support\Str::uuid()]));

        $reponse->assertRedirect();
        $reponse->assertSessionHas('push_devices_resultat', __('admin.push_devices_already_removed'));
    }

    public function test_un_identifiant_malforme_est_refuse_proprement(): void
    {
        $moi = $this->superadmin();

        $this->actingAs($moi)
            ->delete(route('admin.outils.my-push-devices.destroy', ['subscription' => 'pas-un-uuid']))
            ->assertStatus(302); // redirige par la validation, pas une 500
    }

    // ═══════════════════════════════════════════════════════════════════
    // 7. Absence de suppression involontaire d'autres lignes
    // ═══════════════════════════════════════════════════════════════════

    public function test_supprimer_une_ligne_ne_touche_aucune_autre_ligne(): void
    {
        $moi = $this->superadmin();
        $aConserver1 = $this->appareil($moi, 'Appareil A');
        $aSupprimer = $this->appareil($moi, 'Appareil B');
        $aConserver2 = $this->appareil($moi, 'Appareil C');
        $autreCompteAppareil = $this->appareil($this->superadmin(), 'Appareil D');

        $this->actingAs($moi)
            ->delete(route('admin.outils.my-push-devices.destroy', ['subscription' => $aSupprimer->id]))
            ->assertRedirect();

        $this->assertNull(PushSubscription::query()->find($aSupprimer->id));
        $this->assertNotNull(PushSubscription::query()->find($aConserver1->id), 'Un autre appareil du MEME compte doit survivre.');
        $this->assertNotNull(PushSubscription::query()->find($aConserver2->id), 'Un autre appareil du MEME compte doit survivre.');
        $this->assertNotNull(PushSubscription::query()->find($autreCompteAppareil->id), "L'appareil d'un AUTRE compte doit survivre.");
    }

    // ═══════════════════════════════════════════════════════════════════
    // 8. Reconciliation ulterieure possible — comportement EXISTANT, intact
    // ═══════════════════════════════════════════════════════════════════

    public function test_la_reconciliation_ulterieure_recree_l_abonnement_apres_suppression(): void
    {
        $moi = $this->superadmin();
        $endpoint = 'https://fcm.googleapis.com/fcm/send/t1820-reconciliation';
        $appareil = $this->appareil($moi, 'Android', $endpoint);

        $this->actingAs($moi)
            ->delete(route('admin.outils.my-push-devices.destroy', ['subscription' => $appareil->id]))
            ->assertRedirect();
        $this->assertNull(PushSubscription::query()->find($appareil->id));

        // Le navigateur « revient » : meme comportement que T1739, non modifie.
        $recree = app(PushSubscriptionStore::class)->revendiquer($moi, $endpoint, str_repeat('A', 87), str_repeat('B', 22), 'Android');

        $this->assertNotNull(PushSubscription::query()->where('endpoint_hash', $recree->endpoint_hash)->first());
        $this->assertSame($moi->id, $recree->user_id);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Indication VAPID — les trois etats, distincts, jamais une preuve de reception
    // ═══════════════════════════════════════════════════════════════════

    public function test_l_etat_vapid_concordant_est_affiche_sans_etre_presente_comme_une_preuve(): void
    {
        $moi = $this->superadmin();
        $this->appareil($moi); // cree avec la clef en vigueur (vapid_key_hash courant)

        $html = $this->actingAs($moi)->get(route('admin.outils.my-push-devices'))->getContent();

        $this->assertStringContainsString(__('admin.push_devices_vapid_concordant'), $html);
    }

    public function test_l_etat_vapid_divergent_est_signale_distinctement(): void
    {
        $moi = $this->superadmin();
        $appareil = $this->appareil($moi);
        $appareil->forceFill(['vapid_key_hash' => hash('sha256', 'une-autre-clef-plus-en-vigueur')])->save();

        $html = $this->actingAs($moi)->get(route('admin.outils.my-push-devices'))->getContent();

        $this->assertStringContainsString(__('admin.push_devices_vapid_divergent'), $html);
        $this->assertStringNotContainsString(__('admin.push_devices_vapid_concordant'), $html);
    }

    public function test_l_etat_vapid_inconnu_pour_une_ligne_anterieure_a_la_colonne(): void
    {
        $moi = $this->superadmin();
        $appareil = $this->appareil($moi);
        $appareil->forceFill(['vapid_key_hash' => null])->save();

        $html = $this->actingAs($moi)->get(route('admin.outils.my-push-devices'))->getContent();

        $this->assertStringContainsString(__('admin.push_devices_vapid_unknown'), $html);
    }

    // ═══════════════════════════════════════════════════════════════════
    // La trace ne stocke ni endpoint ni clef (MASTER)
    // ═══════════════════════════════════════════════════════════════════

    public function test_le_journal_de_suppression_ne_contient_ni_endpoint_ni_clef(): void
    {
        $moi = $this->superadmin();
        $endpoint = 'https://fcm.googleapis.com/fcm/send/t1820-trace-journal-unique';
        $appareil = $this->appareil($moi, 'Android', $endpoint);

        \Illuminate\Support\Facades\Log::shouldReceive('info')
            ->once()
            ->withArgs(function (string $message, array $contexte) use ($endpoint) {
                $serialise = json_encode($contexte);

                return ! str_contains($serialise, $endpoint)
                    && ! str_contains($serialise, str_repeat('A', 87))
                    && ! str_contains($serialise, str_repeat('B', 22));
            });

        $this->actingAs($moi)
            ->delete(route('admin.outils.my-push-devices.destroy', ['subscription' => $appareil->id]))
            ->assertRedirect();
    }
}
