<?php

namespace Tests\Feature\ScenarioManifest;

use App\Models\BlogPost;
use App\Models\Dossier;
use App\Models\Organization;
use App\Models\ScenarioManifestVersion;
use App\Models\User;
use App\Support\ScenarioManager\Capture\ScenarioCaptureService;
use App\Support\ScenarioManager\ScenarioLifecycleService;
use App\Support\ScenarioManifest\ScenarioManifestValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

/**
 * TASK-1653 — `ROOT_DOSSIER_LOADER_FIDELITY`.
 *
 * Le Manifest redevient la source de verite pour ce qu'il declare sur le
 * Dossier racine et son document. Jusqu'ici le Loader posait le gabarit
 * produit et n'appliquait jamais la declaration : la divergence etait
 * PERMANENTE, et elle est devenue visible le jour ou un ecran de comparaison
 * l'a donnee a lire.
 *
 * Ces tests verifient les deux moities : ce qui est applique, et ce qui n'est
 * SURTOUT pas duplique.
 */
class ManifestRootDossierFidelityTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_le_Load_applique_EXACTEMENT_ce_que_le_manifeste_declare(): void
    {
        [$version, $sandbox, $source] = $this->charger();

        foreach ($source['dossiers'] as $declare) {
            // La fixture n'a que des Dossiers racines aujourd'hui ; un Dossier
            // non racine ajoute demain doit faire IGNORER ce cas, pas faire
            // exploser le test sur un index absent.
            if (! is_array($declare['root_document'] ?? null)) {
                continue;
            }

            $dossier = $this->dossierParNom($sandbox, (string) $declare['name']);

            $this->assertNotNull($dossier, "Le Dossier « {$declare['name']} » doit porter son nom declare.");

            // Les invariants du produit sont conserves. `parent_id` et la
            // visibilite sont deja tenus par le Validator AVANT le Load : ce
            // qu'on verifie ici, c'est que l'ecriture des champs declares ne
            // les a pas defaits.
            $this->assertNull($dossier->parent_id, 'Un Dossier racine n a pas de parent.');
            $this->assertNotNull($dossier->loop_id, 'Un Dossier racine appartient a sa Boucle.');
            $this->assertNotNull($dossier->root_blog_post_id, 'Un Dossier racine porte son document.');
            // La VISIBILITE n'est volontairement PAS appliquee.
            //
            // `ensureRootDossier()` cree l'espace documents en
            // `VISIBILITY_PRIVATE`, et c'est un choix produit :
            // `DossierPolicy` traite `VISIBILITY_LOOP` differemment — elle
            // exige un `sharedWithLoop`. Ecrire `loop` ici changerait QUI VOIT
            // le Dossier, ce qui depasse le perimetre de cette TASK.
            //
            // Le Manifest, lui, declare `loop` parce que son invariant le veut,
            // et la Capture normalise vers `loop` pour la meme raison : le
            // round-trip converge donc, et l'ecart reste interne au produit.
            // Dette nommee au TASK file.
            $this->assertSame('private', (string) $dossier->visibility);

            // Et le PROPRIETAIRE declare, que rien n'appliquait.
            $proprietaire = User::query()->findOrFail($dossier->owner_id);
            $this->assertStringStartsWith(
                explode('@', $this->emailDeclare($source, (string) $declare['owner']))[0].'@',
                (string) $proprietaire->email,
                'Le proprietaire du Dossier racine doit etre le persona DECLARE.'
            );

            $post = BlogPost::query()->withoutGlobalScopes()->findOrFail($dossier->root_blog_post_id);
            $declareDocument = $declare['root_document'];

            $this->assertSame($declareDocument['title'], (string) $post->title);
            $this->assertSame($declareDocument['content'], (string) $post->content);

            // L AUTEUR declare, resolu vers le persona reel.
            $auteur = User::query()->findOrFail($post->user_id);
            $emailDeclare = $this->emailDeclare($source, (string) $declareDocument['author']);
            $this->assertStringStartsWith(
                explode('@', $emailDeclare)[0].'@',
                (string) $auteur->email,
                'L auteur du document racine doit etre le persona DECLARE.'
            );
        }
    }

    public function test_aucun_second_Dossier_racine_ni_second_document_n_est_cree(): void
    {
        [, $sandbox, $source] = $this->charger();

        // Une racine par Boucle, et pas davantage.
        $racines = Dossier::query()->withoutGlobalScopes()
            ->where('organization_id', $sandbox->id)
            ->whereNull('deleted_at')
            ->whereNotNull('root_blog_post_id')
            ->get();

        $this->assertCount(count($source['loops']), $racines);
        $this->assertSame(
            $racines->count(),
            $racines->pluck('loop_id')->unique()->count(),
            'Deux Boucles ne partagent pas un Dossier racine.'
        );
        $this->assertSame(
            $racines->count(),
            $racines->pluck('root_blog_post_id')->unique()->count(),
            'Deux Dossiers racines ne partagent pas un document.'
        );
    }

    public function test_deux_Boucles_gardent_des_Dossiers_racines_DISTINCTS(): void
    {
        [, $sandbox, $source] = $this->charger();

        $noms = array_column($source['dossiers'], 'name');
        $this->assertCount(2, array_unique($noms), 'La fixture doit declarer deux noms distincts.');

        foreach ($noms as $nom) {
            $this->assertNotNull($this->dossierParNom($sandbox, $nom), "« {$nom} » doit exister tel quel.");
        }
    }

    public function test_un_RESET_reapplique_les_valeurs_DECLAREES(): void
    {
        // Reset reconstruit le monde : il doit le reconstruire FIDELE, pas
        // retomber sur le gabarit produit.
        [$version, $sandbox, $source] = $this->charger();

        $nomDeclare = (string) $source['dossiers'][0]['name'];
        $dossier = $this->dossierParNom($sandbox, $nomDeclare);
        $dossier->forceFill(['name' => 'Nom saccage dans la sandbox'])->save();

        app(ScenarioLifecycleService::class)->reset($version->fresh(), $this->superAdmin);

        $this->assertNotNull(
            $this->dossierParNom($sandbox, $nomDeclare),
            'Reset doit reappliquer le nom declare par le manifeste charge.'
        );

        // Et TOUT le monde declarable converge, pas seulement ce Dossier.
        // Une famille non reconstruite depuis la nouvelle ancre apparaitrait
        // ici en `changed` — y compris les onze autres champs porteurs d'un
        // offset, qu'une assertion sur les seuls messages ne voit pas.
        $diff = app(ScenarioCaptureService::class)->comparer($version->fresh());
        $this->assertTrue(
            $diff->estVide(),
            'Apres un Reset, le monde doit etre a nouveau conforme au manifeste : '.json_encode($diff->famillesModifiees())
        );
    }

    public function test_apres_le_Load_le_Preview_immediat_ne_montre_AUCUN_changement(): void
    {
        // Le critere produit de T1653 : c'est ce que ce correctif rend
        // atteignable. Sans lui, un Load suivi d'un Preview affichait
        // « 2 Dossiers modifies » sur un monde ou personne n'avait rien fait.
        [$version] = $this->charger();

        $diff = app(ScenarioCaptureService::class)->comparer($version);

        $this->assertTrue(
            $diff->estVide(),
            'Load puis Preview immediat doit rendre zero changement : '.json_encode($diff->famillesModifiees())
        );
    }

    public function test_le_Load_persiste_EXACTEMENT_l_ancre_que_le_pack_declare(): void
    {
        // LA preuve qui manquait a la Phase A, et elle doit etre DETERMINISTE.
        //
        // Ma premiere version comparait `world_anchored_at + offset*60` aux
        // `created_at` reels. Elle ne discriminait rien : en SQLite, `apply()`
        // dure moins d une seconde, donc remplacer l ancre declaree par `now()`
        // au moment de persister tombe dans la MEME seconde et le test reste
        // vert. Le sabotage l a montre — la mutation passait.
        //
        // On utilise donc un pack STUB qui declare une ancre lointaine et
        // n ecrit rien d autre : `now()` ne peut plus se confondre avec elle.
        // La cible doit etre QUALIFIEE : `ScenarioPackOrganizationGuard` refuse
        // toute Organization qui n est ni dans l allowlist, ni une sandbox de
        // scenario. La garde fait son travail — on lui donne une cible
        // legitime plutot que de la contourner.
        $organization = Organization::factory()->create();
        $organization->forceFill(['scenario_sandbox_created_at' => now()])->save();

        $ancre = new \DateTimeImmutable('2020-03-04 05:06:07', new \DateTimeZone('UTC'));

        $pack = new class($ancre) implements \App\Support\ScenarioPacks\Contracts\ScenarioPackDefinition
        {
            public function __construct(private readonly \DateTimeImmutable $ancre) {}

            public function packId(): string
            {
                return 'stub:ancre';
            }

            public function packVersion(): string
            {
                return '1.0.0';
            }

            public function packName(): string
            {
                return 'Stub ancre';
            }

            public function purpose(): string
            {
                return 'Prouver que l ancre declaree est celle qui est persistee.';
            }

            public function apply(Organization $organization, \App\Support\ScenarioPacks\ScenarioPackEntityRegistrar $registrar): void
            {
                $registrar->declarerLAncreDuMonde($this->ancre);
            }
        };

        $resultat = app(\App\Support\ScenarioPacks\ScenarioPackLoader::class)->load($pack, $organization);

        $this->assertSame(
            $ancre->getTimestamp(),
            $resultat->load->world_anchored_at->getTimestamp(),
            'Le Load doit persister EXACTEMENT l ancre declaree par le pack, pas un instant voisin.'
        );
    }

    public function test_les_offsets_du_monde_derivent_de_l_ancre_persistee(): void
    {
        // Et l autre moitie : l ancre persistee est bien celle dont les dates
        // du monde sont derivees. Comparaison a la SECONDE — le format de date
        // du modele est a la seconde, donc l ancre et les instants derives
        // perdent la meme fraction.
        [$version, $sandbox, $source] = $this->charger();

        $load = \App\Models\ScenarioPackLoad::query()->findOrFail($version->scenario_pack_load_id);
        $ancre = $load->world_anchored_at;

        $this->assertNotNull($ancre, 'Le Load doit persister une ancre.');

        $messages = \App\Models\LoopMessage::query()
            ->where('organization_id', $sandbox->id)->where('type', 'user')
            ->orderBy('created_at')->get();

        $offsets = array_column($source['messages'], 'offset_minutes');
        sort($offsets);

        $this->assertCount(count($offsets), $messages);

        foreach ($messages as $rang => $message) {
            $this->assertSame(
                $ancre->getTimestamp() + $offsets[$rang] * 60,
                $message->created_at->getTimestamp(),
                'Les dates du monde derivent de l ancre persistee.'
            );
        }
    }

    public function test_le_locale_de_l_ORGANIZATION_ne_vient_pas_du_locale_RACINE(): void
    {
        // Les deux sont INDEPENDANTS dans le schema, et le provisionneur charge
        // la sandbox avec `organization.locale`. Lire le locale racine faisait
        // recharger la version capturee dans la MAUVAISE langue — et
        // `ensureRootDocument()` y aurait cuit titres et en-tetes, definitivement.
        [$version, $sandbox] = $this->charger();

        // On force la divergence : le locale racine dit « en », la sandbox « fr ».
        $document = json_decode((string) $version->json_source, true);
        $document['locale'] = 'en';
        $version->forceFill(['json_source' => json_encode($document, JSON_UNESCAPED_UNICODE)])->save();

        $this->assertSame('fr', (string) $sandbox->locale, 'La sandbox doit etre chargee dans le locale de son bloc organization.');

        $capture = app(ScenarioCaptureService::class)->inspecter($version->fresh())->document;

        $this->assertSame('fr', $capture['organization']['locale'], 'Le locale de l Organization vient du RUNTIME.');
        $this->assertSame('en', $capture['locale'], 'Le locale racine, lui, reste celui du document.');
    }

    // =====================================================================
    // Outillage
    // =====================================================================

    private function dossierParNom(Organization $sandbox, string $nom): ?Dossier
    {
        return Dossier::query()->withoutGlobalScopes()
            ->where('organization_id', $sandbox->id)
            ->whereNull('deleted_at')
            ->where('name', $nom)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function emailDeclare(array $source, string $clefPersona): string
    {
        foreach ($source['users'] as $persona) {
            if ($persona['key'] === $clefPersona) {
                return (string) $persona['email'];
            }
        }

        $this->fail("Le persona « {$clefPersona} » n existe pas dans la fixture.");
    }

    /**
     * @return array{0: ScenarioManifestVersion, 1: Organization, 2: array<string, mixed>}
     */
    private function charger(): array
    {
        $json = file_get_contents(base_path('tests/Fixtures/ScenarioManifest/amt-formation-ia.json'));
        $resultat = app(ScenarioManifestValidator::class)->validate($json);
        $this->assertTrue($resultat->isValid(), 'La fixture AMT doit etre valide.');

        $version = new ScenarioManifestVersion([
            'scenario_key' => 'amt-formation-ia',
            'name' => 'AMT — Formation IA',
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

        $service = app(ScenarioLifecycleService::class);
        $service->approve($version, $this->superAdmin);
        $resultatLoad = $service->load($version->fresh());

        return [$version->fresh(), $resultatLoad->organization, json_decode($json, true)];
    }
}
