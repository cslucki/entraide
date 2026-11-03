<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Support\Notifications\NotificationCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Lang;
use Tests\TestCase;

/**
 * TASK-1746 (CDC §7A) — les préférences regroupées par INTENTION.
 *
 * ## Ce que cette suite défend
 *
 * Le regroupement est une lecture, pas un moteur : il ne doit RIEN changer à
 * ce qui s'enregistre. Les deux moitiés comptent donc, et aucune ne suffit
 * seule — l'écran est mieux rangé, et le comportement est identique au
 * caractère près.
 *
 * ## Pourquoi une intention plutôt que `category`
 *
 * `category` existait déjà et ne prend que deux valeurs, `loop` et `blog` :
 * des domaines d'objet. En dériver les sections donnerait « Boucles » et
 * « Blog », un classement par technique — et dès le §8.1 une demande d'aide
 * serait rangée à côté d'une invitation, alors que l'une appelle une réponse
 * et l'autre informe.
 */
class TASK1746PreferenceIntentionsTest extends TestCase
{
    use RefreshDatabase;

    private User $membre;

    protected function setUp(): void
    {
        parent::setUp();

        $organization = Organization::factory()->create(['is_active' => true]);
        $this->membre = User::factory()->create(['organization_id' => $organization->id]);
    }

    public function test_toute_cle_du_catalogue_declare_une_intention_CONNUE(): void
    {
        // LA garde de cette TASK, et elle est fail-closed par construction.
        //
        // Sans elle, une clé ajoutée demain sans intention disparaîtrait
        // simplement de l'écran : pas d'erreur, pas de trace, juste un
        // réglage que personne ne peut plus atteindre. C'est exactement la
        // classe de défaut que le catalogue combat partout ailleurs.
        foreach (NotificationCatalogue::keys() as $cle) {
            $intention = NotificationCatalogue::intentionFor($cle);

            $this->assertNotNull(
                $intention,
                "[{$cle}] ne déclare aucune intention : son réglage serait invisible à l'écran.",
            );

            $this->assertContains(
                $intention,
                NotificationCatalogue::INTENTIONS,
                "[{$cle}] déclare une intention inconnue : « {$intention} ».",
            );
        }
    }

    public function test_chaque_cle_tombe_dans_EXACTEMENT_une_section(): void
    {
        // Ni perdue, ni comptée deux fois. On compare des ensembles plutôt
        // que des totaux : un doublon et un oubli qui se compensent
        // passeraient un simple décompte.
        $classees = array_merge(...array_values(NotificationCatalogue::keysByIntention()));

        sort($classees);
        $attendues = NotificationCatalogue::keys();
        sort($attendues);

        $this->assertSame($attendues, $classees);
        $this->assertSame(count($classees), count(array_unique($classees)), 'une clé est classée deux fois');
    }

    public function test_les_libelles_de_section_existent_dans_les_DEUX_locales(): void
    {
        // Dérivés des intentions DÉCLARÉES, pas d'une liste recopiée : une
        // intention ajoutée sans libellé rougit ici. Leçon de T1745, où un
        // test vérifiait sa propre liste pendant que la vue en lisait une
        // autre.
        foreach (['fr', 'en'] as $locale) {
            foreach (NotificationCatalogue::INTENTIONS as $intention) {
                foreach (['title', 'hint'] as $champ) {
                    $this->assertTrue(
                        Lang::has("notifications.intentions.{$intention}.{$champ}", $locale),
                        "[{$locale}] libellé manquant : notifications.intentions.{$intention}.{$champ}",
                    );
                }
            }
        }
    }

    public function test_l_ecran_rend_une_section_par_intention_NON_VIDE(): void
    {
        $reponse = $this->actingAs($this->membre)->get('/notifications/preferences')->assertOk();

        foreach (NotificationCatalogue::keysByIntention() as $intention => $cles) {
            $marqueur = 'data-preference-intention="'.$intention.'"';

            if ($cles === []) {
                // Une section vide est un titre qui ne tient rien. La
                // sollicitation pertinente est dans ce cas jusqu'au §9.2 :
                // elle est DÉCLARÉE mais ne doit pas s'afficher.
                $reponse->assertDontSee($marqueur, false);

                continue;
            }

            $reponse->assertSee($marqueur, false);
        }
    }

    public function test_les_ANCRES_de_test_existantes_sont_PRESERVEES(): void
    {
        // Contrainte dure de la TASK. Le banc navigateur de T1744 localise
        // les lignes par `data-preference-channel`, et la suite PHP par
        // `data-preference-key`. Le regroupement ajoute un niveau AUTOUR ;
        // renommer ces ancres ferait rougir une TASK déjà fusionnée pour une
        // raison purement cosmétique.
        $reponse = $this->actingAs($this->membre)->get('/notifications/preferences')->assertOk();

        foreach (NotificationCatalogue::keys() as $cle) {
            $reponse->assertSee('data-preference-key="'.$cle.'"', false);

            foreach (NotificationCatalogue::channelsFor($cle) as $canal) {
                $reponse->assertSee('data-preference-channel="'.$cle.':'.$canal.'"', false);
            }
        }
    }

    public function test_le_regroupement_ne_change_RIEN_a_ce_qui_s_enregistre(): void
    {
        // La moitié qui compte vraiment. Un regroupement est une mise en
        // page ; si l'enregistrement bougeait, ce ne serait plus du §7A.
        //
        // On éteint un canal configurable, et on vérifie que l'écart est
        // écrit — puis qu'un retour au défaut SUPPRIME la ligne au lieu de
        // l'écrire à la valeur du défaut. C'est la doctrine « seuls les
        // écarts sont stockés », et elle ne doit pas avoir bougé.
        [$cle, $canal] = $this->premierCanalConfigurable();

        $defaut = NotificationCatalogue::channelDefault($cle, $canal);

        $this->actingAs($this->membre)
            ->post('/notifications/preferences', ['canaux' => [$cle => [$canal => $defaut ? '0' : '1']]])
            ->assertRedirect();

        $this->assertDatabaseHas('member_notification_preferences', [
            'user_id' => $this->membre->id,
            'notification_key' => $cle,
            'channel' => $canal,
            'enabled' => ! $defaut,
        ]);

        $this->actingAs($this->membre)
            ->post('/notifications/preferences', ['canaux' => [$cle => [$canal => $defaut ? '1' : '0']]])
            ->assertRedirect();

        $this->assertDatabaseMissing('member_notification_preferences', [
            'user_id' => $this->membre->id,
            'notification_key' => $cle,
            'channel' => $canal,
        ]);
    }

    public function test_un_canal_NON_configurable_reste_affiche_et_verrouille(): void
    {
        // Le regroupement ne doit pas transformer un canal obligatoire en
        // case à cocher — ni le faire disparaître sous prétexte qu'il n'est
        // pas réglable. Il reste visible, en badge.
        $reponse = $this->actingAs($this->membre)->get('/notifications/preferences')->assertOk();

        $trouve = false;

        foreach (NotificationCatalogue::keys() as $cle) {
            foreach (NotificationCatalogue::channelsFor($cle) as $canal) {
                if (NotificationCatalogue::channelIsConfigurable($cle, $canal)) {
                    continue;
                }

                $trouve = true;
                $reponse->assertSee('data-preference-locked="'.$cle.':'.$canal.'"', false);
            }
        }

        $this->assertTrue($trouve, 'aucun canal obligatoire au catalogue : ce test ne mesure rien');
    }

    /** @return array{0: string, 1: string} */
    private function premierCanalConfigurable(): array
    {
        foreach (NotificationCatalogue::keys() as $cle) {
            foreach (NotificationCatalogue::channelsFor($cle) as $canal) {
                if (NotificationCatalogue::channelIsConfigurable($cle, $canal)) {
                    return [$cle, $canal];
                }
            }
        }

        $this->fail('aucun canal configurable au catalogue');
    }
}
