<?php

namespace Tests\Feature;

use App\Models\MemberNotification;
use App\Models\MemberNotificationDelivery;
use App\Models\Organization;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Push\PushSubscriptionStore;
use App\Support\Notifications\NotificationCatalogue;
use App\Support\Push\SuperAdminPushAccessPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * TASK-1821 — « Appareils Push » : la console GLOBALE, décidée en arbitrage
 * MASTER après que « Mes appareils Push » (T1820) s'est révélé trop étroit
 * en recette (un abonnement créé sous un AUTRE compte, via un tunnel
 * Cloudflare, était invisible — comportement correct, mais pas le besoin
 * réel).
 *
 * ## Un seul écran, pas deux
 *
 * Décision produit (14/11, en session) : l'URL et le nom de route de T1820
 * sont CONSERVÉS — `admin.outils.my-push-devices` devient la console
 * globale, plutôt qu'un second écran voisin. Ce fichier couvre donc ce que
 * T1821 ajoute (garde durcie, liste tous comptes, propriétaire affiché,
 * recherche, suppression de la ligne d'autrui, journalisation) ;
 * `TASK1820MyPushDevicesTest` couvre ce qui n'a pas bougé et a été mis à
 * jour là où T1821 remplace délibérément son comportement.
 */
class TASK1821AllPushDevicesConsoleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        config(['webpush.vapid.public_key' => 'clef-vapid-de-test-task-1821-console']);

        $this->organization = Organization::factory()->create(['is_active' => true]);
    }

    private function superadminReel(): User
    {
        return User::factory()->create([
            'organization_id' => $this->organization->id,
            'is_admin' => true,
            'email' => 'superadmin-reel-'.bin2hex(random_bytes(4)).'@exemple.com',
        ]);
    }

    private function adminDemo(): User
    {
        return User::factory()->create([
            'organization_id' => $this->organization->id,
            'is_admin' => true,
            'email' => 'admin-demo-'.bin2hex(random_bytes(4)).'@bouclepro.test',
        ]);
    }

    private function membre(): User
    {
        return User::factory()->create([
            'organization_id' => $this->organization->id,
            'is_admin' => false,
        ]);
    }

    private function appareil(User $proprietaire, ?string $libelle = 'Android'): PushSubscription
    {
        return app(PushSubscriptionStore::class)->revendiquer(
            $proprietaire,
            'https://fcm.googleapis.com/fcm/send/t1821-console-'.bin2hex(random_bytes(8)),
            str_repeat('A', 87),
            str_repeat('B', 22),
            $libelle,
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // 1. SuperAdminPushAccessPolicy — pure, sans HTTP
    // ═══════════════════════════════════════════════════════════════════

    public function test_la_politique_refuse_un_compte_sans_is_admin(): void
    {
        $policy = new SuperAdminPushAccessPolicy;

        $this->assertFalse($policy->estAutorise($this->membre()));
    }

    public function test_la_politique_refuse_un_compte_de_demonstration_meme_admin(): void
    {
        $policy = new SuperAdminPushAccessPolicy;

        $this->assertFalse($policy->estAutorise($this->adminDemo()));
    }

    public function test_la_politique_refuse_null(): void
    {
        $policy = new SuperAdminPushAccessPolicy;

        $this->assertFalse($policy->estAutorise(null));
    }

    public function test_la_politique_autorise_un_superadmin_reel(): void
    {
        $policy = new SuperAdminPushAccessPolicy;

        $this->assertTrue($policy->estAutorise($this->superadminReel()));
    }

    // ═══════════════════════════════════════════════════════════════════
    // 2. Autorisation HTTP — le scénario exact de la garde fail-closed
    // ═══════════════════════════════════════════════════════════════════

    public function test_le_superadmin_reel_peut_ouvrir_la_console(): void
    {
        $this->actingAs($this->superadminReel())
            ->get(route('admin.outils.my-push-devices'))
            ->assertOk();
    }

    public function test_un_membre_ordinaire_est_refuse(): void
    {
        $this->actingAs($this->membre())
            ->get(route('admin.outils.my-push-devices'))
            ->assertForbidden();
    }

    public function test_un_compte_admin_de_demonstration_est_refuse(): void
    {
        $this->actingAs($this->adminDemo())
            ->get(route('admin.outils.my-push-devices'))
            ->assertForbidden();
    }

    public function test_un_compte_admin_de_demonstration_ne_peut_pas_supprimer(): void
    {
        $victime = $this->superadminReel();
        $appareilVictime = $this->appareil($victime);

        $this->actingAs($this->adminDemo())
            ->delete(route('admin.outils.my-push-devices.destroy', ['subscription' => $appareilVictime->id]))
            ->assertForbidden();

        $this->assertNotNull(PushSubscription::query()->find($appareilVictime->id));
    }

    public function test_un_visiteur_non_connecte_est_redirige(): void
    {
        $this->get(route('admin.outils.my-push-devices'))->assertRedirect();
    }

    // ═══════════════════════════════════════════════════════════════════
    // 3. La console montre VRAIMENT tout le monde (le besoin de l'arbitrage)
    // ═══════════════════════════════════════════════════════════════════

    public function test_la_console_montre_les_appareils_de_plusieurs_comptes_distincts(): void
    {
        $superadmin = $this->superadminReel();
        $membreA = $this->membre();
        $membreB = $this->membre();

        $appareilA = $this->appareil($membreA, 'Appareil de A');
        $appareilB = $this->appareil($membreB, 'Appareil de B');

        $html = $this->actingAs($superadmin)
            ->get(route('admin.outils.my-push-devices'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(mb_substr($appareilA->endpoint_hash, 0, 8), $html);
        $this->assertStringContainsString(mb_substr($appareilB->endpoint_hash, 0, 8), $html);
        $this->assertStringContainsString($membreA->email, $html);
        $this->assertStringContainsString($membreB->email, $html);
    }

    public function test_le_total_compte_tous_les_comptes(): void
    {
        $superadmin = $this->superadminReel();
        $this->appareil($this->membre());
        $this->appareil($this->membre());
        $this->appareil($this->membre());

        $html = $this->actingAs($superadmin)
            ->get(route('admin.outils.my-push-devices'))
            ->getContent();

        $this->assertStringContainsString('3', $html);
    }

    public function test_la_recherche_filtre_par_nom_ou_email_du_proprietaire(): void
    {
        $superadmin = $this->superadminReel();
        $cherche = User::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Alpha Zebra']);
        $autre = User::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Beta Yak']);

        $appareilCherche = $this->appareil($cherche);
        $appareilAutre = $this->appareil($autre);

        $html = $this->actingAs($superadmin)
            ->get(route('admin.outils.my-push-devices', ['q' => 'Alpha Zebra']))
            ->getContent();

        $this->assertStringContainsString(mb_substr($appareilCherche->endpoint_hash, 0, 8), $html);
        $this->assertStringNotContainsString(mb_substr($appareilAutre->endpoint_hash, 0, 8), $html);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 4. Suppression ciblée + journalisation (MASTER section 2/4)
    // ═══════════════════════════════════════════════════════════════════

    public function test_le_superadmin_reel_peut_retirer_l_abonnement_d_un_autre_compte(): void
    {
        $superadmin = $this->superadminReel();
        $proprietaire = $this->membre();
        $appareil = $this->appareil($proprietaire);
        $autreAppareilDuMemeProprietaire = $this->appareil($proprietaire, 'Autre appareil');

        $this->actingAs($superadmin)
            ->delete(route('admin.outils.my-push-devices.destroy', ['subscription' => $appareil->id]))
            ->assertRedirect();

        $this->assertNull(PushSubscription::query()->find($appareil->id));
        $this->assertNotNull(
            PushSubscription::query()->find($autreAppareilDuMemeProprietaire->id),
            'La suppression doit être strictement ciblée : ne jamais toucher une autre ligne.'
        );
    }

    public function test_supprimer_un_identifiant_deja_absent_n_est_pas_une_erreur(): void
    {
        $superadmin = $this->superadminReel();
        $appareil = $this->appareil($this->membre());
        $appareil->delete();

        $this->actingAs($superadmin)
            ->delete(route('admin.outils.my-push-devices.destroy', ['subscription' => $appareil->id]))
            ->assertRedirect();
    }

    public function test_la_suppression_est_journalisee_sans_endpoint_ni_clef(): void
    {
        Log::spy();

        $superadmin = $this->superadminReel();
        $proprietaire = $this->membre();
        $appareil = $this->appareil($proprietaire);

        $this->actingAs($superadmin)
            ->delete(route('admin.outils.my-push-devices.destroy', ['subscription' => $appareil->id]));

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $contexte) use ($superadmin, $proprietaire, $appareil) {
                $contenu = json_encode($contexte);

                return str_contains($message, 'TASK-1821')
                    && $contexte['par'] === (string) $superadmin->id
                    && $contexte['proprietaire'] === $proprietaire->id
                    && $contexte['abonnement'] === $appareil->id
                    && $contexte['retire'] === true
                    && ! str_contains($contenu, 'fcm.googleapis.com')
                    && ! str_contains($contenu, str_repeat('A', 87));
            })
            ->once();
    }

    // ═══════════════════════════════════════════════════════════════════
    // 5. Aucune clef ni endpoint complet dans le HTML
    // ═══════════════════════════════════════════════════════════════════

    public function test_aucune_cle_ni_endpoint_complet_n_apparait_dans_le_html(): void
    {
        $superadmin = $this->superadminReel();
        $appareil = $this->appareil($this->membre());

        $html = $this->actingAs($superadmin)
            ->get(route('admin.outils.my-push-devices'))
            ->getContent();

        $this->assertStringNotContainsString($appareil->endpoint, $html);
        $this->assertStringNotContainsString($appareil->p256dh, $html);
        $this->assertStringNotContainsString($appareil->auth, $html);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 5 bis. L'état VAPID est CONTEXTUALISÉ — le faux signal du 14/11
    // ═══════════════════════════════════════════════════════════════════

    public function test_un_abonnement_d_un_autre_environnement_n_est_jamais_dit_divergent(): void
    {
        $superadmin = $this->superadminReel();
        $appareil = $this->appareil($this->membre());

        // La ligne telle qu'un dump de PRODUCTION la depose dans une base
        // locale : stampee ailleurs, avec la clef de cet autre environnement.
        $appareil->forceFill([
            'created_env' => 'production',
            'vapid_key_hash' => hash('sha256', 'clef-vapid-d-un-autre-environnement'),
        ])->save();

        $this->assertSame(
            PushSubscription::VAPID_NON_COMPARABLE,
            $appareil->fresh()->etatCleVapidContextualise(),
        );

        $html = $this->actingAs($superadmin)
            ->get(route('admin.outils.my-push-devices'))
            ->assertOk()
            ->getContent();

        // `e()` : Blade echappe les apostrophes et guillemets typographiques
        // du catalogue. Comparer la chaine BRUTE a du HTML echappe est une
        // instabilite connue — on compare donc ce que la page rend vraiment.
        $this->assertStringContainsString(e(__('admin.push_devices_vapid_non_comparable', ['env' => 'production'])), $html);
        $this->assertStringNotContainsString(e(__('admin.push_devices_vapid_divergent')), $html);
    }

    public function test_un_abonnement_du_meme_environnement_reste_jugeable(): void
    {
        $appareil = $this->appareil($this->membre());

        // Stampe ICI, avec une clef qui n'a plus cours : la divergence est
        // alors une mesure valide, et elle doit continuer de sortir.
        $appareil->forceFill([
            'created_env' => (string) config('app.env'),
            'vapid_key_hash' => hash('sha256', 'une-clef-qui-n-a-plus-cours'),
        ])->save();

        $this->assertSame(
            PushSubscription::VAPID_DIVERGENTE,
            $appareil->fresh()->etatCleVapidContextualise(),
        );
    }

    public function test_le_resume_separe_les_environnements_et_n_evalue_que_le_courant(): void
    {
        $ici = $this->appareil($this->membre());
        $ici->forceFill(['created_env' => (string) config('app.env')])->save();

        $ailleurs = $this->appareil($this->membre());
        $ailleurs->forceFill([
            'created_env' => 'production',
            'vapid_key_hash' => hash('sha256', 'clef-d-ailleurs'),
        ])->save();

        $resume = collect(app(PushSubscriptionStore::class)->resumeParEnvironnement())
            ->keyBy('environnement');

        $this->assertTrue($resume[(string) config('app.env')]['comparable']);
        $this->assertSame(1, $resume[(string) config('app.env')]['eligibles']);

        $this->assertFalse($resume['production']['comparable']);
        $this->assertNull(
            $resume['production']['eligibles'],
            "L'eligibilite d'un abonnement d'un autre environnement n'est pas calculable ici : elle doit rester ABSENTE, jamais inventee."
        );
    }

    /**
     * La regle d'eligibilite ne doit exister QU'A UN SEUL ENDROIT.
     *
     * Le livreur et la console emploient le meme scope du modele. Ce test
     * les confronte sur un jeu de donnees ou les trois cas coexistent : si
     * quelqu'un recopiait la condition d'un cote, les deux compteurs
     * divergeraient et ce test rougirait.
     */
    public function test_le_compte_d_eligibles_suit_exactement_la_regle_du_livreur(): void
    {
        $env = (string) config('app.env');

        $concordant = $this->appareil($this->membre());
        $concordant->forceFill(['created_env' => $env])->save();

        $sansEmpreinte = $this->appareil($this->membre());
        $sansEmpreinte->forceFill(['created_env' => $env, 'vapid_key_hash' => null])->save();

        $divergent = $this->appareil($this->membre());
        $divergent->forceFill(['created_env' => $env, 'vapid_key_hash' => hash('sha256', 'autre')])->save();

        $selonLeLivreur = PushSubscription::query()
            ->where('created_env', $env)
            ->cleVapidUtilisable()
            ->count();

        $resume = collect(app(PushSubscriptionStore::class)->resumeParEnvironnement())->keyBy('environnement');

        $this->assertSame(2, $selonLeLivreur);
        $this->assertSame($selonLeLivreur, $resume[$env]['eligibles']);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 5 ter. Le panneau Diagnostic dit AU NIVEAU DU COMPTE, et le dit
    // ═══════════════════════════════════════════════════════════════════

    public function test_le_panneau_diagnostic_montre_les_livraisons_du_compte_en_les_annoncant_comme_telles(): void
    {
        $superadmin = $this->superadminReel();
        $proprietaire = $this->membre();
        $this->appareil($proprietaire);

        $notification = MemberNotification::create([
            'organization_id' => $this->organization->id,
            'recipient_id' => $proprietaire->id,
            'notification_key' => NotificationCatalogue::LOOP_MEMBER_ADDED,
            'event_id' => (string) Str::uuid(),
            // Le catalogue impose le type d'objet declare pour cette cle :
            // `NotificationInvariants` le verifie a l'ecriture.
            'object_type' => NotificationCatalogue::OBJECT_LOOP,
            'object_id' => (string) Str::uuid(),
        ]);

        // `$fillable` ne porte que `notification_id` et `channel` : l'etat se
        // pose en `forceFill()`, exactement comme le fait le livreur.
        $livraison = MemberNotificationDelivery::query()->create([
            'notification_id' => $notification->id,
            'channel' => NotificationCatalogue::CHANNEL_WEB_PUSH,
        ]);

        // La machine d'etats interdit `pending -> sent` : on emprunte le MEME
        // chemin que le livreur, prise de travail comprise.
        $livraison->forceFill(['status' => 'sending', 'claimed_at' => now(), 'attempts' => 1])->save();
        $livraison->forceFill(['status' => 'sent', 'diagnostic' => 'devices:1 revoked:1', 'sent_at' => now()])->save();

        $html = $this->actingAs($superadmin)
            ->get(route('admin.outils.my-push-devices'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('devices:1 revoked:1', $html);
        $this->assertStringContainsString(
            __('admin.all_push_devices_diagnostic_user_level_warning'),
            $html,
            "Une trace de niveau COMPTE affichee en face d'un appareil DOIT porter son avertissement : sans lui, l'ecran invente une tracabilite par appareil qui n'existe pas."
        );
    }

    public function test_l_ecran_nomme_les_quatre_niveaux_et_ne_promet_aucune_reception(): void
    {
        $this->appareil($this->membre());

        $html = $this->actingAs($this->superadminReel())
            ->get(route('admin.outils.my-push-devices'))
            ->getContent();

        $this->assertStringContainsString(__('admin.all_push_devices_level_registered'), $html);
        $this->assertStringContainsString(__('admin.all_push_devices_level_attempt'), $html);
        $this->assertStringContainsString(__('admin.all_push_devices_level_accepted'), $html);
        $this->assertStringContainsString(__('admin.all_push_devices_level_received'), $html);
    }

    // ═══════════════════════════════════════════════════════════════════
    // 5 quater. LE DÉFAUT DÉCOUVERT — mesuré ici, CORRIGÉ EN T1822
    // ═══════════════════════════════════════════════════════════════════

    /**
     * `revendiquer()` REECRIT `created_env` et `vapid_key_hash`.
     *
     * Ce test ne protege pas un comportement souhaitable : il EPINGLE un
     * defaut, pour qu'il soit mesure plutot que raconte, et pour que T1822
     * parte d'un rouge franc quand elle le corrigera.
     *
     * Consequence en production : un abonnement cree avec une clef qui n'a
     * plus cours voit son empreinte re-stampee avec la clef COURANTE des
     * qu'une reconciliation passe. Le livreur le considere alors eligible,
     * le fournisseur repond 2xx, et le navigateur jette le message en
     * silence — la divergence est devenue invisible.
     *
     * `push-device.js` s'en protege (il s'abstient de reconfirmer quand il
     * detecte la divergence), mais `push-first-run.js` appelle `confirmer()`
     * sans cette garde : c'est le chemin du premier lancement de la PWA
     * installee. Perimetre de T1822, hors perimetre ici.
     */
    public function test_DEFAUT_CONNU_la_reconciliation_reecrit_l_environnement_et_l_empreinte_vapid(): void
    {
        $proprietaire = $this->membre();
        $endpoint = 'https://fcm.googleapis.com/fcm/send/t1821-restamp-'.bin2hex(random_bytes(8));

        $magasin = app(PushSubscriptionStore::class);
        $abonnement = $magasin->revendiquer($proprietaire, $endpoint, str_repeat('A', 87), str_repeat('B', 22), 'Android');

        // La ligne telle qu'elle arrive d'un dump de production, creee sous
        // une clef qui n'a plus cours ici.
        $abonnement->forceFill([
            'created_env' => 'production',
            'vapid_key_hash' => hash('sha256', 'ancienne-clef-de-production'),
        ])->save();

        // Une simple reconciliation — le geste que le navigateur rejoue a
        // chaque ouverture de l'ecran des reglages.
        $magasin->revendiquer($proprietaire, $endpoint, str_repeat('A', 87), str_repeat('B', 22), null);

        $apres = $abonnement->fresh();

        $this->assertSame(
            (string) config('app.env'),
            $apres->created_env,
            'DEFAUT T1821 (corrige en T1822) : `created_env` n\'est pas une origine, il est reecrit a chaque reconciliation.',
        );
        $this->assertSame(
            PushSubscription::empreinteCleVapid(),
            $apres->vapid_key_hash,
            'DEFAUT T1821 (corrige en T1822) : `vapid_key_hash` enregistre la clef du DERNIER contact, pas celle de la creation — ce qui peut masquer une divergence reelle.',
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // 6. Le lien de navigation n'apparaît que pour qui est réellement autorisé
    // ═══════════════════════════════════════════════════════════════════

    public function test_le_lien_de_navigation_est_absent_pour_un_compte_de_demonstration(): void
    {
        // Lu depuis un AUTRE ecran d'administration : la page elle-meme
        // rendrait 403 a ce compte, et son contenu ne dirait rien du rail.
        $html = $this->actingAs($this->adminDemo())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(route('admin.outils.my-push-devices'), $html);
    }

    public function test_le_lien_de_navigation_est_present_pour_le_superadmin_reel(): void
    {
        $html = $this->actingAs($this->superadminReel())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(route('admin.outils.my-push-devices'), $html);
    }
}
