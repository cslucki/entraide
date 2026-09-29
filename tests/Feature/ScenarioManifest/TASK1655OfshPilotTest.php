<?php

namespace Tests\Feature\ScenarioManifest;

use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\ScenarioManifestVersion;
use App\Models\ScenarioPackLoad;
use App\Models\User;
use App\Support\ScenarioManager\ScenarioLifecycleService;
use App\Support\ScenarioManifest\ScenarioManifestValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TASK-1655 — OFSH est le scenario de REFERENCE : il doit rester chargeable.
 *
 * ## Pourquoi ce fichier, et pas seulement la recette navigateur
 *
 * La recette prouve le parcours une fois, sur un poste. Ce test prouve, a chaque
 * execution de la CI et sur les deux moteurs, que le Manifest de reference reste
 * valide ET applicable — deux proprietes distinctes, et c'est tout l'objet du
 * test le plus important d'ici.
 *
 * ## Le defaut que ce fichier empeche de revenir
 *
 * Le Validator a declare VERT un document que le Loader a REFUSE :
 * `dossiers.loop_id` porte un index UNIQUE — « pour la racine d'une Boucle » — et
 * la premiere transcription posait deux Dossiers sur la meme Boucle. Aucune
 * regle de schema ne l'interdit ; seule la base le refuse, au Load.
 *
 * « Le Validator est vert » ne veut donc pas dire « le monde se charge ». Le test
 * qui compte est celui qui CHARGE.
 *
 * ## La lacune du Review Pack, gardee ici
 *
 * Deux invariants avaient echappe a 32 controles de relecture : un auteur de
 * message doit etre membre de sa Boucle, et un `highlight_in_loop` exige la meme
 * appartenance. Le Validator les a trouves. Ils sont desormais gardes AVANT lui,
 * pour que l'echec dise quoi corriger plutot que « REFERENCE_WRONG_SCOPE ».
 */
class TASK1655OfshPilotTest extends TestCase
{
    use RefreshDatabase;

    /**
     * TASK-1656 : le Manifest OFSH a quitte `tests/Fixtures/` pour devenir la
     * source CANONIQUE du modele produit. Ce test lit desormais CE fichier —
     * celui que l'ecran « Modeles » propose. Deux copies maintenues en
     * parallele auraient diverge, et le pilote aurait cesse de prouver quoi que
     * ce soit sur ce que l'utilisateur recoit reellement.
     */
    private const FIXTURE = 'resources/scenario-manifest/templates/ofsh-1.0.0.json';

    /** Les compteurs canoniques, arbitres par MASTER le 28/09/2026. */
    private const CANONIQUES = [
        'users' => 16,
        'loops' => 5,
        'memberships' => 34,
        'messages' => 38,
        'service_requests' => 4,
        'services' => 5,
        'dossiers' => 5,
        'articles' => 5,
        'events' => 3,
        'decisions' => 3,
        'roadmap_items' => 3,
        'categories' => 3,
        'skills' => 8,
    ];

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        App::setLocale('fr');

        $this->superAdmin = User::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
            'is_admin' => true,
            'preferred_locale' => 'fr',
        ]);
    }

    public function test_le_manifest_de_reference_OFSH_est_valide(): void
    {
        $resultat = app(ScenarioManifestValidator::class)->validate($this->manifestJson());

        $this->assertTrue(
            $resultat->isValid(),
            'OFSH est le scenario de REFERENCE : son Manifest doit rester valide. Erreurs : '
            .json_encode($resultat->toArray()['errors'] ?? [], JSON_UNESCAPED_UNICODE)
        );
    }

    public function test_les_compteurs_canoniques_sont_ceux_arbitres(): void
    {
        $doc = json_decode($this->manifestJson(), true);

        foreach (self::CANONIQUES as $famille => $attendu) {
            $this->assertCount(
                $attendu,
                $doc[$famille],
                "OFSH declare {$attendu} {$famille} — compteur canonique arbitre par MASTER."
            );
        }

        $this->assertCount(8, array_merge(...array_column($doc['events'], 'responses')),
            'EVENT_RESPONSES_INITIAL vaut 8, enumerees une par une dans la Product Spec.');
    }

    public function test_tout_auteur_de_message_est_membre_de_sa_Boucle(): void
    {
        // Invariant que 32 controles de relecture avaient manque, et que le
        // Validator a trouve. Garde ici pour que l'echec dise QUOI corriger.
        $doc = json_decode($this->manifestJson(), true);
        $membres = [];

        foreach ($doc['memberships'] as $m) {
            $membres[$m['loop']][$m['user']] = true;
        }

        foreach ($doc['messages'] as $message) {
            $this->assertArrayHasKey(
                $message['author'],
                $membres[$message['loop']] ?? [],
                "« {$message['author']} » ecrit dans « {$message['loop']} » sans en etre membre."
            );
        }
    }

    public function test_tout_highlight_respecte_l_appartenance(): void
    {
        $doc = json_decode($this->manifestJson(), true);
        $membres = [];

        foreach ($doc['memberships'] as $m) {
            $membres[$m['loop']][$m['user']] = true;
        }

        foreach (['service_requests', 'services'] as $famille) {
            foreach ($doc[$famille] as $objet) {
                if (($objet['highlight_in_loop'] ?? null) === null) {
                    continue;
                }

                $this->assertArrayHasKey(
                    $objet['author'],
                    $membres[$objet['highlight_in_loop']] ?? [],
                    "{$famille}[{$objet['key']}] met en avant « {$objet['highlight_in_loop']} » ".
                    "alors que « {$objet['author']} » n'en est pas membre."
                );
            }
        }
    }

    public function test_chaque_Boucle_ne_porte_QU_UN_Dossier(): void
    {
        // `dossiers.loop_id` porte un index UNIQUE. Le Validator ne le sait pas :
        // ce n'est pas une regle de schema, c'est la base. Sans cette garde, un
        // document VALIDE peut etre refuse au Load.
        $doc = json_decode($this->manifestJson(), true);
        $parBoucle = [];

        foreach ($doc['dossiers'] as $dossier) {
            if (($dossier['loop'] ?? null) === null) {
                continue;
            }

            $this->assertArrayNotHasKey(
                $dossier['loop'],
                $parBoucle,
                "Deux Dossiers sur « {$dossier['loop']} » : l'index UNIQUE dossiers.loop_id ".
                'refusera le Load, quoi que dise le Validator.'
            );

            $parBoucle[$dossier['loop']] = $dossier['key'];
        }

        foreach ($doc['loops'] as $loop) {
            $this->assertSame(
                $loop['root_dossier'],
                $parBoucle[$loop['key']] ?? null,
                "Le root_dossier de « {$loop['key']} » doit etre SON unique Dossier."
            );
        }
    }

    public function test_les_decisions_decoulent_d_une_discussion_et_deux_projets_d_une_decision(): void
    {
        $doc = json_decode($this->manifestJson(), true);
        $clefsMessages = array_column($doc['messages'], 'key');

        foreach ($doc['decisions'] as $decision) {
            $this->assertContains(
                $decision['message'],
                $clefsMessages,
                "La decision « {$decision['key']} » doit porter la clef du message dont elle decoule."
            );
        }

        $avecDecision = array_filter($doc['roadmap_items'], fn ($r) => ($r['decision'] ?? null) !== null);

        $this->assertCount(2, $avecDecision, 'Deux projets decoulent d une decision.');
        $this->assertCount(1, array_filter($doc['roadmap_items'], fn ($r) => ($r['decision'] ?? null) === null),
            'Le troisieme nait d une demande, et son `decision` reste nul — arbitrage MASTER Q2.');
    }

    public function test_les_personas_sont_fictives_et_sans_privilege_plateforme(): void
    {
        $doc = json_decode($this->manifestJson(), true);

        foreach ($doc['users'] as $u) {
            $this->assertStringEndsWith('.test', $u['email'], "« {$u['key']} » doit avoir une adresse fictive.");
            $this->assertContains($u['organization_role'], ['admin', 'member']);
        }

        $admins = array_filter($doc['users'], fn ($u) => $u['organization_role'] === 'admin');

        $this->assertCount(1, $admins, 'Un seul administrateur de TENANT.');
        $this->assertSame('helene-vasseur', reset($admins)['key']);
    }

    public function test_le_monde_OFSH_se_CHARGE_reellement(): void
    {
        // LE test. « Le Validator est vert » et « le monde se charge » sont deux
        // proprietes distinctes : la premiere transcription satisfaisait la
        // premiere et echouait la seconde.
        $version = $this->versionValidee();

        $service = app(ScenarioLifecycleService::class);
        $service->approve($version, $this->superAdmin);
        $service->load($version->fresh());

        $version = $version->fresh();

        $this->assertTrue($version->isLoaded(), 'OFSH doit etre chargeable.');

        $load = ScenarioPackLoad::query()->findOrFail($version->scenario_pack_load_id);
        $sandbox = Organization::query()->withTrashed()->findOrFail($load->organization_id);

        $this->assertNotNull($sandbox->scenario_sandbox_created_at, 'La cible doit etre une sandbox du Manager.');
        $this->assertSame('Organismes de Formation Humanistes & Solidaires', $sandbox->name);

        $boucles = Loop::query()->where('organization_id', $sandbox->id)->pluck('id');

        $this->assertSame(16, User::query()->where('organization_id', $sandbox->id)->count(), 'personas');
        $this->assertCount(5, $boucles, 'Boucles');
        $this->assertSame(34, LoopMember::query()->whereIn('loop_id', $boucles)->count(), 'memberships');
        $this->assertSame(38, DB::table('loop_messages')->where('organization_id', $sandbox->id)->count(), 'messages');
        $this->assertSame(5, DB::table('dossiers')->where('organization_id', $sandbox->id)->count(), 'Dossiers');
        $this->assertSame(3, DB::table('loop_events')->where('organization_id', $sandbox->id)->count(), 'evenements');
        $this->assertSame(8, DB::table('loop_event_responses')->where('organization_id', $sandbox->id)->count(), 'reponses');
        $this->assertSame(3, DB::table('loop_decisions')->where('organization_id', $sandbox->id)->count(), 'decisions');
        $this->assertSame(3, DB::table('loop_roadmap_items')->whereIn('loop_id', $boucles)->count(), 'projets');
    }

    public function test_le_monde_charge_garde_ses_chaines_de_causalite(): void
    {
        $version = $this->versionValidee();
        $service = app(ScenarioLifecycleService::class);
        $service->approve($version, $this->superAdmin);
        $service->load($version->fresh());

        $sandbox = ScenarioPackLoad::query()
            ->findOrFail($version->fresh()->scenario_pack_load_id)->organization_id;
        $boucles = Loop::query()->where('organization_id', $sandbox)->pluck('id');

        // Une decision sans message est un texte sans provenance : c'est ce que
        // le monde ne doit jamais devenir.
        $this->assertSame(
            0,
            DB::table('loop_decisions')->where('organization_id', $sandbox)->whereNull('loop_message_id')->count(),
            'Les trois decisions doivent garder le message dont elles decoulent.'
        );

        $this->assertSame(
            2,
            DB::table('loop_roadmap_items')->whereIn('loop_id', $boucles)->whereNotNull('loop_decision_id')->count(),
            'Deux projets doivent garder leur decision d origine.'
        );

        $this->assertSame(
            1,
            DB::table('loop_events')->where('organization_id', $sandbox)->where('status', 'cancelled')->count(),
            'Le webinaire reporte doit rester annule (fidelite T1647).'
        );

        $this->assertSame(
            1,
            DB::table('blog_posts')->where('organization_id', $sandbox)->where('status', 'draft')->count(),
            'Le retour d atelier doit rester un brouillon : l atelier n a pas eu lieu.'
        );
    }

    private function manifestJson(): string
    {
        return (string) file_get_contents(base_path(self::FIXTURE));
    }

    private function versionValidee(): ScenarioManifestVersion
    {
        $json = $this->manifestJson();
        $resultat = app(ScenarioManifestValidator::class)->validate($json);

        $this->assertTrue($resultat->isValid(), 'Le Manifest OFSH doit etre valide avant tout Load.');

        $version = new ScenarioManifestVersion([
            'scenario_key' => 'ofsh',
            'name' => 'Organismes de Formation Humanistes & Solidaires',
            'version' => '1.0.0',
            'usage' => ScenarioManifestVersion::USAGE_DOGFOODING,
            'origin' => ScenarioManifestVersion::ORIGIN_IMPORT,
            'json_source' => $json,
            'created_by' => $this->superAdmin->id,
        ]);

        $version->forceFill([
            'state' => ScenarioManifestVersion::STATE_VALID,
            'digest' => $resultat->digest(),
            'validation_summary' => $resultat->toArray(),
        ])->save();

        return $version->fresh();
    }
}
