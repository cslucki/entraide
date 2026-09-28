<?php

namespace Tests\Feature\ScenarioManifest;

use App\Models\Organization;
use App\Models\ScenarioManifestVersion;
use App\Models\ScenarioPackLoad;
use App\Models\User;
use App\Support\ScenarioManager\Capture\ScenarioCaptureService;
use App\Support\ScenarioManager\ScenarioLifecycleService;
use App\Support\ScenarioManifest\ManifestAvatarBank;
use App\Support\ScenarioManifest\ManifestSchema;
use App\Support\ScenarioManifest\ScenarioManifestValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * TASK-1654 Phase 0 — un asset PARTAGE ne se supprime jamais, un upload
 * individuel se supprime toujours.
 *
 * ## Le defaut que ce fichier ferme
 *
 * L'avatar d'un persona est publie a un chemin DELIBEREMENT partage :
 * `scenario-avatars/<banque>/<cle>.svg`, sans discriminant d'Organization. Deux
 * personas — meme sandbox ou deux sandboxes — designent donc le MEME fichier.
 *
 * `AdminController::updateUser()` supprimait l'ancien avatar sans condition.
 * Ecrit pour l'upload individuel (`avatars/…`), ou « un fichier = une
 * personne » est vrai. Sur un persona, la meme ligne privait de son image tout
 * persona, dans toute sandbox, declarant cette cle — et aucun test ne passait
 * par la.
 *
 * ## Les deux sens, parce qu'un seul fabriquerait l'autre defaut
 *
 * « On ne supprime plus rien » echangerait une casse globale contre une fuite
 * de stockage. Ces tests prouvent donc les DEUX : l'asset de banque survit, et
 * l'upload individuel remplace disparait bien.
 *
 * ## Le faux homonyme
 *
 * `female-03` est une cle valide de la banque. `avatars/female-03.jpg` n'est
 * pas pour autant un asset de banque : c'est l'upload d'une personne qui se
 * trouve porter ce nom. Conclure l'inverse a deux consequences opposees et
 * toutes deux fausses — refuser de supprimer un fichier qu'il fallait
 * supprimer, et declarer dans un manifeste capture un avatar de banque qui
 * n'existe pas. C'est exactement ce que faisait
 * `ScenarioCaptureSerializer::clefDAvatar()`, qui comparait le seul basename.
 */
class TASK1654SharedAvatarSafetyTest extends TestCase
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

    // =====================================================================
    // 1. L'autorite canonique
    // =====================================================================

    /**
     * La forme canonique, ecrite A LA MAIN.
     *
     * Ce test est le seul du fichier a ne PAS demander le chemin a l'autorite :
     * il le compose lui-meme, caractere par caractere. Sans lui, tous les
     * autres partageraient le defaut eventuel de l'autorite qu'ils
     * interrogent — ils resteraient verts sur un chemin faux, du moment qu'il
     * est faux partout pareil.
     */
    public function test_la_forme_canonique_est_EXACTEMENT_celle_que_le_produit_publie(): void
    {
        $this->assertSame(
            'scenario-avatars/fictional-avatars-v1/female-03.svg',
            ManifestAvatarBank::publishedPath('fictional-avatars-v1', 'female-03'),
            "La forme publiee est un CONTRAT : c'est le chemin deja present dans ".
            "`users.avatar` des mondes charges, et le changer les orpheline tous."
        );

        $this->assertSame(
            'fictional-avatars-v1',
            ManifestSchema::AVATAR_BANK,
            'La banque du schema doit rester celle que cette forme nomme.'
        );
    }

    public function test_le_chemin_canonique_de_banque_rend_la_cle_logique(): void
    {
        $chemin = ManifestAvatarBank::publishedPath(ManifestSchema::AVATAR_BANK, 'female-03');

        $this->assertNotNull($chemin);
        $this->assertTrue(ManifestAvatarBank::isPublishedAsset($chemin));
        $this->assertSame('female-03', ManifestAvatarBank::keyFromPublishedPath($chemin));
    }

    public function test_un_couple_inconnu_ne_fabrique_AUCUN_chemin(): void
    {
        // Fail-closed : une cle inconnue ne rend pas un chemin « probable ».
        // C'est ce qui empeche un nom recu de l'exterieur de fabriquer une
        // destination d'ecriture.
        $this->assertNull(ManifestAvatarBank::publishedPath(ManifestSchema::AVATAR_BANK, 'female-99'));
        $this->assertNull(ManifestAvatarBank::publishedPath('banque-inexistante', 'female-03'));
        $this->assertNull(ManifestAvatarBank::publishedPath('..', 'female-03'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function cheminsQuiNeSontPasDesAssetsDeBanque(): array
    {
        $banque = ManifestSchema::AVATAR_BANK;

        return [
            'upload individuel, extension differente' => ['avatars/female-03.jpg', 'faux homonyme'],
            'upload individuel, MEME extension' => ['avatars/female-03.svg', 'faux homonyme'],
            'racine sans repertoire' => ['female-03.svg', 'aucun repertoire'],
            'segment de banque absent' => ['scenario-avatars/female-03.svg', 'deux segments'],
            'banque inconnue' => ['scenario-avatars/autre-banque/female-03.svg', 'banque non declaree'],
            'cle inconnue' => ["scenario-avatars/{$banque}/female-99.svg", 'cle absente de l index'],
            'extension fausse' => ["scenario-avatars/{$banque}/female-03.png", 'asset non SVG'],
            'sans extension' => ["scenario-avatars/{$banque}/female-03", 'aucune extension'],
            'segment en trop' => ["scenario-avatars/{$banque}/sous/female-03.svg", 'quatre segments'],
            'prefixe absolu' => ["/scenario-avatars/{$banque}/female-03.svg", 'segment vide en tete'],
            'prefixe relatif' => ["./scenario-avatars/{$banque}/female-03.svg", 'quatre segments'],
            'double separateur' => ["scenario-avatars//{$banque}/female-03.svg", 'segment vide'],
            'traversee' => ["scenario-avatars/{$banque}/../../female-03.svg", 'cinq segments'],
            'repertoire ressemblant' => ["scenario-avatarsX/{$banque}/female-03.svg", 'repertoire different'],
            'chaine vide' => ['', 'aucun chemin'],
        ];
    }

    #[DataProvider('cheminsQuiNeSontPasDesAssetsDeBanque')]
    public function test_aucune_de_ces_formes_n_est_un_asset_de_banque(string $chemin, string $raison): void
    {
        $this->assertFalse(
            ManifestAvatarBank::isPublishedAsset($chemin),
            sprintf('« %s » ne doit PAS passer pour un asset de banque (%s).', $chemin, $raison)
        );

        $this->assertNull(ManifestAvatarBank::keyFromPublishedPath($chemin));
    }

    // =====================================================================
    // 2. Le remplacement depuis /admin/users
    // =====================================================================

    public function test_remplacer_l_avatar_d_un_persona_ne_supprime_JAMAIS_l_asset_partage(): void
    {
        Storage::fake('public');

        [$sandbox, $persona, $partage] = $this->personaAvecAvatarDeBanque('female-03');

        $this->remplacerLAvatar($persona);

        Storage::disk('public')->assertExists($partage);
        $this->assertNotSame(
            $partage,
            $persona->fresh()->avatar,
            'Le persona doit bien recevoir son NOUVEL avatar : seule la suppression est interdite.'
        );
        $this->assertStringStartsWith('avatars/', (string) $persona->fresh()->avatar);
        $this->assertNotNull($sandbox->fresh());
    }

    public function test_un_SECOND_persona_qui_declare_la_meme_cle_garde_son_image(): void
    {
        Storage::fake('public');

        [, $premier, $partage] = $this->personaAvecAvatarDeBanque('female-03');
        $second = User::factory()->create([
            'organization_id' => $premier->organization_id,
            'email' => 'second@amt-demo.test',
            'avatar' => $partage,
        ]);

        $this->remplacerLAvatar($premier);

        Storage::disk('public')->assertExists($partage);
        $this->assertSame(
            $partage,
            $second->fresh()->avatar,
            "Le second persona pointe toujours le meme chemin — et ce chemin doit ".
            'encore designer un fichier.'
        );
    }

    public function test_une_AUTRE_sandbox_qui_declare_la_meme_cle_garde_son_image(): void
    {
        Storage::fake('public');

        // Le rayon d'action reel du defaut : le chemin ne porte aucun
        // discriminant d'Organization, donc la casse traverse les tenants.
        [, $personaA, $partage] = $this->personaAvecAvatarDeBanque('female-03');
        [, $personaB] = $this->personaAvecAvatarDeBanque('female-03');

        $this->assertSame($partage, $personaB->avatar, 'Les deux sandboxes partagent le MEME fichier.');
        $this->assertNotSame(
            $personaA->organization_id,
            $personaB->organization_id,
            'Les deux personas doivent bien etre dans deux Organizations differentes.'
        );

        $this->remplacerLAvatar($personaA);

        Storage::disk('public')->assertExists($partage);
        $this->assertSame($partage, $personaB->fresh()->avatar);
    }

    public function test_un_upload_individuel_remplace_est_TOUJOURS_supprime(): void
    {
        Storage::fake('public');

        // Le sens inverse. Sans ce test, « ne plus rien supprimer » passerait :
        // on aurait echange une casse globale contre une fuite de stockage.
        $ancien = 'avatars/ancien-fichier-individuel.jpg';
        Storage::disk('public')->put($ancien, 'contenu-individuel');

        $personne = User::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
            'avatar' => $ancien,
        ]);

        $this->remplacerLAvatar($personne);

        Storage::disk('public')->assertMissing($ancien);
        $this->assertNotSame($ancien, $personne->fresh()->avatar);
    }

    public function test_un_upload_individuel_NOMME_comme_une_cle_de_banque_est_bien_supprime(): void
    {
        Storage::fake('public');

        // Le faux homonyme, cote suppression. Si la garde se contentait du
        // basename, ce fichier — qui appartient a UNE personne — survivrait
        // pour toujours a chaque remplacement.
        $ancien = 'avatars/female-03.jpg';
        Storage::disk('public')->put($ancien, 'upload-individuel');

        $personne = User::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
            'avatar' => $ancien,
        ]);

        $this->remplacerLAvatar($personne);

        Storage::disk('public')->assertMissing($ancien);
    }

    public function test_un_remplacement_d_upload_individuel_ne_laisse_AUCUN_orphelin(): void
    {
        Storage::fake('public');

        $ancien = 'avatars/a-remplacer.jpg';
        Storage::disk('public')->put($ancien, 'contenu');

        $personne = User::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
            'avatar' => $ancien,
        ]);

        $avant = Storage::disk('public')->allFiles('avatars');
        $this->remplacerLAvatar($personne);
        $apres = Storage::disk('public')->allFiles('avatars');

        $this->assertCount(
            count($avant),
            $apres,
            "Un remplacement d'upload individuel remplace : il n'ACCUMULE pas. ".
            'Fichiers avant : '.implode(', ', $avant).' — apres : '.implode(', ', $apres)
        );
        $this->assertSame([$personne->fresh()->avatar], $apres);
    }

    // =====================================================================
    // 3. La capture : meme autorite, pas une copie
    // =====================================================================

    public function test_la_capture_rend_la_cle_logique_d_un_avatar_de_banque(): void
    {
        $version = $this->versionChargee();

        $document = app(ScenarioCaptureService::class)->inspecter($version)->document;

        $this->assertSame(
            'female-03',
            $this->avatarCapture($document, 'trainer-1'),
            'AMT declare `female-03` pour `trainer-1` : la capture doit le rendre.'
        );
    }

    public function test_un_upload_individuel_ressemblant_a_une_cle_n_est_JAMAIS_capture_comme_asset_de_banque(): void
    {
        $version = $this->versionChargee();
        $sandbox = $this->sandboxDe($version);

        // Le persona se trouve par ce que le test DECRIT — celui qui porte
        // l'asset partage —, jamais par la forme de son email : le loader
        // suffixe les emails par sandbox, et une recherche sur cette forme
        // rendrait le test muet le jour ou le suffixe change.
        $persona = $this->personaPortant($sandbox, 'female-03');

        // La personne a change son avatar depuis son profil : un upload
        // individuel, qui se trouve porter le nom d'une cle de banque.
        $persona->forceFill(['avatar' => 'avatars/female-03.jpg'])->save();

        $document = app(ScenarioCaptureService::class)->inspecter($version)->document;

        $this->assertNull(
            $this->avatarCapture($document, 'trainer-1'),
            "Ce fichier n'est PAS un asset de banque. Le declarer reviendrait a ".
            'ecrire dans le manifeste une provenance deduite d une ressemblance de nom.'
        );
    }

    public function test_un_avatar_hors_banque_est_capture_a_null(): void
    {
        $version = $this->versionChargee();
        $sandbox = $this->sandboxDe($version);

        $this->personaPortant($sandbox, 'female-03')
            ->forceFill(['avatar' => 'avatars/photo-personnelle.png'])
            ->save();

        $document = app(ScenarioCaptureService::class)->inspecter($version)->document;

        $this->assertNull($this->avatarCapture($document, 'trainer-1'));
    }

    // =====================================================================
    // Harnais
    // =====================================================================

    /**
     * Un persona dans une sandbox, portant l'asset PARTAGE de banque.
     *
     * @return array{Organization, User, string}
     */
    private function personaAvecAvatarDeBanque(string $cle): array
    {
        $chemin = ManifestAvatarBank::publishedPath(ManifestSchema::AVATAR_BANK, $cle);
        $this->assertNotNull($chemin, "La cle « {$cle} » doit exister dans la banque.");

        if (! Storage::disk('public')->exists($chemin)) {
            Storage::disk('public')->put($chemin, '<svg xmlns="http://www.w3.org/2000/svg"/>');
        }

        $sandbox = Organization::factory()->create();
        $sandbox->forceFill(['scenario_sandbox_created_at' => now()])->save();

        $persona = User::factory()->create([
            'organization_id' => $sandbox->id,
            'email' => 'persona-'.uniqid().'@amt-demo.test',
            'avatar' => $chemin,
        ]);

        return [$sandbox, $persona, $chemin];
    }

    /**
     * Le geste reel de l'operateur : remplacer l'avatar depuis `/admin/users`.
     *
     * L'`organization_id` est celle du compte : l'edition SUR PLACE est
     * exactement ce que la garde sandbox de T1650 autorise, et c'est donc par
     * la que le defaut passait.
     */
    private function remplacerLAvatar(User $compte): void
    {
        $this->actingAs($this->superAdmin)
            ->put(route('admin.users.update', $compte), [
                'name' => $compte->name,
                'email' => $compte->email,
                'organization_id' => $compte->organization_id,
                'avatar' => UploadedFile::fake()->image('nouveau.jpg'),
            ])
            ->assertSessionHasNoErrors();
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function avatarCapture(array $document, string $clefPersona): ?string
    {
        foreach ($document['users'] ?? [] as $declare) {
            if (($declare['key'] ?? null) === $clefPersona) {
                return $declare['avatar'] ?? null;
            }
        }

        $this->fail("Le persona « {$clefPersona} » est absent du document capture.");
    }

    /**
     * Le persona de cette sandbox qui porte l'asset de banque de cette cle.
     */
    private function personaPortant(Organization $sandbox, string $cle): User
    {
        $chemin = ManifestAvatarBank::publishedPath(ManifestSchema::AVATAR_BANK, $cle);
        $this->assertNotNull($chemin);

        $personas = User::withoutGlobalScopes()
            ->where('organization_id', $sandbox->id)
            ->where('avatar', $chemin)
            ->get();

        $this->assertCount(
            1,
            $personas,
            "Exactement UN persona de la sandbox doit porter « {$cle} » : ".
            "sans quoi ce test ne saurait pas de qui il parle."
        );

        return $personas->first();
    }

    private function sandboxDe(ScenarioManifestVersion $version): Organization
    {
        $load = ScenarioPackLoad::query()->findOrFail($version->scenario_pack_load_id);

        return Organization::query()->withTrashed()->findOrFail($load->organization_id);
    }

    private function versionChargee(): ScenarioManifestVersion
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
        $service->load($version->fresh());

        return $version->fresh();
    }
}
