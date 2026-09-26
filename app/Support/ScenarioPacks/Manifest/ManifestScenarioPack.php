<?php

namespace App\Support\ScenarioPacks\Manifest;

use App\Models\BlogPost;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\MemberAiProfile;
use App\Models\Organization;
use App\Models\ScenarioPackEntity;
use App\Models\User;
use App\Services\Loops\LoopRootDocumentService;
use App\Services\LoopService;
use App\Support\ScenarioPacks\Contracts\ScenarioPackDefinition;
use App\Support\ScenarioManifest\ManifestAvatarBank;
use App\Support\ScenarioManifest\ManifestSchema;
use App\Support\ScenarioPacks\ScenarioPackEntityRegistrar;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

/**
 * TASK-1642 — l'adaptateur de la forme conceptuelle de la spec 4.3 :
 *
 *     ScenarioPackDefinition  <--  ManifestScenarioPack  <--  ScenarioManifest
 *
 * Il ne reimplemente RIEN du moteur. Le loader, le registrar, le resetter, le
 * remover et le garde Organization restent les autorites de cycle de vie : ce
 * pack se contente de traduire un document declaratif en appels aux primitives
 * metier canoniques, exactement comme les quatre packs PHP historiques, dont
 * le contrat n'est pas touche.
 *
 * ## Perimetre de ce fichier — le socle, et lui seul
 *
 * Ce que cette classe ecrit elle-meme reste le squelette du monde :
 *
 *     users/personas · MemberAiProfile · Loops · memberships · Dossiers
 *     racines (et leur document racine, cree par la primitive canonique)
 *
 * CORE (T1643) et TRAINING (T1644) sont depuis materialises, chacun par un
 * applier dedie appele en fin de `apply()` — le decoupage n'est pas cosmetique :
 * le socle avait ete revu seul, et il doit rester lisible seul.
 *
 * `CourseQuiz` reste le seul objet du langage V1 a n'etre pas materialise, par
 * DECISION de la spec 12.6 qui le reporte a Manifest V1.1.
 *
 * ## Primitives canoniques, jamais les raccourcis de Seeder
 *
 * `LoopService::createLoopForOrg()` cree la Boucle, son membership `owner`, le
 * preset de Cards de son type ET son Dossier racine avec son document racine.
 * C'est la raison pour laquelle T1642 obtient les Dossiers racines "pour
 * rien" : ils ne sont pas charges par une branche dediee, ils sont l'effet
 * normal de la primitive que le produit utilise deja partout ailleurs.
 *
 * Aucun mass assignment du JSON : chaque champ ecrit est nomme ici, un par un.
 */
class ManifestScenarioPack implements ScenarioPackDefinition
{
    /**
     * Prefixe d'identite du pack. Deux manifestes differents donnent deux
     * `pack_id` differents ; le meme manifeste garde le sien entre deux
     * versions, ce qui est la condition de l'idempotence du registre.
     */
    public const PACK_ID_PREFIX = 'manifest:';

    public function __construct(private readonly ScenarioManifest $manifest) {}

    public function packId(): string
    {
        return self::PACK_ID_PREFIX.$this->manifest->id();
    }

    public function packVersion(): string
    {
        return $this->manifest->version();
    }

    public function packName(): string
    {
        return $this->manifest->name();
    }

    public function purpose(): string
    {
        return $this->manifest->purpose();
    }

    public function manifest(): ScenarioManifest
    {
        return $this->manifest;
    }

    public function apply(Organization $organization, ScenarioPackEntityRegistrar $registrar): void
    {
        $users = $this->applyUsers($organization, $registrar);
        $loops = $this->applyLoops($organization, $registrar, $users);

        // L'ancre de temps du CHARGEMENT (spec 6.3 : un unique
        // `load_started_at`, dont tous les offsets derivent). Elle est capturee
        // ICI, une seule fois, et passee aux deux appliers.
        //
        // Elle vivait dans le constructeur de `ManifestCoreApplier` tant qu'il
        // etait seul a deriver des offsets. Avec TRAINING, deux appliers en
        // derivent dans le meme passage : chacun capturant la sienne, une remise
        // et un message declares au meme offset ne tomberaient plus au meme
        // instant, et la spec 6.3 serait violee sans que rien ne le dise.
        $loadStartedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        // TASK-1643 — les familles CORE non-Training, dans un collaborateur
        // dedie. Le socle ci-dessus etait le contrat de T1642 et ses garanties
        // sont deja revues : on doit pouvoir lire, et retirer, CORE sans
        // relire le socle.
        ['articles' => $articles, 'files' => $files] = (new ManifestCoreApplier(
            $this->manifest,
            $this->packId(),
            $loadStartedAt,
        ))->apply($organization, $registrar, $users, $loops);

        // TASK-1644 — les cinq familles TRAINING, meme raison de decoupage.
        // Elles recoivent les index d'articles et de fichiers que CORE vient de
        // produire : une Sequence les REFERENCE (spec 12.2), elle n'en recopie
        // jamais le contenu.
        //
        // `CourseQuiz` reste hors scope par DECISION de la spec 12.6 (reporte a
        // Manifest V1.1).
        (new ManifestTrainingApplier($this->manifest, $this->packId(), $loadStartedAt))
            ->apply($organization, $registrar, $users, $loops, $articles, $files);
    }

    /**
     * Personas et profils IA.
     *
     * @return array<string, User> stable key du manifeste -> User
     */
    /** Disque des avatars de banque : celui que `users.avatar` sert deja. */
    private const AVATAR_DISK = 'public';

    /** Emplacement d'APPLICATION, partage par toutes les sandboxes. */
    private const AVATAR_DIRECTORY = 'scenario-avatars';

    private function applyUsers(Organization $organization, ScenarioPackEntityRegistrar $registrar): array
    {
        $users = [];

        foreach ($this->manifest->collection('users') as $declared) {
            $key = (string) $declared->key;

            $user = User::updateOrCreate(
                ['email' => $this->sandboxEmail($organization, (string) $declared->email)],
                [
                    'organization_id' => $organization->id,
                    'first_name' => (string) $declared->first_name,
                    'name' => (string) $declared->name,
                    'bio' => $declared->bio,
                    'location' => $declared->location,
                    'is_available' => (bool) $declared->available,
                    // `organization_role` vaut `admin` ou `member`, jamais
                    // `superadmin` : le Validator le refuse deja, et rien ici
                    // ne pourrait l'accorder.
                    'is_admin' => $declared->organization_role === 'admin',
                    'preferred_locale' => $this->manifest->locale(),
                    'password' => Hash::make(bin2hex(random_bytes(16))),
                    'banned_at' => null,
                    // TASK-1647 — fidelite : un avatar DECLARE doit etre
                    // reellement ecrit. `null` reste parfaitement valide et
                    // laisse le fallback initiales (spec 13).
                    'avatar' => $this->resolveAvatar($declared->avatar ?? null),
                ],
            );

            // Credential NON EXPORTABLE : le manifeste ne transporte aucun mot
            // de passe (spec 7.2) et n'en recevra jamais un en retour. Chaque
            // persona recoit un secret aleatoire que personne ne connait ;
            // l'acces a une sandbox passera par les mecanismes du produit,
            // pas par un mot de passe partage ecrit dans un JSON.

            // `email_verified_at` n'est pas `$fillable` : le passer a
            // `updateOrCreate` ne l'ecrirait pas. Un persona non verifie peut
            // se faire arreter par une garde de verification avant meme
            // d'atteindre le produit, ce qui rendrait la demonstration
            // irreproductible.
            if ($user->email_verified_at === null) {
                $user->forceFill(['email_verified_at' => now()])->saveQuietly();
            }

            $registrar->track('manifest_user', $key, $user);

            $this->applyMemberAiProfile($organization, $registrar, $key, $user, $declared->member_ai_profile ?? null);

            $users[$key] = $user;
        }

        return $users;
    }

    /**
     * Resoudre une cle logique d'avatar en un chemin reellement servable.
     *
     * ## Pourquoi un asset PARTAGE, et non un fichier par sandbox
     *
     * Le purger ne sait nettoyer un fichier que par une entite qui porte des
     * colonnes `disk` et `path` ; `users.avatar` n'est qu'une colonne de
     * chemin. Un fichier ecrit A CHAQUE chargement ne serait donc jamais
     * nettoye au reset ni au remove, et chaque sandbox en laisserait derriere
     * elle. L'asset est donc publie UNE FOIS, a un emplacement d'application
     * partage par toutes les sandboxes : l'ensemble des fichiers est ferme
     * (une par cle de la banque) et ne croit pas avec les chargements. Il n'y
     * a rien a nettoyer parce qu'il n'y a rien de cree par chargement.
     *
     * ## Ce qui ne vient jamais du manifeste
     *
     * Le document ne cite qu'une CLE logique. Le nom de banque est la
     * constante du schema, pas une chaine libre ; le chemin physique est
     * derive ici. Un manifeste ne peut donc designer aucun fichier.
     *
     * Rejeu : l'asset present n'est pas reecrit, et la meme valeur de colonne
     * est reposee. Cle inconnue ou asset manquant : on rend `null` plutot que
     * d'echouer — le Validator refuse deja une cle hors index
     * (`AVATAR_NOT_FOUND`), et un persona sans photo reste utilisable.
     */
    private function resolveAvatar(mixed $declared): ?string
    {
        if (! is_string($declared) || $declared === '') {
            return null;
        }

        $bank = ManifestSchema::AVATAR_BANK;
        $content = ManifestAvatarBank::asset($bank, $declared);

        if ($content === null) {
            return null;
        }

        $path = self::AVATAR_DIRECTORY.'/'.$bank.'/'.$declared.'.'.ManifestAvatarBank::ASSET_EXTENSION;
        $disk = Storage::disk(self::AVATAR_DISK);

        // `Storage` et non le systeme de fichiers : le disque est S3 en
        // production et local ailleurs, et les deux doivent se comporter
        // pareil. Jamais d'ecrasement : l'asset est immuable pour une version
        // de banque donnee, et le reecrire a chaque chargement serait une
        // ecriture inutile sur un stockage distant.
        if (! $disk->exists($path)) {
            $disk->put($path, $content, ['ContentType' => ManifestAvatarBank::ASSET_MEDIA_TYPE]);
        }

        return $path;
    }

    private function applyMemberAiProfile(
        Organization $organization,
        ScenarioPackEntityRegistrar $registrar,
        string $userKey,
        User $user,
        ?object $declared,
    ): void {
        if ($declared === null) {
            return;
        }

        $profile = MemberAiProfile::updateOrCreate(
            ['organization_id' => $organization->id, 'user_id' => $user->id],
            [
                'status' => (string) $declared->status,
                'locale' => $this->manifest->locale(),
                'member_profile_summary' => $declared->summary,
                'service_scope' => $declared->service_scope,
                'experience_context' => $declared->experience_context,
                'target_audience' => $declared->target_audience,
                'problems_helped' => $declared->problems_helped,
                'skills' => $declared->skills,
                'help_types' => $declared->help_types,
                'boundaries' => $declared->boundaries,
                'preferred_contact_action' => $declared->preferred_contact_action,
                'tone' => $declared->tone,
            ],
        );

        $registrar->track('manifest_member_ai_profile', $userKey, $profile);
    }

    /**
     * Boucles, memberships et Dossiers racines.
     *
     * @param  array<string, User>  $users
     * @return array<string, Loop>
     */
    private function applyLoops(Organization $organization, ScenarioPackEntityRegistrar $registrar, array $users): array
    {
        $loops = [];

        foreach ($this->manifest->collection('loops') as $declared) {
            $key = (string) $declared->key;
            $owner = $users[(string) $declared->owner] ?? null;

            if ($owner === null) {
                // Injoignable sur un manifeste VALID : le Validator resout
                // tout le graphe avant Load (spec 8.2). La garde reste, parce
                // qu'un adaptateur ne doit pas supposer que son appelant a
                // valide — il doit echouer, pas ecrire a moitie.
                throw ManifestNotLoadableException::unresolvedReference('users', (string) $declared->owner);
            }

            $loop = $this->findLoopByManifestKey($organization, $key)
                ?? app(LoopService::class)->createLoopForOrg(
                    $owner,
                    $organization->id,
                    (string) $declared->name,
                    (string) $declared->description,
                    (string) $declared->visibility,
                    null,
                    (string) $declared->access_mode,
                    (string) $declared->type,
                );

            $registrar->track('manifest_loop', $key, $loop);
            $this->trackRootDossier($registrar, $key, $loop);
            $this->applyMemberships($registrar, $key, $loop, $users);

            $loops[$key] = $loop;
        }

        return $loops;
    }

    /**
     * Le Dossier racine n'est pas charge par une branche dediee : il est
     * l'effet de `createLoopForOrg()`. On l'INSCRIT tout de meme au registre,
     * sans quoi le retrait le laisserait derriere lui dans une sandbox que le
     * pack a pourtant entierement produite.
     */
    private function trackRootDossier(ScenarioPackEntityRegistrar $registrar, string $loopKey, Loop $loop): void
    {
        $service = app(LoopRootDocumentService::class);

        // `ensureRootDossier()` est la primitive canonique ET idempotente :
        // elle rend le Dossier racine deja cree par `createLoopForOrg()`.
        $dossier = $service->ensureRootDossier($loop);
        $registrar->track('manifest_root_dossier', $loopKey, $dossier);

        $dossier->refresh();
        $document = $dossier->root_blog_post_id ? BlogPost::find($dossier->root_blog_post_id) : null;

        if ($document !== null) {
            $registrar->track('manifest_root_document', $loopKey, $document);
        }
    }

    /**
     * @param  array<string, User>  $users
     */
    private function applyMemberships(ScenarioPackEntityRegistrar $registrar, string $loopKey, Loop $loop, array $users): void
    {
        $service = app(LoopService::class);

        foreach ($this->manifest->collection('memberships') as $declared) {
            if ((string) $declared->loop !== $loopKey) {
                continue;
            }

            $user = $users[(string) $declared->user] ?? null;

            if ($user === null) {
                continue;
            }

            // `addMember()` LEVE si la ligne existe deja : ce n'est pas une
            // primitive idempotente. Deux situations la rencontrent
            // legitimement — le membership `owner`, deja pose par
            // `createLoopForOrg()`, et tout rejeu du chargement. On lit donc
            // d'abord, et on ne cree que ce qui manque.
            //
            // On ne recree PAS la ligne a la main pour autant : `addMember()`
            // porte la garde cross-tenant (un User d'une autre Organization
            // est refuse) et l'etat `active`. Court-circuiter la primitive
            // pour "faire plus simple" reviendrait a normaliser le nouveau
            // systeme sur un raccourci de Seeder, ce que la spec 10.3
            // interdit.
            $existing = LoopMember::query()
                ->where('loop_id', $loop->id)
                ->where('user_id', $user->id)
                ->first();

            $member = $existing ?? $service->addMember($loop, $user, (string) $declared->role);

            // Note d'ownership : le membership `owner` est cree A L'INTERIEUR
            // de `createLoopForOrg()`, qui ne rend pas l'instance. Relu ici,
            // il s'inscrit donc comme `reused` et non `created` (piege
            // documente dans ScenarioPackEntityRegistrar). Le retrait reste
            // malgre tout complet : `loop_members.loop_id` est en
            // `ON DELETE CASCADE`, et la Boucle, elle, est bien `created`.
            $registrar->track('manifest_membership', $loopKey.':'.$declared->user, $member);
        }
    }

    /**
     * Retrouve la Boucle deja creee par un chargement anterieur de CE pack,
     * via le registre — jamais par son nom ni par un slug devine.
     */
    private function findLoopByManifestKey(Organization $organization, string $key): ?Loop
    {
        $row = ScenarioPackEntity::query()
            ->where('organization_id', $organization->id)
            ->whereHas('scenarioPackLoad', fn ($query) => $query->where('pack_id', $this->packId()))
            ->where('entity_type', 'manifest_loop')
            ->where('internal_key', $key)
            ->first();

        $entity = $row?->resolveEntity();

        return $entity instanceof Loop ? $entity : null;
    }

    /**
     * Adresse de la persona DANS CETTE SANDBOX.
     *
     * `users.email` est unique GLOBALEMENT. Or la spec 10.2 impose qu'un
     * second chargement du meme manifeste produise une AUTRE sandbox : sans
     * derivation, les 22 personas d'AMT entreraient en collision avec ceux de
     * la premiere, et le second chargement echouerait sur une contrainte
     * d'unicite — en laissant croire a un probleme de donnees alors que c'est
     * le contrat qui l'exige.
     *
     * Le domaine est donc prefixe par le slug REEL de la sandbox, celui que
     * BouclePro a choisi. Consequences voulues :
     *  - le suffixe `.test` est conserve, donc la garde d'adresse fictive ;
     *  - la derivation est deterministe : rejouer le chargement dans la MEME
     *    sandbox retrouve la meme adresse, donc le meme User ;
     *  - deux sandboxes ne partagent jamais une identite.
     */
    private function sandboxEmail(Organization $organization, string $declaredEmail): string
    {
        [$local, $domain] = explode('@', $declaredEmail, 2);

        return $local.'@'.$organization->slug.'.'.$domain;
    }
}
