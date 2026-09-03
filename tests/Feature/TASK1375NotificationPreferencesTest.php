<?php

namespace Tests\Feature;

use App\Models\MemberNotificationPreference;
use App\Models\Organization;
use App\Models\User;
use App\Support\Notifications\NotificationCatalogue;
use App\Support\Notifications\NotificationPreferenceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * TASK-1375 — les reglages de notification.
 *
 * ## Ce qui est reellement en jeu
 *
 * Le CDC ne demande pas seulement un ecran. Il demande que **la securite ne
 * dependre pas de l'absence de bouton** :
 *
 * > Pour `configurable: false`, toute preference stockee contradictoire est
 * > IGNOREE par le resolver.
 *
 * Autrement dit : meme une ligne ecrite directement en base, contournant tout
 * chemin applicatif, ne doit pas pouvoir eteindre un canal obligatoire. C'est le
 * test central de ce fichier, et le seul qui protege le jour ou un canal
 * deviendra obligatoire APRES que des membres l'auront regle.
 */
class TASK1375NotificationPreferencesTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $alice;

    private User $bob;

    private NotificationPreferenceResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['name' => 'T1375 Organization', 'is_active' => true]);
        $this->alice = User::factory()->create(['organization_id' => $this->org->id]);
        $this->bob = User::factory()->create(['organization_id' => $this->org->id]);

        $this->resolver = app(NotificationPreferenceResolver::class);
    }

    // =====================================================================
    // 1. Le catalogue fait autorite
    // =====================================================================

    /** Sans aucune ligne en base, c'est le defaut du catalogue qui gouverne. */
    public function test_without_any_row_the_catalogue_default_governs(): void
    {
        $this->assertSame(0, MemberNotificationPreference::query()->count());

        $this->assertTrue(
            $this->resolver->allows($this->alice, NotificationCatalogue::LOOP_INVITATION, NotificationCatalogue::CHANNEL_IN_APP)
        );
    }

    /** Un canal que la cle n'autorise pas est refuse, fail-closed. */
    public function test_a_channel_the_key_does_not_allow_is_refused(): void
    {
        $this->assertFalse(
            $this->resolver->allows($this->alice, NotificationCatalogue::LOOP_INVITATION, 'email')
        );
    }

    /** Une cle absente du catalogue n'existe pas. */
    public function test_a_key_absent_from_the_catalogue_does_not_exist(): void
    {
        $this->assertFalse(
            $this->resolver->allows($this->alice, 'loop.something_invented', NotificationCatalogue::CHANNEL_IN_APP)
        );
    }

    // =====================================================================
    // 2. LE test central — une ligne en base ne neutralise pas l'obligatoire
    // =====================================================================

    /**
     * **Une preference ecrite DIRECTEMENT en base est ignoree sur un canal
     * obligatoire.**
     *
     * On contourne volontairement toutes les gardes applicatives avec un
     * `DB::table()->insert()` : c'est exactement l'etat que produirait un
     * import, une migration, une base heritee — ou une cle devenue obligatoire
     * APRES que des membres l'aient reglee.
     *
     * Ce dernier cas n'a rien de theorique : le jour ou un canal passe de
     * configurable a obligatoire, **toutes** les lignes existantes deviennent
     * contradictoires d'un coup, et doivent cesser d'avoir un effet le jour
     * meme, sans migration de donnees.
     */
    public function test_a_row_written_straight_into_the_database_cannot_disable_a_mandatory_channel(): void
    {
        DB::table('member_notification_preferences')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $this->alice->id,
            'notification_key' => NotificationCatalogue::LOOP_INVITATION,
            'channel' => NotificationCatalogue::CHANNEL_IN_APP,
            'enabled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(1, MemberNotificationPreference::query()->count(), 'La ligne est bien la.');

        $this->assertTrue(
            $this->resolver->allows($this->alice, NotificationCatalogue::LOOP_INVITATION, NotificationCatalogue::CHANNEL_IN_APP),
            'Un canal obligatoire reste actif malgre une ligne contraire en base.'
        );
    }

    /** Et le chemin applicatif refuse d'ecrire un tel ecart, en amont. */
    public function test_the_application_path_refuses_to_store_such_an_override(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is mandatory and cannot be overridden');

        $p = new MemberNotificationPreference([
            'notification_key' => NotificationCatalogue::LOOP_INVITATION,
            'channel' => NotificationCatalogue::CHANNEL_IN_APP,
            'enabled' => false,
        ]);
        $p->user_id = (string) $this->alice->id;
        $p->save();
    }

    // =====================================================================
    // 3. Le proprietaire ne vient jamais du client
    // =====================================================================

    /**
     * **`user_id` n'est pas affectable en masse.**
     *
     * Une page de reglages manipule des donnees venues du client. Si le
     * proprietaire etait fillable, un `create($request->validated())` un peu
     * rapide laisserait n'importe qui ecrire les reglages de n'importe qui.
     */
    public function test_the_owner_is_not_mass_assignable(): void
    {
        $p = new MemberNotificationPreference([
            'user_id' => $this->bob->id,
            'notification_key' => NotificationCatalogue::LOOP_INVITATION,
            'channel' => NotificationCatalogue::CHANNEL_IN_APP,
            'enabled' => true,
        ]);

        $this->assertNull($p->user_id, 'user_id doit rester hors de $fillable.');
    }

    /** L'identite d'un reglage est figee : elle ne se corrige pas, elle se remplace. */
    public function test_a_preference_identity_is_frozen(): void
    {
        $p = $this->ecartBrut($this->alice, false);

        // `notification_key` et `channel` sont fillable ; `user_id` ne l'est pas
        // et serait silencieusement ignore par `update()`. On le mute donc
        // directement, sinon le test ne mesurerait rien pour cette colonne.
        foreach (['notification_key' => 'autre.cle', 'channel' => 'email'] as $colonne => $valeur) {
            $refus = $this->refusDeMutation(fn () => $p->fresh()->update([$colonne => $valeur]));

            $this->assertStringContainsString('identity is frozen', $refus);
            $this->assertStringContainsString($colonne, $refus);
        }

        $refus = $this->refusDeMutation(function () use ($p) {
            $ligne = $p->fresh();
            $ligne->user_id = (string) $this->bob->id;
            $ligne->save();
        });

        $this->assertStringContainsString('user_id', $refus);
        $this->assertSame((string) $this->alice->id, (string) $p->fresh()->user_id);
    }

    // =====================================================================
    // 4. L'ecran
    // =====================================================================

    /** L'ecran montre le canal obligatoire, SANS bouton. */
    public function test_the_screen_shows_a_mandatory_channel_without_a_toggle(): void
    {
        $cible = NotificationCatalogue::LOOP_INVITATION.':'.NotificationCatalogue::CHANNEL_IN_APP;

        $this->actingAs($this->alice)->get(route('notifications.preferences.edit'))
            ->assertOk()
            ->assertSee('data-preference-mandatory', false)
            ->assertSee('data-preference-locked="'.$cible.'"', false)
            ->assertDontSee('name="canaux['.NotificationCatalogue::LOOP_INVITATION.']', false);
    }

    /** Un invite n'a pas de reglages. */
    public function test_a_guest_is_sent_to_the_login_page(): void
    {
        $this->get(route('notifications.preferences.edit'))->assertRedirect(route('login'));
    }

    /**
     * **Poster un canal obligatoire n'ecrit rien.**
     *
     * Le client peut envoyer ce qu'il veut : l'ecran ne rend pas de champ pour
     * un canal obligatoire, mais rien n'empeche de forger la requete. Chaque
     * couple est confronte au catalogue, et ce qui vise un canal obligatoire est
     * ignore sans bruit — une valeur inconnue n'est pas une erreur a signaler,
     * c'est une valeur a ne pas suivre.
     */
    public function test_posting_a_mandatory_channel_writes_nothing(): void
    {
        $this->actingAs($this->alice)
            ->from(route('notifications.preferences.edit'))
            ->post(route('notifications.preferences.update'), [
                'canaux' => [
                    NotificationCatalogue::LOOP_INVITATION => [
                        NotificationCatalogue::CHANNEL_IN_APP => '0',
                    ],
                ],
            ])
            ->assertRedirect();

        $this->assertSame(0, MemberNotificationPreference::query()->count());
        $this->assertTrue(
            $this->resolver->allows($this->alice, NotificationCatalogue::LOOP_INVITATION, NotificationCatalogue::CHANNEL_IN_APP)
        );
    }

    /** Une cle inventee dans la requete n'ecrit rien non plus. */
    public function test_posting_an_invented_key_writes_nothing(): void
    {
        $this->actingAs($this->alice)
            ->from(route('notifications.preferences.edit'))
            ->post(route('notifications.preferences.update'), [
                'canaux' => ['loop.something_invented' => ['email' => '1']],
            ])
            ->assertRedirect();

        $this->assertSame(0, MemberNotificationPreference::query()->count());
    }

    /** Et une charge malformee ne fait pas tomber l'ecran. */
    public function test_a_malformed_payload_does_not_break_the_screen(): void
    {
        foreach (['pas-un-tableau', ['loop.invitation' => 'pas-un-tableau']] as $bidon) {
            $this->actingAs($this->alice)
                ->from(route('notifications.preferences.edit'))
                ->post(route('notifications.preferences.update'), ['canaux' => $bidon])
                ->assertRedirect();
        }

        $this->assertSame(0, MemberNotificationPreference::query()->count());
    }

    // =====================================================================
    // 5. Les deux langues
    // =====================================================================

    public function test_the_screen_speaks_french(): void
    {
        $this->alice->forceFill(['preferred_locale' => 'fr'])->saveQuietly();

        $this->actingAs($this->alice)->get(route('notifications.preferences.edit'))
            ->assertOk()
            ->assertSee('Réglages des notifications')
            ->assertSee('Toujours active');
    }

    public function test_the_screen_speaks_english(): void
    {
        $this->alice->forceFill(['preferred_locale' => 'en'])->saveQuietly();

        $this->actingAs($this->alice)->get(route('notifications.preferences.edit'))
            ->assertOk()
            ->assertSee('Notification settings')
            ->assertSee('Always on');
    }

    /** Les deux fichiers de langue portent exactement les memes cles. */
    public function test_both_languages_carry_the_same_keys(): void
    {
        $aplatir = function (array $t, string $prefixe = '') use (&$aplatir): array {
            $plat = [];
            foreach ($t as $c => $v) {
                $chemin = $prefixe === '' ? (string) $c : $prefixe.'.'.$c;
                $plat = is_array($v) ? $plat + $aplatir($v, $chemin) : $plat + [$chemin => $v];
            }

            return $plat;
        };

        $fr = $aplatir(require lang_path('fr/notifications.php'));
        $en = $aplatir(require lang_path('en/notifications.php'));

        foreach ($fr as $cle => $valeur) {
            $this->assertArrayHasKey($cle, $en, "[{$cle}] manque en anglais.");
            $this->assertNotSame('', trim((string) $en[$cle]), "[{$cle}] est vide en anglais.");
        }
        foreach ($en as $cle => $valeur) {
            $this->assertArrayHasKey($cle, $fr, "[{$cle}] manque en francais.");
        }
    }

    // =====================================================================
    // Helper
    // =====================================================================

    /**
     * Execute une mutation attendue en echec et rend son message.
     *
     * `$this->fail()` leve une `AssertionFailedError`, qui DESCEND de
     * `RuntimeException` : un `try { … $this->fail(); } catch (RuntimeException)`
     * avale donc sa propre assertion. Pire ici, le message de `fail()` contenait
     * le nom de la colonne, donc l'assertion suivante passait AUSSI — le test
     * etait entierement auto-realisateur. Defaut deja rencontre en T1372 et
     * corrige en T1373 ; refait ici, et attrape par le sabotage.
     */
    private function refusDeMutation(callable $mutation): string
    {
        try {
            $mutation();
        } catch (RuntimeException|InvalidArgumentException $e) {
            return $e->getMessage();
        }

        $this->fail('La mutation aurait du etre refusee.');
    }

    /**
     * Une ligne posee SANS passer par les gardes applicatives.
     *
     * Necessaire pour simuler un etat qu'aucun chemin legitime ne produit —
     * import, base heritee, ou canal devenu obligatoire apres coup.
     */
    private function ecartBrut(User $user, bool $enabled): MemberNotificationPreference
    {
        $id = (string) Str::uuid();

        DB::table('member_notification_preferences')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'notification_key' => NotificationCatalogue::LOOP_INVITATION,
            'channel' => NotificationCatalogue::CHANNEL_IN_APP,
            'enabled' => $enabled,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return MemberNotificationPreference::findOrFail($id);
    }
}
