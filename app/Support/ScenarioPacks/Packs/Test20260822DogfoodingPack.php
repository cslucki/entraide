<?php

namespace App\Support\ScenarioPacks\Packs;

use App\Models\Category;
use App\Models\Dossier;
use App\Models\DossierFile;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\MemberAiProfile;
use App\Models\Organization;
use App\Models\PointLedger;
use App\Models\Skill;
use App\Models\User;
use App\Services\Dossiers\FileContentExtractor;
use App\Services\Loops\LoopRootDocumentService;
use App\Services\LoopService;
use App\Support\ScenarioPacks\Contracts\ScenarioPackDefinition;
use App\Support\ScenarioPacks\ScenarioPackEntityPurger;
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
 *  - une Loop par repertoire de corpus DECLARE dans `LOOP_DIRECTORIES`
 *    (T1274 : 10 noms, dans l'ordre ; le nom de la Loop EST le nom du
 *    repertoire, accents et espaces compris — jamais scanne a l'aveugle :
 *    un repertoire declare absent du disque fait echouer le chargement en
 *    le nommant, un repertoire present mais non declare — `CV_profils/`,
 *    sources factuelles des personas, ou tout futur annexe — est ignore
 *    sans aucune regle sur son nom), par la chaine
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
 *
 * TASK-1274 (version 1.1.0) — SOCLE DATASET FR, donnees dans
 * `Test20260822DogfoodingDataset` :
 *  - profils humains des 4 personas (champs exiges par
 *    `EnsureProfileComplete`, `preferred_locale = fr`, telephones DEMO jamais
 *    affiches, liens publics). Les personas restent `reused` (le pack ne les
 *    cree pas) et sont MIS A JOUR — decision produit Cyril (brief T1274),
 *    ecart assume au contrat T1245 « reused = jamais mute » : la mise a jour
 *    a lieu APRES `track()` (qui n'examine que l'instance qu'on lui passe),
 *    et ni `reset` ni `delete` ne restaurent ces champs (pas de
 *    snapshot/restore en V1) ;
 *  - points de bienvenue par le mecanisme canonique du produit
 *    (`RegisteredUserController`, `AdminController::storeUser`) : DOUBLE
 *    ECRITURE `points_balance` + ligne `point_ledger` `welcome_bonus`, jamais
 *    l'une sans l'autre ; idempotent (une ligne `welcome_bonus` existante pour
 *    l'utilisateur dans cette Organization = retrouvee, jamais doublee). La
 *    ligne de ledger EST inscrite au registre (`point_ledger`, `created` si
 *    ce chargement l'a ecrite, `reused` si elle preexistait) et la balance
 *    DERIVE du ledger : apres chaque passage `points_balance = SUM(delta)`
 *    des lignes de l'utilisateur dans cette Organization, par la primitive
 *    unique `ScenarioPackEntityPurger::realignPointsBalance()` — la meme
 *    que le purger applique apres avoir retire une ligne `created` au
 *    `delete`/`reset`. Un persona `reused` avec un historique anterieur le
 *    garde integralement : le pack ne retire que sa propre ligne ;
 *  - referentiels de l'Organization : 6 `Category` + 37 `Skill` issus des CV
 *    (`created`, purgeables ; les skills sont inscrits APRES les categories,
 *    donc purges AVANT elles, FK-safe) ;
 *  - 4 `MemberAiProfile` publies, en francais, coherents avec les CV
 *    (`created`, purgeables ; `member_ai_profile_interactions` et
 *    `profile_agent_conversations` cascadent en base).
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

    /**
     * TASK-1274 — les 10 repertoires de corpus, DECLARES, dans l'ordre des
     * Boucles. C'est la declaration qui fait foi, pas le disque : un
     * repertoire declare absent fait echouer le chargement (corpus
     * incomplet = bruyant), un repertoire present mais absent d'ici est
     * ignore (c'est ce qui protege `CV_profils/` et tout annexe futur, sans
     * aucune regle sur leur nom).
     *
     * @var list<string>
     */
    public const LOOP_DIRECTORIES = [
        '01-COMMUNICATION',
        '02-DESIGN',
        '03-Post LinkedIN',
        '04-Screens',
        '05-Pour-la-beta1',
        '06-Pour_Boucles',
        '07-Plan-262 Définition boucles et IA',
        "08-Protocole d'emergence",
        '09-UT Dallas',
        '10-Aria projet européen',
    ];

    public function __construct(
        private readonly LoopService $loops,
        private readonly LoopRootDocumentService $rootDocuments,
        // Porte la primitive de realignement balance/ledger (T1274), partagee
        // avec le retrait : aucune logique comptable dupliquee dans le pack.
        private readonly ScenarioPackEntityPurger $purger,
    ) {}

    public function packId(): string
    {
        return self::PACK_ID;
    }

    public function packVersion(): string
    {
        return '1.1.0';
    }

    public function packName(): string
    {
        return 'Dogfooding Cyril — test20260822';
    }

    public function purpose(): string
    {
        return 'Charger les vrais documents de travail de Cyril (10 Boucles, leurs Dossiers racines, 83 fichiers) dans l\'Organization isolee test20260822 pour le dogfooding IA/RAG, sans declencher aucune indexation ; puis le socle FR (T1274) : profils humains des 4 personas, 6 categories et 37 skills issus des CV, points de bienvenue, 4 profils IA publies.';
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

        // TASK-1274 — socle FR : personas utilisables, referentiels, points,
        // profils IA. Avant les Boucles pour que `services.category_id`
        // (T1276) trouve ses categories quel que soit l'etat du corpus.
        $this->humanProfiles($personas);
        $this->welcomePoints($organization, $personas, $registrar);
        $categories = $this->categories($organization, $registrar);
        $this->skills($organization, $categories, $registrar);
        $this->aiProfiles($organization, $personas, $registrar);

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
     * TASK-1274 — profils humains des 4 personas : les champs exiges par
     * `EnsureProfileComplete` + locale FR, visibilite, liens publics. Les
     * personas sont `reused` (deja inscrits par `personas()`) et mis a jour
     * ICI, apres leur inscription ; `update()` n'ecrit rien si les valeurs
     * sont deja celles du dataset (rejeu). Aucune coordonnee reelle : voir
     * `Test20260822DogfoodingDataset`.
     *
     * @param  array<string, User>  $personas
     */
    private function humanProfiles(array $personas): void
    {
        foreach (Test20260822DogfoodingDataset::HUMAN_PROFILES as $key => $attributes) {
            $personas[$key]->update($attributes);
        }
    }

    /**
     * TASK-1274 — points de bienvenue : le ledger est la source de verite
     * comptable, la balance en est la somme.
     *
     *  - LOAD : la ligne `welcome_bonus` (user × Organization) est retrouvee
     *    ou creee — jamais doublee —, inscrite au registre (`point_ledger`,
     *    `created` si ce passage l'a ecrite, `reused` si elle preexistait :
     *    un persona credite par l'ecran admin avant le pack garde sa ligne
     *    au retrait), puis `points_balance` est realignee sur `SUM(delta)`
     *    du ledger de l'utilisateur dans cette Organization ;
     *  - LOAD repete / RESET : ligne retrouvee, aucun nouveau credit, meme
     *    balance qu'apres un chargement unique ;
     *  - DELETE : la ligne `created` est purgee par `ScenarioPackEntityPurger`
     *    qui realigne la balance sur les lignes restantes (historique
     *    anterieur d'un persona `reused` conserve integralement).
     *
     * Note (ecart constate, non corrige — hors perimetre T1274) : rien dans
     * le produit ne lit `organizations.welcome_points` ; la valeur est codee
     * en dur (100, `RegisteredUserController`). Le pack applique la MEME
     * valeur codee en dur.
     *
     * @param  array<string, User>  $personas
     */
    private function welcomePoints(Organization $organization, array $personas, ScenarioPackEntityRegistrar $registrar): void
    {
        foreach ($personas as $key => $user) {
            $line = PointLedger::query()
                ->where('user_id', $user->id)
                ->where('organization_id', $organization->id)
                ->where('reason', Test20260822DogfoodingDataset::WELCOME_REASON)
                ->orderBy('created_at')
                ->first();

            if ($line === null) {
                $line = PointLedger::create([
                    'user_id' => $user->id,
                    'transaction_id' => null,
                    'delta' => Test20260822DogfoodingDataset::WELCOME_POINTS,
                    'organization_id' => $organization->id,
                    'reason' => Test20260822DogfoodingDataset::WELCOME_REASON,
                ]);
            }

            $registrar->track('point_ledger', "{$key}:welcome_bonus", $line);

            $user->points_balance = $this->purger->realignPointsBalance($user->id, $organization);
        }
    }

    /**
     * TASK-1274 — 6 categories de l'Organization (`name_b2c` affiche en
     * `transactions_naming = b2c`, `name_b2b` NOT NULL). Cle d'idempotence :
     * (`organization_id`, `slug`).
     *
     * @return array<string, Category> slug -> Category
     */
    private function categories(Organization $organization, ScenarioPackEntityRegistrar $registrar): array
    {
        $categories = [];

        foreach (Test20260822DogfoodingDataset::CATEGORIES as $slug => $attributes) {
            $category = Category::query()->updateOrCreate(
                ['organization_id' => $organization->id, 'slug' => $slug],
                $attributes,
            );
            $registrar->track('category', $slug, $category);
            $categories[$slug] = $category;
        }

        return $categories;
    }

    /**
     * TASK-1274 — skills issus des CV, rattaches a leur categorie. Cle
     * d'idempotence : (`organization_id`, `slug`), slug derive du nom.
     *
     * @param  array<string, Category>  $categories
     */
    private function skills(Organization $organization, array $categories, ScenarioPackEntityRegistrar $registrar): void
    {
        foreach (Test20260822DogfoodingDataset::SKILLS as $categorySlug => $names) {
            foreach ($names as $name) {
                $slug = Str::slug($name);
                $skill = Skill::query()->updateOrCreate(
                    ['organization_id' => $organization->id, 'slug' => $slug],
                    ['category_id' => $categories[$categorySlug]->id, 'name' => $name],
                );
                $registrar->track('skill', $slug, $skill);
            }
        }
    }

    /**
     * TASK-1274 — 4 profils IA membre publies, en francais. Cle
     * d'idempotence : (`organization_id`, `user_id`). Les horodatages de
     * validation/publication sont poses au premier passage et conserves
     * ensuite (rejeu = aucune re-publication).
     *
     * @param  array<string, User>  $personas
     */
    private function aiProfiles(Organization $organization, array $personas, ScenarioPackEntityRegistrar $registrar): void
    {
        foreach (Test20260822DogfoodingDataset::AI_PROFILES as $key => $data) {
            $user = $personas[$key];

            $profile = MemberAiProfile::query()->firstOrNew([
                'organization_id' => $organization->id,
                'user_id' => $user->id,
            ]);

            $profile->fill([
                'status' => MemberAiProfile::STATUS_PUBLISHED,
                'locale' => 'fr',
                'member_profile_summary' => $data['member_profile_summary'],
                'service_scope' => $data['service_scope'],
                'experience_context' => $data['experience_context'],
                'preferred_contact_action' => $data['preferred_contact_action'],
                'tone' => $data['tone'],
                'generated_summary' => $data['generated_summary'],
                'target_audience' => $data['target_audience'],
                'problems_helped' => $data['problems_helped'],
                'skills' => $data['skills'],
                'help_types' => $data['help_types'],
                'boundaries' => $data['boundaries'],
                'good_request_examples' => $data['good_request_examples'],
                'bad_request_examples' => $data['bad_request_examples'],
                'structured_profile' => [
                    'summary' => $data['member_profile_summary'],
                    'service_scope' => $data['service_scope'],
                    'experience_context' => $data['experience_context'],
                    'skills' => $data['skills'],
                    'help_types' => $data['help_types'],
                    'target_audience' => implode(', ', $data['target_audience']),
                    'problems_helped' => implode(' ; ', $data['problems_helped']),
                    'boundaries' => $data['boundaries'],
                    'preferred_contact_action' => $data['preferred_contact_action'],
                    'tone' => $data['tone_label'],
                ],
                'metadata' => ['source' => 'human_declaration', 'scenario' => self::PACK_ID, 'task' => 'TASK-1274'],
                'disabled_at' => null,
            ]);

            if (! $profile->exists) {
                $profile->validated_at = now();
                $profile->published_at = now();
                $profile->last_saved_at = now();
            } elseif ($profile->isDirty()) {
                $profile->last_saved_at = now();
            }

            $profile->save();

            $registrar->track('ai_profile', $key, $profile);
        }
    }

    /**
     * Les repertoires de corpus DECLARES (`LOOP_DIRECTORIES`), dans l'ordre
     * de la declaration. Le nom de la Loop EST le nom du repertoire, tel
     * quel. Aucun scan : un repertoire declare absent du disque est une
     * erreur nommee, un repertoire present mais non declare n'existe pas
     * pour le pack.
     *
     * @return array<string, string> nom -> chemin absolu
     */
    private function loopDirectories(string $sourceDirectory): array
    {
        $directories = [];

        foreach (self::LOOP_DIRECTORIES as $name) {
            $path = $sourceDirectory.'/'.$name;

            if (! is_dir($path)) {
                throw new RuntimeException(
                    "Test20260822DogfoodingPack : repertoire de corpus declare absent du disque : '{$name}' (attendu : {$path}). ".
                    'Le corpus est incomplet, rien n\'a ete charge.'
                );
            }

            $directories[$name] = $path;
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
