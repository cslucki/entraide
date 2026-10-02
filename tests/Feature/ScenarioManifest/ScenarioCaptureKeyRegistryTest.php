<?php

namespace Tests\Feature\ScenarioManifest;

use App\Models\Organization;
use App\Models\ScenarioCaptureKey;
use App\Models\ScenarioManifestVersion;
use App\Models\ScenarioPackEntity;
use App\Models\User;
use App\Support\ScenarioManager\Capture\ScenarioCaptureKeyRegistry;
use App\Support\ScenarioManager\ScenarioLifecycleService;
use App\Support\ScenarioManifest\ScenarioManifestValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

/**
 * TASK-1652 — le registre des stable keys de Capture.
 *
 * Ce fichier ne verifie pas d'abord que le registre « marche ». Il verifie
 * qu'il ne MENT pas :
 *
 * - que sa carte d'amorcage n'ignore aucune famille reellement tracee ;
 * - qu'un objet source garde SA clef d'origine ;
 * - qu'un objet nouveau garde la sienne apres un renommage ;
 * - qu'il ne franchit jamais la frontiere de la sandbox.
 */
class ScenarioCaptureKeyRegistryTest extends TestCase
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

    /**
     * La carte d'amorcage est ECRITE A LA MAIN : elle doit donc etre
     * confrontee a la realite.
     *
     * Une carte en dur se perime EN SILENCE — c'est le defaut paye en T1631
     * sur des tables, en T1650 sur des colonnes, en T1651 sur une forme. Ici,
     * une famille ajoutee demain a un applier produirait un `entity_type` que
     * la carte ignore, et les objets de cette famille perdraient leur clef
     * d'origine a la Capture : ils apparaitraient comme NEUFS a chaque fois.
     *
     * Le test part donc d'un Load REEL et confronte les `entity_type`
     * reellement ecrits a la carte. Toute exclusion doit etre DECLAREE ici,
     * avec sa raison.
     */
    public function test_la_carte_d_amorcage_couvre_TOUT_ce_que_les_appliers_tracent(): void
    {
        // L'autorite est le CODE des appliers, pas une fixture.
        //
        // Premiere version de ce test : confronter la carte aux `entity_type`
        // reellement ecrits par UN Load. C'etait faux dans les deux sens. La
        // fixture AMT ne declare que des Dossiers RACINES, donc `applyDossiers`
        // n'ecrit jamais `manifest_dossier` : la carte paraissait nommer un
        // type fantome alors qu'elle avait raison. Une fixture prouve ce
        // qu'elle contient, jamais ce que le code peut produire.
        $typesDuCode = [];

        foreach (glob(base_path('app/Support/ScenarioPacks/Manifest/*.php')) as $fichier) {
            preg_match_all("/track\\('([a-z_]+)'/", (string) file_get_contents($fichier), $trouves);
            $typesDuCode = array_merge($typesDuCode, $trouves[1]);
        }

        $typesDuCode = array_values(array_unique($typesDuCode));
        sort($typesDuCode);

        $this->assertGreaterThanOrEqual(20, count($typesDuCode), 'La lecture des appliers doit trouver les familles ; un motif casse rendrait ce test vert a vide.');

        // Les exclusions, NOMMEES, avec la raison qui les justifie.
        $exclusions = [
            // Sa clef Manifest est celle de son Dossier, pas celle de sa
            // Boucle : elle se lit dans `loops[].root_dossier` du document
            // source, et l'amorcage le fait par une voie dediee.
            'manifest_root_dossier',
            // Objets EN LIGNE : ils vivent DANS un objet porteur et n'ont pas
            // de clef propre (`dossiers[].root_document`,
            // `users[].member_ai_profile`). Le second a ete trouve PAR ce
            // test, pas par ma lecture des appliers.
            'manifest_root_document',
            'manifest_member_ai_profile',
            // Identites COMPOSEES : adressees par leurs composants.
            'manifest_membership',
            'manifest_course_progress',
            'manifest_course_submission',
            // Un lien, pas un objet.
            'manifest_article_placement',
        ];

        $carte = ScenarioCaptureKeyRegistry::famillesAmorcables();

        $orphelins = array_values(array_filter(
            $typesDuCode,
            static fn (string $type): bool => ! isset($carte[$type]) && ! in_array($type, $exclusions, true)
        ));

        $this->assertSame([], $orphelins, sprintf(
            "Ces entity_type sont traces par un applier mais ni mappes ni exclus : %s.\n".
            'Chaque famille absente de la carte perd sa clef d origine a la Capture.',
            implode(', ', $orphelins)
        ));

        // L'autre sens : la carte ne doit nommer aucun type que le code n'ecrit
        // pas — sinon elle decrit un monde disparu.
        $fantomes = array_values(array_diff(array_keys($carte), $typesDuCode));
        $this->assertSame([], $fantomes, 'La carte nomme des entity_type qu aucun applier n ecrit : '.implode(', ', $fantomes));

        // Et les familles nommees sont bien des familles MANIFEST.
        $familles = array_keys(\App\Support\ScenarioManifest\ManifestSchema::envelope());
        $familles = array_merge($familles, array_map(
            static fn (string $s): string => 'training.'.$s,
            array_keys(\App\Support\ScenarioManifest\ManifestSchema::envelope()['training']['fields'])
        ));

        foreach ($carte as $type => $famille) {
            $this->assertContains($famille, $familles, "La carte fait pointer {$type} vers une famille inexistante : {$famille}.");
        }
    }

    public function test_un_objet_du_Manifest_SOURCE_garde_sa_clef_d_origine(): void
    {
        $version = $this->versionApprouvee();
        $resultat = app(ScenarioLifecycleService::class)->load($version);
        $document = json_decode((string) $version->json_source, true);

        $registre = ScenarioCaptureKeyRegistry::pour($resultat->organization);
        $registre->amorcerDepuisLeMoteur($resultat->packLoad->load, $document);

        // Une personne du manifeste : sa clef runtime doit etre SA clef
        // declaree, jamais une clef fabriquee depuis son nom.
        $ligne = ScenarioPackEntity::query()
            ->where('organization_id', $resultat->organization->id)
            ->where('entity_type', 'manifest_user')
            ->firstOrFail();

        $this->assertSame(
            (string) $ligne->internal_key,
            $registre->clefConnue('users', (string) $ligne->entity_id)
        );

        // Et la clef proposee est IGNOREE sur un objet deja connu.
        $this->assertSame(
            (string) $ligne->internal_key,
            $registre->clefDe('users', (string) $ligne->entity_id, 'un-nom-totalement-different')
        );
    }

    public function test_le_Dossier_RACINE_recoit_la_clef_du_DOSSIER_et_non_celle_de_sa_Boucle(): void
    {
        // Le piege mesure : `trackRootDossier()` inscrit le Dossier racine sous
        // la clef de sa BOUCLE. Amorcer naivement lui donnerait cette clef —
        // deux objets pour une clef, et un Manifest qui ne valide plus.
        $version = $this->versionApprouvee();
        $resultat = app(ScenarioLifecycleService::class)->load($version);
        $document = json_decode((string) $version->json_source, true);

        $registre = ScenarioCaptureKeyRegistry::pour($resultat->organization);
        $registre->amorcerDepuisLeMoteur($resultat->packLoad->load, $document);

        $ligne = ScenarioPackEntity::query()
            ->where('organization_id', $resultat->organization->id)
            ->where('entity_type', 'manifest_root_dossier')
            ->firstOrFail();

        $clefDeLaBoucle = (string) $ligne->internal_key;
        $clefObtenue = $registre->clefConnue('dossiers', (string) $ligne->entity_id);

        $attendue = null;
        foreach ($document['loops'] as $boucle) {
            if ($boucle['key'] === $clefDeLaBoucle) {
                $attendue = $boucle['root_dossier'];
            }
        }

        $this->assertNotNull($attendue, 'La fixture doit declarer un root_dossier.');
        $this->assertNotSame($clefDeLaBoucle, $attendue, 'Le cas ne vaut que si les deux clefs DIFFERENT.');
        $this->assertSame($attendue, $clefObtenue);
    }

    public function test_un_objet_NOUVEAU_garde_sa_clef_apres_un_renommage(): void
    {
        // Le contrat qui rend deux Captures comparables. Sans lui, renommer une
        // Boucle ferait croire au diff qu un objet a disparu et qu un autre est
        // apparu.
        $resultat = app(ScenarioLifecycleService::class)->load($this->versionApprouvee());
        $sandbox = $resultat->organization;
        $identifiant = (string) \Illuminate\Support\Str::uuid7();

        $premier = ScenarioCaptureKeyRegistry::pour($sandbox);
        $clef = $premier->clefDe('loops', $identifiant, 'Atelier du mardi');
        $premier->persister();

        $this->assertSame('atelier-du-mardi', $clef);

        // Renommage runtime, puis SECONDE Capture.
        $second = ScenarioCaptureKeyRegistry::pour($sandbox);

        $this->assertSame($clef, $second->clefDe('loops', $identifiant, 'Un tout autre nom'));
        $this->assertSame(1, ScenarioCaptureKey::query()->where('organization_id', $sandbox->id)->count());
    }

    public function test_deux_objets_HOMONYMES_recoivent_des_clefs_distinctes(): void
    {
        $resultat = app(ScenarioLifecycleService::class)->load($this->versionApprouvee());
        $registre = ScenarioCaptureKeyRegistry::pour($resultat->organization);

        $a = $registre->clefDe('loops', (string) \Illuminate\Support\Str::uuid7(), 'Atelier');
        $b = $registre->clefDe('loops', (string) \Illuminate\Support\Str::uuid7(), 'Atelier');

        $this->assertNotSame($a, $b);
        $motif = (new \ReflectionClass(\App\Support\ScenarioManifest\ManifestShapeValidator::class))
            ->getConstant('STABLE_KEY_PATTERN');
        $this->assertMatchesRegularExpression($motif, $a);
        $this->assertMatchesRegularExpression($motif, $b);

        // Mais une meme clef dans DEUX familles est legitime : l'unicite est
        // PAR famille (mesure T1651).
        $c = $registre->clefDe('users', (string) \Illuminate\Support\Str::uuid7(), 'Atelier');
        $this->assertSame('atelier', $c);
    }

    public function test_le_registre_d_une_sandbox_ignore_celui_d_une_AUTRE(): void
    {
        $premiere = app(ScenarioLifecycleService::class)->load($this->versionApprouvee())->organization;
        $identifiant = (string) \Illuminate\Support\Str::uuid7();

        $registreA = ScenarioCaptureKeyRegistry::pour($premiere);
        $registreA->clefDe('loops', $identifiant, 'Atelier partage');
        $registreA->persister();

        $seconde = Organization::factory()->create();
        $seconde->forceFill(['scenario_sandbox_created_at' => now()])->save();

        $registreB = ScenarioCaptureKeyRegistry::pour($seconde);

        // La MEME entite runtime, vue depuis l'autre sandbox : inconnue.
        $this->assertNull($registreB->clefConnue('loops', $identifiant));

        // Et la clef « atelier-partage » y est LIBRE : l'unicite est bornee a
        // la sandbox, pas globale.
        $this->assertSame('atelier-partage', $registreB->clefDe('loops', (string) \Illuminate\Support\Str::uuid7(), 'Atelier partage'));
    }

    private function versionApprouvee(): ScenarioManifestVersion
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

        app(ScenarioLifecycleService::class)->approve($version, $this->superAdmin);

        return $version->fresh();
    }
}
