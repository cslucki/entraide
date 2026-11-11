<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\LoopNotificationPreference;
use App\Models\MemberNotificationPreference;
use App\Models\Organization;
use App\Models\User;
use App\Support\Notifications\NotificationCatalogue;
use App\Support\Notifications\NotificationPreferenceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * TASK-1775 (CDC B) — les reglages de notification COLLECTIVE, par Boucle.
 *
 * ## Une table COMPLEMENTAIRE, jamais une migration de l'historique
 *
 * `member_loop_notification_preferences` est NEUVE : elle n'altere aucune
 * colonne de `member_notification_preferences` (T1375). Ce fichier verrouille
 * sa propre coherence (identite figee, tenant verifie) ET la priorite qu'elle
 * instaure sur l'ecart global historique — jamais l'inverse.
 */
class TASK1775LoopNotificationPreferencesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $membre;

    private Loop $boucle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['is_active' => true]);
        $this->membre = User::factory()->create(['organization_id' => $this->organization->id]);
        $this->boucle = Loop::factory()->create(['organization_id' => $this->organization->id]);

        LoopMember::create([
            'loop_id' => $this->boucle->id,
            'user_id' => $this->membre->id,
            'organization_id' => $this->organization->id,
            'role' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ]);
    }

    private function resolver(): NotificationPreferenceResolver
    {
        return app(NotificationPreferenceResolver::class);
    }

    private function creerReglage(array $attributs): LoopNotificationPreference
    {
        $reglage = new LoopNotificationPreference(array_intersect_key($attributs, array_flip([
            'collective_enabled', 'email_enabled', 'web_push_enabled',
        ])));

        $reglage->organization_id = $attributs['organization_id'];
        $reglage->loop_id = $attributs['loop_id'];
        $reglage->user_id = $attributs['user_id'];
        $reglage->save();

        return $reglage;
    }

    // =====================================================================
    // 1. Le modele : identite figee, tenant verifie
    // =====================================================================

    public function test_une_boucle_fraichement_rejointe_n_a_aucune_ligne(): void
    {
        $this->assertSame(
            0,
            LoopNotificationPreference::query()->forOwner((string) $this->membre->id)->count(),
            'Rejoindre une Boucle ne doit rien provisionner : le defaut produit EST l\'absence de ligne.'
        );
    }

    public function test_une_ligne_ne_peut_pas_nommer_une_organization_etrangere_a_sa_boucle(): void
    {
        $autreOrganization = Organization::factory()->create(['is_active' => true]);

        $this->expectException(RuntimeException::class);

        $this->creerReglage([
            'organization_id' => $autreOrganization->id,
            'loop_id' => $this->boucle->id,
            'user_id' => $this->membre->id,
            'collective_enabled' => false,
        ]);
    }

    public function test_l_identite_d_une_ligne_est_figee_apres_creation(): void
    {
        $ligne = $this->creerReglage([
            'organization_id' => $this->organization->id,
            'loop_id' => $this->boucle->id,
            'user_id' => $this->membre->id,
            'collective_enabled' => false,
        ]);

        $this->expectException(RuntimeException::class);

        // `loop_id` n'est pas fillable : `update()` l'ignorerait sans rien
        // muter, et le test ne verifierait alors rien. On mute directement
        // l'attribut, seul moyen de vraiment le salir avant `save()`.
        $autreBoucle = Loop::factory()->create(['organization_id' => $this->organization->id]);
        $ligne->loop_id = (string) $autreBoucle->id;
        $ligne->save();
    }

    public function test_seuls_les_trois_leviers_restent_modifiables(): void
    {
        $ligne = $this->creerReglage([
            'organization_id' => $this->organization->id,
            'loop_id' => $this->boucle->id,
            'user_id' => $this->membre->id,
            'collective_enabled' => true,
        ]);

        $ligne->update(['email_enabled' => true, 'web_push_enabled' => true]);

        $this->assertTrue($ligne->fresh()->email_enabled);
        $this->assertTrue($ligne->fresh()->web_push_enabled);
    }

    // =====================================================================
    // 2. Le resolveur : priorite Boucle > global > catalogue
    // =====================================================================

    public function test_sans_ligne_le_maitre_de_boucle_n_a_aucun_effet_sur_l_in_app(): void
    {
        $this->assertTrue($this->resolver()->allows(
            $this->membre,
            NotificationCatalogue::LOOP_HELP_REQUEST_CREATED,
            NotificationCatalogue::CHANNEL_IN_APP,
            (string) $this->boucle->id,
        ));
    }

    public function test_le_maitre_de_boucle_eteint_coupe_l_in_app(): void
    {
        $this->creerReglage([
            'organization_id' => $this->organization->id,
            'loop_id' => $this->boucle->id,
            'user_id' => $this->membre->id,
            'collective_enabled' => false,
        ]);

        $this->assertFalse($this->resolver()->allows(
            $this->membre,
            NotificationCatalogue::LOOP_HELP_REQUEST_CREATED,
            NotificationCatalogue::CHANNEL_IN_APP,
            (string) $this->boucle->id,
        ));
    }

    /** Le maitre coupe TOUT, meme un canal explicitement allume sur la meme ligne. */
    public function test_le_maitre_de_boucle_eteint_coupe_aussi_un_canal_allume(): void
    {
        $this->creerReglage([
            'organization_id' => $this->organization->id,
            'loop_id' => $this->boucle->id,
            'user_id' => $this->membre->id,
            'collective_enabled' => false,
            'web_push_enabled' => true,
        ]);

        $this->assertFalse($this->resolver()->allows(
            $this->membre,
            NotificationCatalogue::LOOP_HELP_REQUEST_CREATED,
            NotificationCatalogue::CHANNEL_WEB_PUSH,
            (string) $this->boucle->id,
        ));
    }

    public function test_le_maitre_de_boucle_allume_laisse_passer_l_in_app(): void
    {
        $this->creerReglage([
            'organization_id' => $this->organization->id,
            'loop_id' => $this->boucle->id,
            'user_id' => $this->membre->id,
            'collective_enabled' => true,
        ]);

        $this->assertTrue($this->resolver()->allows(
            $this->membre,
            NotificationCatalogue::LOOP_HELP_REQUEST_CREATED,
            NotificationCatalogue::CHANNEL_IN_APP,
            (string) $this->boucle->id,
        ));
    }

    public function test_une_ligne_par_boucle_gouverne_le_push_meme_sans_reglage_global(): void
    {
        $this->creerReglage([
            'organization_id' => $this->organization->id,
            'loop_id' => $this->boucle->id,
            'user_id' => $this->membre->id,
            'collective_enabled' => true,
            'web_push_enabled' => true,
        ]);

        $this->assertTrue($this->resolver()->allows(
            $this->membre,
            NotificationCatalogue::LOOP_HELP_REQUEST_CREATED,
            NotificationCatalogue::CHANNEL_WEB_PUSH,
            (string) $this->boucle->id,
        ));
    }

    /**
     * Sans ligne PAR Boucle, l'ecart GLOBAL historique continue de
     * gouverner : rien n'est perdu pour qui n'a pas encore touche a cette
     * Boucle individuellement.
     *
     * TASK-1775 (arbitrage Cyril, 07/11) — l'ecart pose ici est desormais
     * `false` et non `true` : le defaut du catalogue pour WEB_PUSH est
     * devenu `true`, donc un ecart global a `true` ne mesurerait plus rien
     * (il coinciderait avec le defaut). La direction qui reste signifiante
     * est l'inverse — un membre qui a globalement COUPE ce canal doit
     * rester coupe sur une Boucle qu'il n'a pas reglee individuellement.
     */
    public function test_sans_ligne_par_boucle_l_ecart_global_historique_continue_de_gouverner(): void
    {
        // `user_id` n'est pas fillable sur ce modele non plus (T1375) : pose
        // directement, comme le ferait le controleur historique.
        $ecartGlobal = new MemberNotificationPreference([
            'notification_key' => NotificationCatalogue::LOOP_HELP_REQUEST_CREATED,
            'channel' => NotificationCatalogue::CHANNEL_WEB_PUSH,
            'enabled' => false,
        ]);
        $ecartGlobal->user_id = (string) $this->membre->id;
        $ecartGlobal->save();

        $this->assertFalse($this->resolver()->allows(
            $this->membre,
            NotificationCatalogue::LOOP_HELP_REQUEST_CREATED,
            NotificationCatalogue::CHANNEL_WEB_PUSH,
            (string) $this->boucle->id,
        ), 'Le reglage global doit continuer a s\'appliquer a une Boucle jamais reglee individuellement.');
    }

    /** Une autre cle qui partage `OBJECT_LOOP` (`loop.member_added`) n'est JAMAIS gouvernee par l'interrupteur general de Boucle. */
    public function test_le_maitre_de_boucle_ne_coupe_jamais_une_cle_personnelle(): void
    {
        $this->creerReglage([
            'organization_id' => $this->organization->id,
            'loop_id' => $this->boucle->id,
            'user_id' => $this->membre->id,
            'collective_enabled' => false,
        ]);

        $this->assertTrue($this->resolver()->allows(
            $this->membre,
            NotificationCatalogue::LOOP_MEMBER_ADDED,
            NotificationCatalogue::CHANNEL_EMAIL,
            (string) $this->boucle->id,
        ), 'Une Boucle coupee collectivement ne doit jamais faire taire un fait personnel sur la meme Boucle.');
    }

    /** Quitter la Boucle neutralise le reglage sans l'effacer : il redevient inerte. */
    public function test_quitter_la_boucle_neutralise_le_reglage_sans_l_effacer(): void
    {
        $this->creerReglage([
            'organization_id' => $this->organization->id,
            'loop_id' => $this->boucle->id,
            'user_id' => $this->membre->id,
            'collective_enabled' => false,
        ]);

        LoopMember::query()
            ->where('loop_id', $this->boucle->id)
            ->where('user_id', $this->membre->id)
            ->update(['status' => 'left']);

        $this->assertTrue($this->resolver()->allows(
            $this->membre,
            NotificationCatalogue::LOOP_HELP_REQUEST_CREATED,
            NotificationCatalogue::CHANNEL_IN_APP,
            (string) $this->boucle->id,
        ), 'Une Boucle quittee ne doit plus etre coupee par un reglage laisse derriere soi.');

        $this->assertSame(
            1,
            LoopNotificationPreference::query()->forOwner((string) $this->membre->id)->count(),
            'La ligne reste en base : elle redevient active si la personne rejoint la Boucle.'
        );
    }

    // =====================================================================
    // 3. L'ecran : « Mes Boucles »
    // =====================================================================

    public function test_l_ecran_liste_les_boucles_dont_on_est_membre_actif(): void
    {
        $this->actingAs($this->membre)->get(route('notifications.preferences.edit'))
            ->assertOk()
            ->assertSee('data-loop-preference="'.$this->boucle->id.'"', false)
            ->assertSee($this->boucle->name);
    }

    /**
     * TASK-1775 (arbitrage Cyril, 07/11) — une Boucle jamais reglee affiche
     * desormais email ET push COCHES (opt-OUT), et l'in-app comme un badge
     * verrouille plutot qu'une case.
     */
    public function test_une_boucle_jamais_reglee_affiche_email_et_push_coches_et_l_in_app_verrouille(): void
    {
        $html = $this->actingAs($this->membre)->get(route('notifications.preferences.edit'))
            ->assertOk()
            ->getContent();

        // Aucune case "collective" n'est plus postee : l'in-app est un badge
        // verrouille, pas un champ de formulaire.
        $this->assertStringNotContainsString('[collective]', $html);
        $this->assertStringContainsString('data-loop-channel="'.$this->boucle->id.':collective"', $html);
        $this->assertStringContainsString('data-preference-locked', $html);

        foreach (['email', 'web_push'] as $levier) {
            $motif = '/name="boucles\['.preg_quote((string) $this->boucle->id, '/').'\]\['.$levier.'\]"[^>]*checked/';
            $this->assertMatchesRegularExpression($motif, $html, "{$levier} doit etre coche par defaut.");
        }
    }

    public function test_l_ecran_ignore_une_boucle_d_une_autre_organization(): void
    {
        $autreOrganization = Organization::factory()->create(['is_active' => true]);
        $boucleEtrangere = Loop::factory()->create(['organization_id' => $autreOrganization->id]);

        $this->actingAs($this->membre)->get(route('notifications.preferences.edit'))
            ->assertOk()
            ->assertDontSee('data-loop-preference="'.$boucleEtrangere->id.'"', false);
    }

    public function test_l_ecran_ignore_une_boucle_quittee(): void
    {
        $boucleQuittee = Loop::factory()->create(['organization_id' => $this->organization->id]);
        LoopMember::create([
            'loop_id' => $boucleQuittee->id,
            'user_id' => $this->membre->id,
            'organization_id' => $this->organization->id,
            'role' => 'member',
            'status' => 'left',
            'joined_at' => now(),
        ]);

        $this->actingAs($this->membre)->get(route('notifications.preferences.edit'))
            ->assertOk()
            ->assertDontSee('data-loop-preference="'.$boucleQuittee->id.'"', false);
    }

    /** CDC B2 — le message EXACT, et un chemin vers l'annuaire des Boucles. */
    public function test_sans_aucune_boucle_l_ecran_invite_a_en_rejoindre_une(): void
    {
        $sansBoucle = User::factory()->create(['organization_id' => $this->organization->id]);

        $reponse = $this->actingAs($sansBoucle)->get(route('notifications.preferences.edit'))
            ->assertOk()
            ->assertSee("Vous n'avez pas encore rejoint de Boucle. Rejoignez-en une pour participer aux entraides et recevoir les demandes d'aide.")
            ->assertSee('Rejoindre une boucle');

        $reponse->assertSee('href="'.route('loops.index').'"', false);
    }

    /**
     * TASK-1775 (arbitrage Cyril, 07/11) — ce test s'appelait
     * `test_un_membre_peut_couper_une_boucle_depuis_l_ecran` : l'ecran n'a
     * plus de case "collective" a cocher (l'in-app est obligatoire), donc ce
     * n'est plus email/push qui la gouvernent plus — c'est desormais EUX,
     * et EUX SEULS, que l'ecran peut couper.
     */
    public function test_un_membre_peut_couper_email_et_push_depuis_l_ecran(): void
    {
        $this->actingAs($this->membre)
            ->post(route('notifications.preferences.loops.update'), [
                'boucles' => [
                    (string) $this->boucle->id => ['email' => '0', 'web_push' => '0'],
                ],
            ])
            ->assertRedirect();

        $ligne = LoopNotificationPreference::query()->forOwner((string) $this->membre->id)->first();

        $this->assertNotNull($ligne);
        $this->assertFalse($ligne->email_enabled);
        $this->assertFalse($ligne->web_push_enabled);
    }

    /**
     * Une valeur `collective` postee — forgee, puisque l'ecran n'en envoie
     * plus — est IGNOREE : le controleur pose toujours `true`, jamais la
     * valeur recue.
     */
    public function test_une_valeur_collective_forgee_est_ignoree(): void
    {
        $this->actingAs($this->membre)
            ->post(route('notifications.preferences.loops.update'), [
                'boucles' => [
                    (string) $this->boucle->id => ['collective' => '0', 'email' => '0', 'web_push' => '0'],
                ],
            ]);

        $ligne = LoopNotificationPreference::query()->forOwner((string) $this->membre->id)->firstOrFail();

        $this->assertTrue($ligne->collective_enabled, 'Le maitre n\'est plus un levier pilote par la requete.');
    }

    /**
     * Rallumer efface l'ecart au lieu d'en stocker un second — meme doctrine
     * que T1744.
     *
     * TASK-1775 (arbitrage Cyril, 07/11) — le defaut auquel on revient est
     * desormais (collectif actif, email actif, push actif) : opt-OUT, pas
     * opt-IN.
     */
    public function test_revenir_au_defaut_efface_la_ligne(): void
    {
        $this->creerReglage([
            'organization_id' => $this->organization->id,
            'loop_id' => $this->boucle->id,
            'user_id' => $this->membre->id,
            'collective_enabled' => true,
            'email_enabled' => false,
            'web_push_enabled' => false,
        ]);

        $this->actingAs($this->membre)
            ->post(route('notifications.preferences.loops.update'), [
                'boucles' => [
                    (string) $this->boucle->id => ['email' => '1', 'web_push' => '1'],
                ],
            ]);

        $this->assertSame(
            0,
            LoopNotificationPreference::query()->forOwner((string) $this->membre->id)->count(),
            'Revenir exactement au defaut doit supprimer la ligne, pas la conserver a l\'identique.'
        );
    }

    /** Un identifiant de Boucle etranger au tenant — ou forge — est ignore, jamais ecrit. */
    public function test_une_boucle_etrangere_postee_est_ignoree(): void
    {
        $autreOrganization = Organization::factory()->create(['is_active' => true]);
        $boucleEtrangere = Loop::factory()->create(['organization_id' => $autreOrganization->id]);

        $this->actingAs($this->membre)
            ->post(route('notifications.preferences.loops.update'), [
                'boucles' => [
                    (string) $boucleEtrangere->id => ['collective' => '0'],
                ],
            ])
            ->assertRedirect();

        $this->assertSame(
            0,
            LoopNotificationPreference::query()->where('loop_id', $boucleEtrangere->id)->count(),
            'Une Boucle hors du tenant resolu ne doit jamais recevoir de ligne.'
        );
    }

    /** Connaitre l'identifiant d'une Boucle n'est pas un droit d'y regler quoi que ce soit pour un AUTRE membre. */
    public function test_un_membre_ne_peut_pas_regler_la_boucle_pour_un_autre(): void
    {
        $autreMembre = User::factory()->create(['organization_id' => $this->organization->id]);
        LoopMember::create([
            'loop_id' => $this->boucle->id,
            'user_id' => $autreMembre->id,
            'organization_id' => $this->organization->id,
            'role' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->actingAs($this->membre)
            ->post(route('notifications.preferences.loops.update'), [
                'boucles' => [
                    (string) $this->boucle->id => ['collective' => '0'],
                ],
            ]);

        $this->assertNull(
            LoopNotificationPreference::query()->forOwner((string) $autreMembre->id)->first(),
            'La ligne posee par Alice ne doit jamais porter l\'identifiant de Bob.'
        );
    }

    public function test_les_deux_langues_portent_les_memes_cles_de_boucle(): void
    {
        $fr = require lang_path('fr/notifications.php');
        $en = require lang_path('en/notifications.php');

        foreach (array_keys($fr['loops']) as $cle) {
            $this->assertArrayHasKey($cle, $en['loops'], "[loops.{$cle}] manque en anglais.");
            $this->assertNotSame('', trim((string) $en['loops'][$cle]), "[loops.{$cle}] est vide en anglais.");
        }
    }
}
