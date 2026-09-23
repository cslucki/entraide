<?php

namespace Tests\Feature;

use App\Livewire\CreateFeedPost;
use App\Livewire\EditFeedPost;
use App\Livewire\LoopChat;
use App\Livewire\MessageThread;
use App\Models\Loop;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * TASK-1623 — l'upload d'image casse quand le stockage temporaire Livewire
 * est DISTANT.
 *
 * LE MECANISME, et il est entierement dans le vendor :
 *
 *   TemporaryUploadedFile::getPathname() rend Storage::disk($tmp)->path($p),
 *   et FilesystemAdapter::path() ne fait que PREFIXER le chemin avec la
 *   racine du disque. Sur un disque local, cette racine est absolue et le
 *   chemin rendu existe. Sur S3/GCS, la racine est vide : le chemin rendu est
 *   RELATIF — « livewire-tmp/xxx.png ». Intervention Image le recoit, fait
 *   `is_dir(dirname($path))`, et leve
 *
 *     DirectoryNotFoundException
 *     The directory "livewire-tmp" contained in SplFileInfo does not exist
 *
 * POURQUOI AUCUN TEST EXISTANT NE POUVAIT L'ATTRAPER — c'est le point qui
 * fait la valeur de ce fichier : `Livewire::test(...)->set('image', $file)`
 * passe par Testable::upload(), qui stocke sur
 * FileUploadConfiguration::disk(). Or cette methode rend en dur
 * « tmp-for-tests » des que l'application tourne en test, et
 * FileUploadConfiguration::storage() en fait un Storage::fake() — donc un
 * disque LOCAL. Le harnais de test de Livewire ne PEUT PAS produire la
 * condition de production. Il fallait construire le fichier temporaire
 * nous-memes, sur un disque dont path() est relatif.
 *
 * Ce que ce banc simule est donc exactement la condition S3, sans reseau :
 * un vrai TemporaryUploadedFile, un vrai FilesystemAdapter, une vraie
 * lecture de contenu — et une racine vide, comme S3.
 */
class TASK1623RemoteUploadTest extends TestCase
{
    private const DISQUE_DISTANT = 'task1623-distant';

    private string $racineSimulee;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        // Le contenu vit reellement quelque part — c'est le propre d'un
        // stockage distant : il SERT le fichier. Ce qu'il ne fournit pas,
        // c'est un chemin local utilisable.
        $this->racineSimulee = sys_get_temp_dir().'/task1623-'.Str::uuid();
        @mkdir($this->racineSimulee, 0777, true);

        $racine = $this->racineSimulee;

        Storage::extend(self::DISQUE_DISTANT, function ($app, $config) use ($racine) {
            $adapter = new LocalFilesystemAdapter($racine);

            // `root` VIDE : c'est toute la simulation. FilesystemAdapter::path()
            // prefixe avec cette racine, donc il rendra « livewire-tmp/x.png »
            // — un chemin relatif, exactement comme le fait le driver s3.
            return new FilesystemAdapter(new Flysystem($adapter), $adapter, ['root' => '']);
        });

        config(['filesystems.disks.'.self::DISQUE_DISTANT => ['driver' => self::DISQUE_DISTANT]]);
    }

    protected function tearDown(): void
    {
        // Le nettoyage ne porte que sur le chemin que CE test a cree.
        if (is_dir($this->racineSimulee)) {
            exec('rm -rf '.escapeshellarg($this->racineSimulee));
        }

        parent::tearDown();
    }

    /**
     * Un vrai TemporaryUploadedFile, pose sur le disque a racine vide.
     */
    private function fichierTemporaireDistant(string $nom = 'photo.png'): TemporaryUploadedFile
    {
        $vraiPng = UploadedFile::fake()->image($nom, 40, 30);
        $octets = file_get_contents($vraiPng->getPathname());

        Storage::disk(self::DISQUE_DISTANT)->put('livewire-tmp/'.$nom, $octets);

        return new TemporaryUploadedFile($nom, self::DISQUE_DISTANT);
    }

    // ── 1. LA PREMISSE, MESUREE ─────────────────────────────────────────────
    //
    // Sans cette mesure, tout le reste du fichier reposerait sur une
    // hypothese. Elle dit deux choses a la fois : le chemin local est
    // inutilisable, ET le contenu est parfaitement lisible.

    public function test_un_fichier_temporaire_distant_sert_son_contenu_mais_pas_son_chemin(): void
    {
        $fichier = $this->fichierTemporaireDistant();

        $chemin = $fichier->getPathname();

        $this->assertSame('livewire-tmp/photo.png', $chemin, 'le chemin rendu est RELATIF, comme sur s3');
        $this->assertFalse(is_dir(dirname($chemin)), 'c\'est ce repertoire introuvable que Intervention signale');
        $this->assertFalse(file_exists($chemin), 'aucun fichier local a ce chemin');

        // Et pourtant le fichier existe, et son contenu est la.
        $this->assertTrue($fichier->exists());
        $this->assertNotEmpty($fichier->get());
        $this->assertIsResource($fichier->readStream());
    }

    // ── 2. LES QUATRE CHEMINS D'UPLOAD ──────────────────────────────────────
    //
    // storeImage() est privee dans les quatre composants : c'est la ligne de
    // production exacte, atteinte avec l'objet de production exact. Un test
    // qui passerait par le harnais Livewire mesurerait un disque local et
    // resterait vert quoi qu'il arrive (cf. en-tete de classe).

    public static function cheminsDUpload(): array
    {
        return [
            'ChatLoop' => ['chatloop'],
            'MessageThread' => ['thread'],
            'CreateFeedPost' => ['creation-annonce'],
            'EditFeedPost' => ['edition-annonce'],
        ];
    }

    #[DataProvider('cheminsDUpload')]
    public function test_une_image_venue_d_un_stockage_distant_est_traitee(string $surface): void
    {
        $fichier = $this->fichierTemporaireDistant();

        $chemin = $this->appelerStoreImage($surface, $fichier);

        $this->assertStringEndsWith('.webp', $chemin);
        Storage::disk('public')->assertExists($chemin);
        $this->assertNotEmpty(Storage::disk('public')->get($chemin));
    }

    // ── 3. LA GARDE DE NON-RETOUR ───────────────────────────────────────────
    //
    // Demandee par MASTER : un test qui rougit si quelqu'un redonne un jour
    // l'OBJET fichier a Intervention au lieu de son contenu. Il ne lit pas le
    // code source — il mesure le comportement, sur les quatre surfaces d'un
    // coup, avec un fichier dont le pathname est inutilisable.

    public function test_aucune_surface_ne_redonne_le_pathname_a_intervention(): void
    {
        $surfaces = array_map(fn (array $cas) => $cas[0], array_values(self::cheminsDUpload()));

        foreach ($surfaces as $surface) {
            $fichier = $this->fichierTemporaireDistant('garde-'.$surface.'.png');

            $chemin = $this->appelerStoreImage($surface, $fichier);

            Storage::disk('public')->assertExists($chemin);
        }
    }

    // ── 4. AUCUNE REGRESSION SUR LE CAS LOCAL ───────────────────────────────

    public function test_le_cas_local_continue_de_fonctionner(): void
    {
        // Le disque temporaire par defaut du harnais : local, comme avant.
        $fichier = UploadedFile::fake()->image('locale.png', 40, 30);

        foreach (['chatloop', 'thread', 'creation-annonce', 'edition-annonce'] as $surface) {
            $chemin = $this->appelerStoreImage($surface, $fichier);

            Storage::disk('public')->assertExists($chemin);
        }
    }

    /**
     * Invoque le storeImage() reel du composant demande.
     */
    private function appelerStoreImage(string $surface, UploadedFile $fichier): string
    {
        $organizationId = '01a09a4b-403c-71f1-b804-000000000001';

        switch ($surface) {
            case 'chatloop':
                $composant = new LoopChat;
                $composant->loop = new Loop(['organization_id' => $organizationId]);
                $arguments = [$fichier, 'messages'];
                break;

            case 'thread':
                $composant = new MessageThread;
                $composant->organizationId = $organizationId;
                $arguments = [$fichier];
                break;

            case 'creation-annonce':
                $composant = new CreateFeedPost;
                $composant->image = $fichier;
                $arguments = [$organizationId];
                break;

            case 'edition-annonce':
                $composant = new EditFeedPost;
                $composant->image = $fichier;
                $arguments = [$organizationId];
                break;

            default:
                $this->fail('surface inconnue : '.$surface);
        }

        $methode = new ReflectionMethod($composant, 'storeImage');
        $methode->setAccessible(true);

        return $methode->invokeArgs($composant, $arguments);
    }
}
