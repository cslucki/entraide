<?php

namespace App\Support\ScenarioPacks\Packs;

use App\Models\Dossier;
use App\Models\DossierFile;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use App\Services\Dossiers\FileContentExtractor;
use App\Services\Loops\LoopRootDocumentService;
use App\Services\LoopService;
use App\Support\ScenarioPacks\Contracts\ScenarioPackDefinition;
use App\Support\ScenarioPacks\ScenarioPackEntityRegistrar;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\File;

/**
 * TASK-1269 — dataset de dogfooding IA/RAG de Cyril, dans l'Organization
 * ISOLEE `test20260822` (decision Cyril 2026-08-22 20:38 : `main` n'est plus
 * le dataset de dogfooding, rien n'y est supprime).
 *
 * Ce que le pack cree, dans `test20260822` UNIQUEMENT :
 *  - une Loop par sous-dossier du repertoire source (les noms sont lus sur le
 *    disque, accents et espaces compris — jamais recopies), par la chaine
 *    canonique `LoopService::createLoopForOrg()` -> membre `owner` ->
 *    preset de cartes -> `LoopRootDocumentService::ensureRootDocument()`
 *    (Dossier racine tenu par la Loop + document racine), PAS le raccourci
 *    `Loop::updateOrCreate` + `Dossier::updateOrCreate` du seeder ArtSciLab ;
 *  - AUCUN sous-dossier « Documents » (decision Cyril) : le Dossier RACINE
 *    canonique de chaque Loop, nomme comme la Loop, porte directement les
 *    fichiers ;
 *  - un `DossierFile` par fichier du sous-dossier, ecrit sur le disque
 *    `dossier_files` sous `dossier-files/{dossier_id}/` (meme arborescence
 *    que l'upload UI), `original_name` preserve, `mime_type` determine par le
 *    MEME mecanisme que l'upload UI — `Symfony\...\File::getMimeType()`,
 *    detection sur le CONTENU (un `.md` ressort `text/plain`, comme a
 *    l'upload ; il reste indexable : `FileContentExtractor::SUPPORTED_MIME_TYPES`
 *    + extension) —, `size_bytes`, `checksum_sha256`, `source = upload`.
 *
 * Ce que le pack NE fait PAS :
 *  - creer l'Organization ni les comptes : `test20260822` et les 4 personas
 *    (`test_cyril`, `test_roger`, `test_kiran`, `test_sana @bouclepro.test`)
 *    sont crees par le SuperAdmin (ecrans `admin.organizations.store`,
 *    `admin.users.store`) AVANT le chargement ; le pack les retrouve
 *    (`reused`, jamais modifies, jamais supprimes) et refuse de tourner s'il
 *    en manque un ;
 *  - dispatcher le moindre job : la creation des `DossierFile` est enveloppee
 *    dans `DossierFile::withoutEvents()` — closure qui ne contient QUE
 *    l'ecriture de la ligne, pour que `DossierFileObserver::created()` ne
 *    pousse pas un `IndexDossierFileChunks` sur la queue `default` (queue
 *    sans worker sur la surface Apache, qui porte deja 138 jobs historiques).
 *    La chaine Loop, elle, ne dispatche rien (`BlogPostObserver::updated()`
 *    ne reagit qu'a `content/status/published_at`, que `designate()` ne
 *    touche pas). L'indexation viendra plus tard, explicitement, par
 *    `dossiers:index-files test20260822 --queue=dossier-files-indexing`
 *    (TASK-1268), apres validation du credential par Cyril.
 *
 * Ownership (TASK-1245) : `createLoopForOrg()` cree le Dossier racine, le
 * document racine et le membre `owner` sans rendre ces instances ; le pack les
 * relit, et quand la Loop vient d'etre creee par CE passage
 * (`$loop->wasRecentlyCreated`), ces trois entites l'ont ete aussi — le
 * service garantit qu'elles n'existaient pas avant la Loop. Le pack reporte
 * donc ce signal sur les instances relues avant de les declarer, pour que le
 * registre les tienne pour `created` (purgeables au retrait : le document
 * racine n'est PAS cascade par la suppression de la Loop, il resterait
 * orphelin sinon). Au rejeu, rien n'est `wasRecentlyCreated` et le registre
 * garde l'ownership fixe au premier passage.
 */
class Test20260822DogfoodingPack implements ScenarioPackDefinition
{
    public const PACK_ID = 'test20260822-dogfooding';

    public const ORGANIZATION_SLUG = 'test20260822';

    public const DISK = 'dossier_files';

    /** Cle de configuration du repertoire source (surchargeable en test). */
    public const SOURCE_CONFIG_KEY = 'scenario_packs.sources.'.self::PACK_ID;

    public const CREATOR_EMAIL = 'test_cyril@bouclepro.test';

    public const PERSONA_EMAILS = [
        'test_cyril' => 'test_cyril@bouclepro.test',
        'test_roger' => 'test_roger@bouclepro.test',
        'test_kiran' => 'test_kiran@bouclepro.test',
        'test_sana' => 'test_sana@bouclepro.test',
    ];

    public function __construct(
        private readonly LoopService $loops,
        private readonly LoopRootDocumentService $rootDocuments,
    ) {}

    public function packId(): string
    {
        return self::PACK_ID;
    }

    public function packVersion(): string
    {
        return '1.0.0';
    }

    public function packName(): string
    {
        return 'Dogfooding Cyril — test20260822';
    }

    public function purpose(): string
    {
        return 'Charger les vrais documents de travail de Cyril (10 Boucles, leurs Dossiers racines, 83 fichiers) dans l\'Organization isolee test20260822 pour le dogfooding IA/RAG, sans declencher aucune indexation.';
    }

    public function apply(Organization $organization, ScenarioPackEntityRegistrar $registrar): void
    {
        if ($organization->slug !== self::ORGANIZATION_SLUG) {
            throw new LogicException(
                "Test20260822DogfoodingPack ne peut cibler que l'Organization '".self::ORGANIZATION_SLUG."', reçu '{$organization->slug}'."
            );
        }

        $sourceDirectory = $this->sourceDirectory();
        $personas = $this->personas($organization, $registrar);
        $creator = $personas['test_cyril'];

        foreach ($this->loopDirectories($sourceDirectory) as $loopName => $directory) {
            $loopKey = Str::slug($loopName);
            $loop = $this->loop($organization, $creator, $loopName);
            $registrar->track('loop', $loopKey, $loop);

            // Dossier racine + document racine : idempotents, tenus par la
            // Loop (`loop_id`), nommes comme elle. Aucun sous-dossier.
            $rootDocument = $this->rootDocuments->ensureRootDocument($loop, $creator);
            $rootDossier = $this->rootDocuments->ensureRootDossier($loop);
            $ownerMembership = LoopMember::query()
                ->where('loop_id', $loop->id)
                ->where('user_id', $creator->id)
                ->firstOrFail();

            if ($loop->wasRecentlyCreated) {
                $rootDossier->wasRecentlyCreated = true;
                $rootDocument->wasRecentlyCreated = true;
                $ownerMembership->wasRecentlyCreated = true;
            }

            $registrar->track('loop_member', "{$loopKey}:test_cyril", $ownerMembership);
            $registrar->track('folder', $loopKey, $rootDossier);
            $registrar->track('root_document', $loopKey, $rootDocument);

            foreach ($this->files($directory) as $absolutePath) {
                $this->dossierFile($organization, $creator, $rootDossier, $loopKey, $absolutePath, $registrar);
            }
        }
    }

    /**
     * Repertoire source : `config('scenario_packs.sources.<pack_id>')`. Les
     * vrais documents de Cyril vivent hors git (`_temp/`, gitignore) ; un test
     * pointe la cle vers une fixture temporaire.
     */
    private function sourceDirectory(): string
    {
        $directory = (string) config(self::SOURCE_CONFIG_KEY, '');

        if ($directory === '' || ! is_dir($directory)) {
            throw new RuntimeException(
                'Test20260822DogfoodingPack : repertoire source introuvable ('.self::SOURCE_CONFIG_KEY." = '{$directory}')."
            );
        }

        return rtrim($directory, '/');
    }

    /**
     * @return array<string, User> cle persona -> User de l'Organization
     */
    private function personas(Organization $organization, ScenarioPackEntityRegistrar $registrar): array
    {
        $personas = [];

        foreach (self::PERSONA_EMAILS as $key => $email) {
            $user = User::query()
                ->where('organization_id', $organization->id)
                ->where('email', $email)
                ->whereNull('banned_at')
                ->first();

            if ($user === null) {
                throw new RuntimeException(
                    "Test20260822DogfoodingPack : le compte '{$email}' n'existe pas dans l'Organization '{$organization->slug}'. ".
                    'Les comptes sont crees par le SuperAdmin avant le chargement, jamais par le pack.'
                );
            }

            $registrar->track('persona', $key, $user);
            $personas[$key] = $user;
        }

        return $personas;
    }

    /**
     * Les sous-dossiers directs du repertoire source, dans l'ordre naturel de
     * leur nom (`01-…`, `02-…`, … `10-…`). Le nom de la Loop EST le nom du
     * sous-dossier, tel quel.
     *
     * @return array<string, string> nom -> chemin absolu
     */
    private function loopDirectories(string $sourceDirectory): array
    {
        $names = array_values(array_filter(
            scandir($sourceDirectory) ?: [],
            fn (string $entry) => ! str_starts_with($entry, '.') && is_dir($sourceDirectory.'/'.$entry),
        ));
        usort($names, 'strnatcasecmp');

        $directories = [];
        foreach ($names as $name) {
            $directories[$name] = $sourceDirectory.'/'.$name;
        }

        return $directories;
    }

    /**
     * @return list<string> chemins absolus des fichiers directs du sous-dossier
     */
    private function files(string $directory): array
    {
        $names = array_values(array_filter(
            scandir($directory) ?: [],
            fn (string $entry) => ! str_starts_with($entry, '.') && is_file($directory.'/'.$entry),
        ));
        usort($names, 'strnatcasecmp');

        return array_map(fn (string $name) => $directory.'/'.$name, $names);
    }

    /**
     * La Loop nommee exactement comme le sous-dossier, retrouvee (rejeu) ou
     * creee par la chaine canonique. `public` : visible des membres de
     * l'Organization (les 4 personas), acces sur demande (defaut produit).
     */
    private function loop(Organization $organization, User $creator, string $name): Loop
    {
        $existing = Loop::query()
            ->where('organization_id', $organization->id)
            ->where('name', $name)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return $this->loops->createLoopForOrg(
            user: $creator,
            organizationId: $organization->id,
            name: $name,
            visibility: 'public',
            accessMode: Loop::ACCESS_REQUEST,
        );
    }

    /**
     * Nom de stockage d'un fichier, derive du SEUL `original_name` (slug
     * ASCII + 6 hex du nom exact, pour distinguer deux noms au meme slug) :
     * stable d'un passage a l'autre, independant de la position du fichier
     * dans son dossier — supprimer un voisin ne renumerote rien, le reset ne
     * retire que l'orphelin.
     */
    public static function storedName(string $originalName): string
    {
        $extension = Str::lower(pathinfo($originalName, PATHINFO_EXTENSION));
        $stem = Str::slug(pathinfo($originalName, PATHINFO_FILENAME)) ?: 'fichier';

        return $stem.'-'.substr(hash('sha256', $originalName), 0, 6).($extension !== '' ? '.'.$extension : '');
    }

    /**
     * Meme mecanisme que `UploadedFile::getMimeType()` dans
     * `DossierFileController::store()` : detection sur le CONTENU (un `.md`
     * ordinaire ressort `text/plain`, un PNG renomme `.md` ressort `image/png`).
     *
     * Cas releve sur le vrai corpus : un Markdown qui commence par du code
     * HTML/Blade est devine `text/html` — MIME que l'upload UI refuse
     * (`StoreDossierFileRequest`) et que `dossiers:index-files` ne retient pas,
     * alors que `FileContentExtractor::isSupported()` accepte ce fichier par
     * son extension (`txt`, `md`, `markdown`) et le traiterait comme du
     * Markdown. Pour un contenu TEXTE (`text/*`) hors
     * `SUPPORTED_MIME_TYPES` dont l'extension est une extension texte
     * supportee, la ligne porte donc le MIME que l'extracteur appliquera :
     * `text/markdown` (md, markdown) ou `text/plain` (txt). Un contenu
     * binaire n'est jamais requalifie.
     */
    private function mimeType(string $absolutePath, string $originalName): string
    {
        $guessed = (new File($absolutePath))->getMimeType() ?? 'application/octet-stream';

        if (in_array($guessed, FileContentExtractor::SUPPORTED_MIME_TYPES, true) || ! str_starts_with($guessed, 'text/')) {
            return $guessed;
        }

        return match (Str::lower(pathinfo($originalName, PATHINFO_EXTENSION))) {
            'md', 'markdown' => 'text/markdown',
            'txt' => 'text/plain',
            default => $guessed,
        };
    }

    private function dossierFile(
        Organization $organization,
        User $uploader,
        Dossier $dossier,
        string $loopKey,
        string $absolutePath,
        ScenarioPackEntityRegistrar $registrar,
    ): void {
        $originalName = basename($absolutePath);
        $storedName = self::storedName($originalName);
        $fileKey = "{$loopKey}/{$storedName}";
        $path = 'dossier-files/'.$dossier->id.'/'.$storedName;

        $contents = file_get_contents($absolutePath);
        if ($contents === false) {
            throw new RuntimeException("Test20260822DogfoodingPack : lecture impossible de {$absolutePath}.");
        }

        // TASK-1245 : un fichier deja present a ce chemin qui n'est pas celui
        // de ce chargement est un fichier preexistant -> refus, jamais ecrase.
        $registrar->assertStoragePathAvailable('folder_file', $fileKey, self::DISK, $path);

        // Le pack tourne en CLI (utilisateur cyril) alors que la surface
        // Apache lit en www-data : sans visibilite explicite, Flysystem cree
        // le repertoire du Dossier en 0700 et Apache ne peut plus servir
        // l'apercu ni le telechargement (residu connu depuis TASK-1266). La
        // racine du disque reste 2770 cyril:www-data : rien n'est expose
        // au-dela du groupe.
        $written = Storage::disk(self::DISK)->put($path, $contents, [
            'visibility' => 'public',
            'directory_visibility' => 'public',
        ]);
        if (! $written) {
            throw new RuntimeException("Test20260822DogfoodingPack : ecriture impossible de {$path} sur le disque ".self::DISK.'.');
        }

        $mimeType = $this->mimeType($absolutePath, $originalName);

        // Closure bornee : UNIQUEMENT l'ecriture de la ligne. Aucun evenement
        // Eloquent n'en sort -> DossierFileObserver (created/restored) ne
        // dispatche rien sur `default`.
        $file = DossierFile::withoutEvents(function () use ($organization, $uploader, $dossier, $path, $originalName, $mimeType, $contents) {
            $file = DossierFile::withTrashed()->updateOrCreate(
                ['organization_id' => $organization->id, 'path' => $path],
                [
                    'dossier_id' => $dossier->id,
                    'uploaded_by' => $uploader->id,
                    'disk' => self::DISK,
                    'original_name' => $originalName,
                    'display_name' => $originalName,
                    'mime_type' => $mimeType,
                    'size_bytes' => strlen($contents),
                    'checksum_sha256' => hash('sha256', $contents),
                    'source' => 'upload',
                ],
            );

            if ($file->trashed()) {
                $file->restore();
            }

            return $file;
        });

        $registrar->track('folder_file', $fileKey, $file);
    }
}
