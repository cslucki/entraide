<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\MemberNotification;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopMessageService;
use App\Services\LoopService;
use App\Support\Loops\LoopRoleRegistry;
use App\Support\Notifications\NotificationCatalogue;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TASK-1799 (B1) — la memoire de lecture du chemin des mentions.
 *
 * ## Ce que ce fichier verrouille
 *
 * `NotificationTargetResolver::loopMessage()`, `NotificationEmailDeliverer::
 * rendreDepuisGabarit()` et `LoopMentionMailer::compose()` relisaient
 * independamment, pour CHAQUE destinataire d'une mention, la MEME Boucle, la
 * MEME Organization, le MEME message et le MEME auteur — audit TASK-1798,
 * sources de cout #3/#4. B1 les fait passer par `ScopedReadCache` (precedent
 * TASK-1778), avec des cles PARTAGEES entre les trois appelants.
 *
 * Chaque garde ci-dessous mesure une PROPRIETE relative (« lu au plus une
 * fois, quel que soit N »), jamais un chiffre absolu — meme discipline que
 * `TASK1778FanOutSqlCostTest`.
 *
 * ## Ce que ce fichier NE verrouille PAS, volontairement
 *
 * Les controles qui VARIENT par destinataire (tenant, adhesion active,
 * preference courante) ne sont pas touches par B1 et restent couverts par
 * `TASK1797MentionAllTest` — les rejouer ici serait une seconde copie du
 * meme controle, exactement le piege que ce depot documente ailleurs.
 */
class TASK1799NotificationFanOutCacheTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $auteur;

    private Loop $loop;

    /** @var list<User> */
    private array $membres = [];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('mail.default', 'array');

        $this->org = Organization::factory()->create(['is_active' => true, 'slug' => 'org-1799-cache']);
        $this->auteur = User::factory()->complete()->create([
            'organization_id' => $this->org->id, 'first_name' => 'Alice', 'name' => 'Owner',
        ]);

        app()->instance('current_organization', $this->org);

        $service = new LoopService;
        $this->loop = $service->createLoop($this->auteur, 'Boucle du banc B1');

        for ($i = 0; $i < 5; $i++) {
            $membre = User::factory()->complete()->create(['organization_id' => $this->org->id]);
            $service->addMemberByUserId($this->loop, $membre->id, LoopRoleRegistry::MEMBER);
            $this->membres[] = $membre;
        }
    }

    private function envoyerMentionTous(): LoopMessage
    {
        $destinataires = array_map(fn (User $u) => (string) $u->id, $this->membres);

        return app(LoopMessageService::class)->sendUserMessage(
            $this->loop, $this->auteur, 'Un message pour @tous', ['mention_all' => true], null, null, $destinataires,
        );
    }

    private function assertCinqNotificationsEmail(): void
    {
        $this->assertSame(
            5,
            MemberNotification::query()->where('notification_key', NotificationCatalogue::LOOP_MENTION_CREATED)->count(),
            'Les 5 notifications doivent exister, sinon aucune garde ci-dessous ne mesure le bon chemin.'
        );
    }

    // =====================================================================
    // Plafonds de lecture — la PREUVE que B1 fonctionne
    // =====================================================================

    /**
     * Le MESSAGE n'est volontairement PAS memorise par B1 (a la difference
     * de la Boucle/Organization/acteur) : une tentative de le faire a ete
     * retiree parce qu'elle faisait rougir `TASK1772MentionEmailEtCibleTest::
     * test_un_message_supprime_ou_un_membre_sorti_ne_mene_plus_nulle_part` et
     * `::test_un_message_sans_texte_affiche_une_mention_neutre` — deux
     * garanties de fraicheur anterieures a cette TASK. Ce test verrouille
     * cette decision : le nombre de lectures du message DOIT CROITRE avec le
     * nombre de destinataires, preuve qu'aucune memoire de lecture ne s'y
     * applique.
     */
    public function test_le_message_mentionne_reste_relu_a_chaque_destinataire_par_construction(): void
    {
        $lectures = 0;
        DB::listen(function (QueryExecuted $e) use (&$lectures): void {
            if (str_contains($e->sql, 'from "loop_messages"') && str_contains($e->sql, '"id" = ?')) {
                $lectures++;
            }
        });

        $message = $this->envoyerMentionTous();
        DB::getEventDispatcher()->forget(QueryExecuted::class);

        $this->assertCinqNotificationsEmail();
        $this->assertNotNull($message);
        $this->assertGreaterThanOrEqual(
            5,
            $lectures,
            "Le message n a ete lu que {$lectures} fois pour 5 destinataires : s il est devenu memorise, ".
            'relire ce test et celui de TASK1772 avant de changer quoi que ce soit.'
        );
    }

    public function test_la_boucle_de_la_mention_n_est_lue_qu_une_fois_pour_cinq_destinataires(): void
    {
        $lectures = 0;
        DB::listen(function (QueryExecuted $e) use (&$lectures): void {
            // Forme EXACTE de la lecture memorisee (tenant pose en WHERE) —
            // distincte d'un `Loop::find()` nu lu ailleurs dans l'envoi
            // (hors du chemin que B1 optimise, et deja constant en N).
            if (str_starts_with($e->sql, 'select * from "loops" where "loops"."id" = ? and "organization_id" = ?')) {
                $lectures++;
            }
        });

        $this->envoyerMentionTous();
        DB::getEventDispatcher()->forget(QueryExecuted::class);

        $this->assertCinqNotificationsEmail();
        $this->assertLessThanOrEqual(
            1,
            $lectures,
            "La Boucle a ete lue {$lectures} fois pour 5 destinataires : la memoire de lecture ne joue plus."
        );
    }

    public function test_l_organization_de_la_mention_n_est_lue_qu_une_fois_pour_cinq_destinataires(): void
    {
        $lectures = 0;
        DB::listen(function (QueryExecuted $e) use (&$lectures): void {
            // La lecture PLEINE LIGNE memorisee (`select *`) — distincte de
            // la lecture `select "locale"` (deja memorisee depuis T1778, et
            // mesuree par `TASK1778FanOutSqlCostTest`) et de la lecture en
            // LOT de `allowsMany()` (`... id in (?)`, deja unique en soi).
            if (str_starts_with($e->sql, 'select * from "organizations" where "organizations"."id" = ?')) {
                $lectures++;
            }
        });

        $this->envoyerMentionTous();
        DB::getEventDispatcher()->forget(QueryExecuted::class);

        $this->assertCinqNotificationsEmail();
        $this->assertLessThanOrEqual(
            1,
            $lectures,
            "L Organization a ete lue {$lectures} fois pour 5 destinataires : la memoire de lecture ne joue plus."
        );
    }

    public function test_l_auteur_de_la_mention_n_est_lu_qu_une_fois_pour_cinq_destinataires(): void
    {
        $lectures = 0;
        DB::listen(function (QueryExecuted $e) use (&$lectures): void {
            // Forme EXACTE de la lecture memorisee (`User::with('organization')
            // ->find()`, ligne PLEINE table-qualifiee) — distincte de la
            // garde d'integrite `NotificationInvariants::
            // assertBelongsToOrganization()`, qui relit l'acteur a chaque
            // notification CREEE par CONCEPTION (`select "organization_id"
            // from "users"`, jamais memorisee : c'est une garde de securite,
            // pas une donnee de reference) — et distincte de la lecture
            // D'UN DESTINATAIRE (valeur differente de binding).
            if (! str_starts_with($e->sql, 'select * from "users" where "users"."id" = ?')) {
                return;
            }

            foreach ($e->bindings as $valeur) {
                if ((string) $valeur === (string) $this->auteur->id) {
                    $lectures++;

                    return;
                }
            }
        });

        $this->envoyerMentionTous();
        DB::getEventDispatcher()->forget(QueryExecuted::class);

        $this->assertCinqNotificationsEmail();
        $this->assertLessThanOrEqual(
            1,
            $lectures,
            "L auteur a ete lu {$lectures} fois pour 5 destinataires : la memoire de lecture ne joue plus."
        );
    }

    // =====================================================================
    // Isolation entre Organizations — le risque INTRODUIT par une cle partagee
    // =====================================================================

    /**
     * Deux mentions, dans deux Organizations distinctes, DANS LA MEME requete
     * de test. Si une cle de `ScopedReadCache` oubliait l'`organization_id`,
     * la seconde Boucle/Organization lue rendrait la PREMIERE — une
     * contamination inter-tenant, silencieuse, exactement ce que ce depot
     * s'interdit partout ailleurs.
     */
    public function test_deux_organizations_distinctes_ne_partagent_aucune_entree_de_cache(): void
    {
        $this->envoyerMentionTous();

        $autreOrg = Organization::factory()->create(['is_active' => true, 'slug' => 'org-1799-cache-bis']);
        $autreAuteur = User::factory()->complete()->create([
            'organization_id' => $autreOrg->id, 'first_name' => 'Zoe', 'name' => 'Owner Bis',
        ]);
        $autreMembre = User::factory()->complete()->create(['organization_id' => $autreOrg->id]);

        app()->instance('current_organization', $autreOrg);

        $service = new LoopService;
        $autreLoop = $service->createLoop($autreAuteur, 'Boucle de l autre Organization');
        $service->addMemberByUserId($autreLoop, $autreMembre->id, LoopRoleRegistry::MEMBER);

        app(LoopMessageService::class)->sendUserMessage(
            $autreLoop, $autreAuteur, 'Un message pour @tous, ailleurs', ['mention_all' => true], null, null,
            [(string) $autreMembre->id],
        );

        $notificationAutreOrg = MemberNotification::query()
            ->where('notification_key', NotificationCatalogue::LOOP_MENTION_CREATED)
            ->where('recipient_id', $autreMembre->id)
            ->firstOrFail();

        $cible = app(\App\Support\Notifications\NotificationTargetResolver::class)->resolve($notificationAutreOrg);

        $this->assertNotNull($cible, 'La cible de la seconde Organization doit rester resolvable.');
        $this->assertStringContainsString(
            (string) $autreOrg->slug,
            $cible,
            'La cible resolue doit mener a la SECONDE Organization, jamais a la premiere (cle de cache contaminee).'
        );
        $this->assertStringContainsString(
            (string) $autreLoop->fresh()->slug,
            $cible,
            'La cible doit mener a la Boucle de la SECONDE Organization (par son slug, pas celle de la premiere).'
        );
    }
}
