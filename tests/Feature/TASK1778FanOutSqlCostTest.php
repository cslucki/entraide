<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\LoopNotificationPreference;
use App\Models\MemberNotification;
use App\Models\MemberNotificationDelivery;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopMessageService;
use App\Support\Notifications\NotificationCatalogue;
use App\Support\Notifications\NotificationPreferenceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * TASK-1778 — les gardes du cout SQL du fan-out collectif.
 *
 * ## Ce que ce fichier verrouille, et pourquoi chaque garde est necessaire
 *
 * T1778 a retire des requetes. Une optimisation qui n'est pas verrouillee se
 * defait : il suffit qu'un futur appelant oublie de passer les verdicts
 * calcules en masse pour que le N+1 revienne, sans qu'aucun test ne rougisse.
 * Chaque garde d'ici mesure donc une PROPRIETE, pas un chiffre arbitraire.
 *
 * | garde | ce qu'elle empeche de revenir |
 * |---|---|
 * | aucune livraison planifiee pour un canal coupe | le job qui part pour decouvrir un refus deja connu |
 * | cout de preference CONSTANT en N | le retour de `allows()` appele par destinataire |
 * | `allowsMany()` == `allows()` | la divergence entre les deux strategies de chargement |
 * | langue de l'Organization lue une seule fois | la perte de la memoire de lecture |
 * | un reglage d'une AUTRE Boucle ne gouverne pas celle-ci | une contamination par le `whereIn` du chargement en masse |
 * | la preference reste relue a la livraison | une memoire de lecture etendue par erreur aux preferences |
 *
 * ## Les comptes de requetes sont RELATIFS, jamais absolus
 *
 * Aucune garde n'ecrit « 36 requetes ». Un seuil absolu rougirait a la
 * premiere colonne ajoutee ailleurs, et on le releverait sans reflechir
 * jusqu'a ce qu'il ne mesure plus rien. Ce qui est verrouille, c'est que le
 * cout de resolution des preferences **ne depend pas du nombre de
 * destinataires** — la propriete, pas sa valeur du jour.
 */
class TASK1778FanOutSqlCostTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $auteur;

    private Loop $boucle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['is_active' => true]);
        $this->auteur = User::factory()->create(['organization_id' => $this->organization->id]);

        $this->boucle = Loop::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by' => $this->auteur->id,
            'status' => 'active',
        ]);

        $this->rejoindre($this->auteur, 'owner');
    }

    // =====================================================================
    // 1. Rien n'est planifie pour un canal que la personne a coupe
    // =====================================================================

    /**
     * **La garde centrale de T1778.**
     *
     * Avant cette TASK, le planificateur ne consultait que le catalogue : il
     * creait une ligne de livraison et mettait un job en file pour l'email ET
     * pour le push, meme quand le membre avait coupe les deux. Le job le
     * decouvrait ensuite, apres ~12 requetes et deux ecritures, et concluait
     * en `skipped_preference`.
     *
     * L'in-app, lui, doit continuer a partir : il est obligatoire au
     * catalogue. C'est ce qui rend cette mesure concluante — si la
     * notification n'existait pas, l'absence de livraison ne prouverait rien.
     */
    public function test_un_canal_coupe_AVANT_l_emission_ne_planifie_aucune_livraison(): void
    {
        Queue::fake();

        $membre = $this->membreAvecReglage(email: false, push: false);

        app(LoopMessageService::class)->sendHelpRequestMessage(
            $this->boucle, $this->auteur, 'Un coup de main ?', 'Relecture', 'Critique', 'Cette semaine', 'relecture',
        );

        $notification = MemberNotification::query()
            ->where('recipient_id', $membre->id)
            ->where('notification_key', NotificationCatalogue::LOOP_HELP_REQUEST_CREATED)
            ->first();

        $this->assertNotNull($notification, 'L in-app est obligatoire : la notification doit exister.');

        $this->assertSame(
            0,
            MemberNotificationDelivery::query()->where('notification_id', $notification->id)->count(),
            'Aucun travail ne doit etre planifie pour deux canaux deja coupes.'
        );

        Queue::assertNothingPushed();
    }

    /** Le canal GARDE doit, lui, etre planifie — sinon la garde precedente serait vraie pour la mauvaise raison. */
    public function test_le_canal_LAISSE_actif_est_bien_planifie(): void
    {
        Queue::fake();

        $membre = $this->membreAvecReglage(email: true, push: false);

        app(LoopMessageService::class)->sendHelpRequestMessage(
            $this->boucle, $this->auteur, 'Un coup de main ?', 'Relecture', 'Critique', 'Cette semaine', 'relecture',
        );

        $notification = MemberNotification::query()->where('recipient_id', $membre->id)->firstOrFail();

        $canaux = MemberNotificationDelivery::query()
            ->where('notification_id', $notification->id)
            ->pluck('channel')
            ->all();

        $this->assertSame([NotificationCatalogue::CHANNEL_EMAIL], $canaux);
    }

    // =====================================================================
    // 2. Le cout de resolution ne depend PAS du nombre de destinataires
    // =====================================================================

    /**
     * **La propriete, pas le chiffre.**
     *
     * Les trois tables de preference (`loop_members`,
     * `member_loop_notification_preferences`, `member_notification_preferences`)
     * doivent etre interrogees le MEME nombre de fois pour 2 destinataires et
     * pour 8. C'est ce qui distingue un chargement en masse d'un N+1.
     *
     * `Queue::fake()` est indispensable : sans lui, les livreurs tournent en
     * ligne sous `sync` et relisent la preference une fois par livraison —
     * ce qui est voulu, mais qui est le cout de LIVRAISON, pas celui de
     * PLANIFICATION. Les melanger rendrait la mesure illisible.
     */
    public function test_le_cout_de_resolution_des_preferences_est_CONSTANT_en_nombre_de_destinataires(): void
    {
        Queue::fake();

        $avecDeux = $this->requetesDePreferencePourUneBoucleDe(2);
        $avecHuit = $this->requetesDePreferencePourUneBoucleDe(8);

        $this->assertSame(
            $avecDeux,
            $avecHuit,
            "Le cout de resolution des preferences a suivi le nombre de destinataires ({$avecDeux} -> {$avecHuit}) : "
            .'le chargement en masse de `allowsMany()` a ete contourne quelque part.'
        );

        // Et ce nombre constant doit rester PETIT : si une refonte future
        // rendait la constante egale a 40, l'egalite ci-dessus serait encore
        // vraie et ne mesurerait plus rien.
        $this->assertLessThanOrEqual(6, $avecHuit, 'Le chargement en masse doit tenir en quelques requetes.');
    }

    // =====================================================================
    // 3. Les deux strategies de chargement rendent le MEME verdict
    // =====================================================================

    /**
     * **L'anti-divergence.**
     *
     * `allows()` lit a la demande, `allowsMany()` lit en masse. Si les deux
     * recopiaient la cascade du catalogue, elles finiraient par diverger — et
     * c'est la rapide qui tournerait en production, la lente qui garderait
     * les tests. Elles partagent donc un seul arbitre (`decider()`), et ce
     * test est la preuve que le partage tient.
     *
     * Les quatre situations couvertes ne sont pas decoratives : ce sont les
     * quatre branches de la cascade — aucun reglage, maitre coupe, canal
     * coupe, et une appartenance qui n'est plus active.
     */
    public function test_allowsMany_rend_exactement_le_MEME_verdict_que_allows(): void
    {
        $resolveur = app(NotificationPreferenceResolver::class);

        $sansRien = $this->membreAvecReglage(email: null, push: null);
        $maitreCoupe = $this->membreAvecReglage(email: true, push: true, collectif: false);
        $emailCoupe = $this->membreAvecReglage(email: false, push: true);
        $partiMaisReglage = $this->membreAvecReglage(email: false, push: false, statut: 'left');

        $personnes = collect([$sansRien, $maitreCoupe, $emailCoupe, $partiMaisReglage]);

        $canaux = [
            NotificationCatalogue::CHANNEL_IN_APP,
            NotificationCatalogue::CHANNEL_EMAIL,
            NotificationCatalogue::CHANNEL_WEB_PUSH,
        ];

        $enMasse = $resolveur->allowsMany(
            $personnes,
            NotificationCatalogue::LOOP_HELP_REQUEST_CREATED,
            $canaux,
            (string) $this->boucle->id,
        );

        foreach ($personnes as $personne) {
            foreach ($canaux as $canal) {
                $this->assertSame(
                    $resolveur->allows(
                        $personne,
                        NotificationCatalogue::LOOP_HELP_REQUEST_CREATED,
                        $canal,
                        (string) $this->boucle->id,
                    ),
                    $enMasse[(string) $personne->id][$canal],
                    "Divergence sur {$canal} pour {$personne->id}."
                );
            }
        }

        // Et les verdicts ne sont pas tous identiques : sans cela, l'egalite
        // ci-dessus serait vraie pour la mauvaise raison.
        $this->assertFalse($enMasse[(string) $maitreCoupe->id][NotificationCatalogue::CHANNEL_IN_APP]);
        $this->assertTrue($enMasse[(string) $sansRien->id][NotificationCatalogue::CHANNEL_EMAIL]);
        $this->assertFalse($enMasse[(string) $emailCoupe->id][NotificationCatalogue::CHANNEL_EMAIL]);
        $this->assertTrue($enMasse[(string) $emailCoupe->id][NotificationCatalogue::CHANNEL_WEB_PUSH]);
    }

    /**
     * **Un reglage pose sur une AUTRE Boucle ne gouverne pas celle-ci.**
     *
     * Le chargement en masse interroge `member_loop_notification_preferences`
     * avec un `whereIn('user_id', ...)`. Si la borne `where('loop_id', ...)`
     * disparaissait, le reglage d'une Boucle contaminerait toutes les autres
     * — et le test precedent resterait vert, puisqu'il ne cree qu'une Boucle.
     */
    public function test_un_reglage_pose_sur_une_AUTRE_boucle_ne_gouverne_pas_celle_ci(): void
    {
        $membre = $this->membreAvecReglage(email: null, push: null);

        $autreBoucle = Loop::factory()->create([
            'organization_id' => $this->organization->id,
            'created_by' => $this->auteur->id,
            'status' => 'active',
        ]);

        LoopMember::create([
            'loop_id' => $autreBoucle->id,
            'user_id' => $membre->id,
            'organization_id' => $this->organization->id,
            'role' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $reglage = new LoopNotificationPreference([
            'collective_enabled' => false,
            'email_enabled' => false,
            'web_push_enabled' => false,
        ]);
        $reglage->organization_id = (string) $this->organization->id;
        $reglage->loop_id = (string) $autreBoucle->id;
        $reglage->user_id = (string) $membre->id;
        $reglage->save();

        $verdicts = app(NotificationPreferenceResolver::class)->allowsMany(
            collect([$membre]),
            NotificationCatalogue::LOOP_HELP_REQUEST_CREATED,
            [NotificationCatalogue::CHANNEL_IN_APP, NotificationCatalogue::CHANNEL_EMAIL],
            (string) $this->boucle->id,
        );

        $this->assertTrue(
            $verdicts[(string) $membre->id][NotificationCatalogue::CHANNEL_IN_APP],
            'Le silence demande sur une autre Boucle ne doit pas eteindre celle-ci.'
        );
        $this->assertTrue($verdicts[(string) $membre->id][NotificationCatalogue::CHANNEL_EMAIL]);
    }

    // =====================================================================
    // 4. La memoire de lecture couvre les REFERENCES, jamais les preferences
    // =====================================================================

    /**
     * **La langue de l'Organization est lue UNE fois pour N destinataires.**
     *
     * Elle est relue a chaque resolution de gabarit — donc deux fois par
     * destinataire sur ce chemin, puisque le corps est compose deux fois (le
     * lien reel et le lien expurge pour le journal). Le banc T1778 comptait
     * 200 lectures de la meme ligne pour une Boucle de 100 membres.
     *
     * ## Le gabarit lui-meme n'est PAS memorise, et c'est voulu
     *
     * Il l'a ete le temps d'une mesure. Deux tests l'ont fait rougir
     * (`TASK1751...::test_le_repli_rend_un_contenu_quand_le_gabarit_est_retire`,
     * `TASK1772...::test_un_gabarit_desactive_retombe_sur_le_repli_de_langue`)
     * et ils avaient raison : un gabarit est du contenu ADMINISTRABLE, re-rendu
     * dans la requete meme ou on l'enregistre, et un `Builder::update()` en
     * masse ne declenche aucun evenement de modele — aucune invalidation ne
     * pouvait etre fiable. Le detail du raisonnement est dans
     * `SystemEmailTemplateResolver::resolve()`.
     */
    public function test_la_langue_de_l_organization_n_est_lue_qu_une_fois_pour_plusieurs_destinataires(): void
    {
        config()->set('mail.default', 'array');

        for ($i = 0; $i < 4; $i++) {
            $this->membreAvecReglage(email: true, push: false);
        }

        $lectures = 0;

        DB::listen(function ($evenement) use (&$lectures): void {
            if (str_contains($evenement->sql, 'select "locale" from "organizations"')
                || str_contains($evenement->sql, 'select `locale` from `organizations`')) {
                $lectures++;
            }
        });

        app(LoopMessageService::class)->sendHelpRequestMessage(
            $this->boucle, $this->auteur, 'Un coup de main ?', 'Relecture', 'Critique', 'Cette semaine', 'relecture',
        );

        DB::getEventDispatcher()->forget(\Illuminate\Database\Events\QueryExecuted::class);

        $this->assertSame(
            4,
            MemberNotificationDelivery::query()->forChannel(NotificationCatalogue::CHANNEL_EMAIL)->count(),
            'Les quatre emails doivent bien avoir ete planifies, sinon rien n a resolu de gabarit.'
        );

        $this->assertLessThanOrEqual(
            1,
            $lectures,
            "La langue de l Organization a ete lue {$lectures} fois : la memoire de lecture par requete ne joue plus."
        );
    }

    /**
     * **La frontiere de la memoire de lecture.**
     *
     * Une preference coupee ENTRE la planification et la livraison doit etre
     * vue par le livreur. Si un jour la memoire de lecture etait etendue aux
     * preferences « pour gagner quelques requetes », ce test rougirait — et
     * c'est exactement ce qu'il protege : la garantie « la preference
     * COURANTE, pas celle de l'emission ».
     */
    public function test_la_preference_reste_RELUE_a_la_livraison_malgre_la_memoire_de_lecture(): void
    {
        config()->set('mail.default', 'array');
        Queue::fake();

        $membre = $this->membreAvecReglage(email: true, push: false);

        app(LoopMessageService::class)->sendHelpRequestMessage(
            $this->boucle, $this->auteur, 'Un coup de main ?', 'Relecture', 'Critique', 'Cette semaine', 'relecture',
        );

        $notification = MemberNotification::query()->where('recipient_id', $membre->id)->firstOrFail();

        LoopNotificationPreference::query()
            ->forOwner((string) $membre->id)
            ->where('loop_id', $this->boucle->id)
            ->update(['email_enabled' => false]);

        app(\App\Support\Notifications\NotificationEmailDeliverer::class)->deliver((string) $notification->id);

        $livraison = MemberNotificationDelivery::query()
            ->where('notification_id', $notification->id)
            ->forChannel(NotificationCatalogue::CHANNEL_EMAIL)
            ->firstOrFail();

        $this->assertSame(
            \App\Support\Notifications\NotificationDeliveryStatus::SKIPPED_PREFERENCE,
            $livraison->status,
            'Le livreur doit voir la coupure posee apres la planification.'
        );
    }

    // ── Outillage ───────────────────────────────────────────────────────────

    /** Combien de requetes de PREFERENCE pour une Boucle neuve de N membres. */
    private function requetesDePreferencePourUneBoucleDe(int $taille): int
    {
        $organization = Organization::factory()->create(['is_active' => true]);
        $auteur = User::factory()->create(['organization_id' => $organization->id]);

        $boucle = Loop::factory()->create([
            'organization_id' => $organization->id,
            'created_by' => $auteur->id,
            'status' => 'active',
        ]);

        foreach (array_merge([$auteur], array_map(
            fn () => User::factory()->create(['organization_id' => $organization->id]),
            range(1, $taille),
        )) as $i => $personne) {
            LoopMember::create([
                'loop_id' => $boucle->id,
                'user_id' => $personne->id,
                'organization_id' => $organization->id,
                'role' => $i === 0 ? 'owner' : 'member',
                'status' => 'active',
                'joined_at' => now(),
            ]);
        }

        $compte = 0;

        DB::listen(function ($evenement) use (&$compte): void {
            foreach (['loop_members', 'member_loop_notification_preferences', 'member_notification_preferences'] as $table) {
                if (str_contains($evenement->sql, $table)) {
                    $compte++;

                    return;
                }
            }
        });

        app(LoopMessageService::class)->sendHelpRequestMessage(
            $boucle, $auteur, 'Un coup de main ?', 'Relecture', 'Critique', 'Cette semaine', 'relecture',
        );

        DB::getEventDispatcher()->forget(\Illuminate\Database\Events\QueryExecuted::class);

        return $compte;
    }

    /**
     * Un membre actif, avec ou sans reglage par Boucle.
     *
     * `$email`/`$push` a `null` : AUCUNE ligne n'est posee — c'est le cas de
     * la quasi-totalite des gens, et celui que le defaut du catalogue
     * gouverne.
     */
    private function membreAvecReglage(
        ?bool $email,
        ?bool $push,
        bool $collectif = true,
        string $statut = 'active',
    ): User {
        $membre = User::factory()->create([
            'organization_id' => $this->organization->id,
            'preferred_locale' => 'fr',
        ]);

        $this->rejoindre($membre, 'member', $statut);

        if ($email !== null || $push !== null || ! $collectif) {
            $reglage = new LoopNotificationPreference([
                'collective_enabled' => $collectif,
                'email_enabled' => $email ?? true,
                'web_push_enabled' => $push ?? true,
            ]);
            $reglage->organization_id = (string) $this->organization->id;
            $reglage->loop_id = (string) $this->boucle->id;
            $reglage->user_id = (string) $membre->id;
            $reglage->save();
        }

        return $membre;
    }

    private function rejoindre(User $personne, string $role, string $statut = 'active'): void
    {
        LoopMember::create([
            'loop_id' => $this->boucle->id,
            'user_id' => $personne->id,
            'organization_id' => $this->organization->id,
            'role' => $role,
            'status' => $statut,
            'joined_at' => now(),
        ]);
    }
}
