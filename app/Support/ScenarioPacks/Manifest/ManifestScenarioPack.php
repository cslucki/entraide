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
use App\Support\ScenarioPacks\ScenarioPackEntityRegistrar;
use Illuminate\Support\Facades\Hash;

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
 * ## Perimetre FOUNDATION de T1642
 *
 * Seul le squelette du monde est materialise :
 *
 *     users/personas · MemberAiProfile · Loops · memberships · Dossiers
 *     racines (et leur document racine, cree par la primitive canonique)
 *
 * Les articles, fichiers, messages, entraide, collaboration et Training sont
 * declares dans le manifeste et DELIBEREMENT non charges : ils appartiennent
 * aux TASKs suivantes. Un pack qui en chargerait une partie "puisqu'on y est"
 * serait un Core Loader cache, impossible a reviser.
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

        // TASK-1643 — les familles CORE non-Training, dans un collaborateur
        // dedie. Le socle ci-dessus etait le contrat de T1642 et ses garanties
        // sont deja revues : on doit pouvoir lire, et retirer, CORE sans
        // relire le socle.
        //
        // TRAINING reste hors scope : les modules, sequences, progressions,
        // travaux et remises sont declares par le manifeste et deliberement
        // non materialises.
        (new ManifestCoreApplier($this->manifest, $this->packId()))
            ->apply($organization, $registrar, $users, $loops);
    }

    /**
     * Personas et profils IA.
     *
     * @return array<string, User> stable key du manifeste -> User
     */
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
