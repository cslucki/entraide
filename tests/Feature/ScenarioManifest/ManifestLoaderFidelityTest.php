<?php

namespace Tests\Feature\ScenarioManifest;

use App\Models\LoopDecision;
use App\Models\LoopEvent;
use App\Models\LoopEventResponse;
use App\Models\LoopMessage;
use App\Models\LoopPoll;
use App\Models\LoopPollVote;
use App\Models\User;
use App\Support\ScenarioManifest\ManifestAvatarBank;
use App\Support\ScenarioManifest\ManifestSchema;
use App\Support\ScenarioManifest\ScenarioManifestValidator;
use App\Support\ScenarioPacks\Manifest\ManifestSandboxLoadResult;
use App\Support\ScenarioPacks\Manifest\ManifestSandboxLoadService;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ScenarioManifest\AmtReferenceManifest;
use Tests\TestCase;

/**
 * TASK-1647 — le monde charge doit etre celui que le manifeste DECLARE.
 *
 * Quatre ecarts etaient documentes (CDC Manager 33.1) : un poll declare
 * `closed` arrivait ouvert, un event declare `cancelled` arrivait actif,
 * `decision.message` etait perdu, un avatar declare n'etait jamais ecrit.
 *
 * Cette suite ne se contente pas de verifier qu'un chargement reussit : pour
 * chaque ecart elle lit l'ETAT REEL en base — la colonne, l'horodatage,
 * l'auteur de la transition, la relation, le fichier sur le disque — et elle
 * verifie AUSSI le cas negatif, sans quoi une correction qui fermerait TOUT
 * passerait pour une reussite.
 *
 * Le rejeu est teste partout ou il peut mentir : une transition rejouee, un
 * message duplique ou un asset reecrit seraient des defauts silencieux.
 */
class ManifestLoaderFidelityTest extends TestCase
{
    private function digestOf(string $json): string
    {
        return (string) (new ScenarioManifestValidator)->validate($json)->digest();
    }

    private function loadJson(string $json): ManifestSandboxLoadResult
    {
        return app(ManifestSandboxLoadService::class)->load($json, $this->digestOf($json));
    }

    private function load(): ManifestSandboxLoadResult
    {
        return $this->loadJson(AmtReferenceManifest::json());
    }

    /**
     * La fixture declare un poll `open` et un event `scheduled` : pour eprouver
     * la cloture et l'annulation, il faut donc muter le document. On mute la
     * SEULE valeur visee, et le digest est recalcule — un manifeste mute reste
     * un manifeste valide, sinon le Load le refuserait.
     */
    private function loadWith(callable $mutation): ManifestSandboxLoadResult
    {
        return $this->loadJson(AmtReferenceManifest::mutate($mutation));
    }

    // =====================================================================
    // 1. Un poll declare `closed` est REELLEMENT clos
    // =====================================================================

    public function test_un_poll_declare_open_reste_ouvert(): void
    {
        // Le cas negatif d'abord : sans lui, une correction qui clorait tous
        // les sondages passerait pour une reussite.
        $this->load();

        $poll = LoopPoll::query()->firstOrFail();

        $this->assertSame('open', $poll->status);
        $this->assertNull($poll->closed_at);
        $this->assertNull($poll->closed_by);
    }

    public function test_un_poll_declare_closed_est_reellement_clos(): void
    {
        $this->loadWith(static function (\stdClass $m): void {
            $m->polls[0]->status = 'closed';
        });

        $poll = LoopPoll::query()->firstOrFail();

        $this->assertSame('closed', $poll->status);
        $this->assertNotNull($poll->closed_at, 'La cloture doit etre horodatee.');
        $this->assertNotNull($poll->closed_by, 'La cloture doit porter son auteur.');
    }

    public function test_la_cloture_intervient_APRES_les_votes_declares(): void
    {
        // L'ordre n'est pas un detail : un sondage clos n'accepte plus de
        // voix. Clore avant de voter ferait disparaitre les votes declares, et
        // le monde charge serait faux dans l'autre sens.
        $this->loadWith(static function (\stdClass $m): void {
            $m->polls[0]->status = 'closed';
        });

        $poll = LoopPoll::query()->firstOrFail();

        $this->assertSame('closed', $poll->status);
        $this->assertSame(
            2,
            LoopPollVote::query()->where('poll_id', $poll->id)->count(),
            'Les deux votes declares doivent avoir ete enregistres malgre la cloture.'
        );
    }

    public function test_la_cloture_est_close_par_l_auteur_declare(): void
    {
        $this->loadWith(static function (\stdClass $m): void {
            $m->polls[0]->status = 'closed';
        });

        $poll = LoopPoll::query()->firstOrFail();

        // L'auteur du sondage dans la fixture. On compare des IDENTITES, pas
        // une valeur prise dans le code teste.
        $this->assertSame(
            $poll->created_by,
            $poll->closed_by,
            'La spec dit « clos par l auteur lors du chargement ».'
        );
    }

    // =====================================================================
    // 2. Un event declare `cancelled` est REELLEMENT annule
    // =====================================================================

    public function test_un_event_declare_scheduled_reste_actif(): void
    {
        $this->load();

        $event = LoopEvent::query()->firstOrFail();

        $this->assertSame('scheduled', $event->status);
        $this->assertNull($event->cancelled_at);
        $this->assertNull($event->cancelled_by);
    }

    public function test_un_event_declare_cancelled_est_reellement_annule(): void
    {
        $this->loadWith(static function (\stdClass $m): void {
            $m->events[0]->status = 'cancelled';
        });

        $event = LoopEvent::query()->firstOrFail();

        $this->assertSame('cancelled', $event->status);
        $this->assertNotNull($event->cancelled_at);
        $this->assertNotNull($event->cancelled_by);
    }

    public function test_l_annulation_intervient_APRES_les_reponses_declarees(): void
    {
        // Meme raison que pour les sondages : la spec dit que « les reponses
        // sont appliquees AVANT une eventuelle annulation ».
        $this->loadWith(static function (\stdClass $m): void {
            $m->events[0]->status = 'cancelled';
        });

        $event = LoopEvent::query()->firstOrFail();

        $this->assertSame('cancelled', $event->status);
        $this->assertSame(
            2,
            LoopEventResponse::query()->where('event_id', $event->id)->count(),
            'Les deux reponses declarees doivent survivre a l annulation.'
        );
    }

    // =====================================================================
    // 3. `decision.message` resout vers le VRAI message
    // =====================================================================

    public function test_une_decision_declarant_un_message_lui_est_reellement_reliee(): void
    {
        $this->load();

        $decision = LoopDecision::query()->firstOrFail();

        $this->assertNotNull(
            $decision->loop_message_id,
            'La fixture declare `message: trainer-answer` : la reference doit etre materialisee.'
        );

        $message = LoopMessage::query()->findOrFail($decision->loop_message_id);

        // Le message doit etre celui de la MEME Loop : une decision reliee a
        // la conversation d'une autre Boucle serait une fuite.
        $this->assertSame($decision->loop_id, $message->loop_id);
    }

    public function test_aucun_second_message_n_est_invente_pour_satisfaire_la_reference(): void
    {
        // Le piege evident serait de creer un message pour remplir la FK :
        // cela inventerait une parole que personne n'a prononcee.
        $this->load();

        $this->assertSame(
            4,
            LoopMessage::query()->count(),
            'La fixture declare quatre messages, et le lien de decision n en ajoute aucun.'
        );
    }

    public function test_une_decision_sans_message_declare_reste_sans_message(): void
    {
        $this->loadWith(static function (\stdClass $m): void {
            $m->decisions[0]->message = null;
        });

        $decision = LoopDecision::query()->firstOrFail();

        $this->assertNull($decision->loop_message_id);
    }

    // =====================================================================
    // 4. Un avatar declare est REELLEMENT ecrit
    // =====================================================================

    public function test_un_avatar_declare_est_ecrit_et_son_asset_existe(): void
    {
        $organization = $this->load()->organization;

        $user = User::query()
            ->where('organization_id', $organization->id)
            ->whereNotNull('avatar')
            ->firstOrFail();

        $attendu = 'scenario-avatars/'.ManifestSchema::AVATAR_BANK.'/';

        $this->assertStringStartsWith($attendu, $user->avatar);
        $this->assertStringEndsWith('.svg', $user->avatar);

        // Le chemin ne vaut rien si le fichier n'est pas servable.
        $this->assertTrue(
            Storage::disk('public')->exists($user->avatar),
            'L asset doit avoir ete publie sur le disque, pas seulement reference.'
        );
    }

    public function test_chaque_cle_declaree_donne_son_propre_asset(): void
    {
        $organization = $this->load()->organization;

        // La fixture declare female-03, male-02, female-01, male-01, female-02
        // et un user SANS avatar.
        $avatars = User::query()
            ->where('organization_id', $organization->id)
            ->whereNotNull('avatar')
            ->pluck('avatar')
            ->all();

        $this->assertGreaterThanOrEqual(5, count($avatars));
        $this->assertSame(
            count($avatars),
            count(array_unique($avatars)),
            'Deux personas declarant des cles differentes ne doivent pas partager un asset.'
        );

        foreach ($avatars as $chemin) {
            $this->assertTrue(Storage::disk('public')->exists($chemin));
        }
    }

    public function test_un_avatar_null_reste_null_et_laisse_le_fallback_initiales(): void
    {
        // `null` est parfaitement valide (spec 13). Une correction qui
        // donnerait une photo a tout le monde serait un defaut.
        $organization = $this->load()->organization;

        $this->assertGreaterThan(
            0,
            User::query()
                ->where('organization_id', $organization->id)
                ->whereNull('avatar')
                ->count(),
            'La fixture declare au moins un persona sans avatar.'
        );
    }

    public function test_le_manifeste_ne_peut_pas_designer_un_chemin_de_fichier(): void
    {
        // Le document ne cite qu'une CLE logique. Une cle hors index ne resout
        // rien, et surtout ne sert pas a lire un chemin arbitraire.
        $this->assertNull(ManifestAvatarBank::asset(ManifestSchema::AVATAR_BANK, '../../../.env'));
        $this->assertNull(ManifestAvatarBank::asset(ManifestSchema::AVATAR_BANK, 'female-99'));
        $this->assertNotNull(ManifestAvatarBank::asset(ManifestSchema::AVATAR_BANK, 'female-03'));
    }

    public function test_les_assets_couvrent_toutes_les_cles_de_l_index(): void
    {
        // Une cle acceptee par le Validator mais sans asset donnerait un
        // avatar silencieusement absent au Load.
        $manquantes = [];

        foreach (ManifestAvatarBank::keys(ManifestSchema::AVATAR_BANK) as $cle) {
            if (ManifestAvatarBank::asset(ManifestSchema::AVATAR_BANK, $cle) === null) {
                $manquantes[] = $cle;
            }
        }

        $this->assertSame([], $manquantes, 'Chaque cle de l index doit avoir son asset versionne.');
    }

    // =====================================================================
    // 5. Le rejeu ne ment pas
    // =====================================================================

    public function test_un_second_chargement_ne_duplique_ni_message_ni_decision(): void
    {
        $this->load();
        $this->load();

        $this->assertSame(4, LoopMessage::query()->count());
        $this->assertSame(1, LoopDecision::query()->count());
        $this->assertNotNull(LoopDecision::query()->firstOrFail()->loop_message_id);
    }

    public function test_un_second_chargement_ne_rejoue_pas_la_cloture(): void
    {
        $mutation = static function (\stdClass $m): void {
            $m->polls[0]->status = 'closed';
        };

        $this->loadWith($mutation);
        $premier = LoopPoll::query()->firstOrFail()->closed_at;

        $this->loadWith($mutation);
        $poll = LoopPoll::query()->firstOrFail();

        $this->assertSame(1, LoopPoll::query()->count());
        $this->assertSame('closed', $poll->status);
        $this->assertEquals(
            $premier,
            $poll->closed_at,
            'Une seconde cloture ne doit pas deplacer l horodatage de la premiere.'
        );
    }

    public function test_un_second_chargement_ne_rejoue_pas_l_annulation(): void
    {
        $mutation = static function (\stdClass $m): void {
            $m->events[0]->status = 'cancelled';
        };

        $this->loadWith($mutation);
        $premier = LoopEvent::query()->firstOrFail()->cancelled_at;

        $this->loadWith($mutation);
        $event = LoopEvent::query()->firstOrFail();

        $this->assertSame(1, LoopEvent::query()->count());
        $this->assertSame('cancelled', $event->status);
        $this->assertEquals($premier, $event->cancelled_at);
    }

    public function test_un_second_chargement_ne_reecrit_pas_les_assets(): void
    {
        $this->load();

        $user = User::query()->whereNotNull('avatar')->firstOrFail();
        $chemin = $user->avatar;
        $empreinte = Storage::disk('public')->lastModified($chemin);

        $this->load();

        $this->assertSame($chemin, User::query()->findOrFail($user->id)->avatar);
        $this->assertSame(
            $empreinte,
            Storage::disk('public')->lastModified($chemin),
            'L asset partage ne doit pas etre reecrit a chaque chargement.'
        );
    }
}
