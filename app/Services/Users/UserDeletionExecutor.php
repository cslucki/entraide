<?php

namespace App\Services\Users;

use App\Models\Dossier;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use App\Services\Dossiers\DossierTreePurger;
use App\Services\LoopGovernanceService;
use App\Services\UserDataLifecycleRegistry;
use App\Services\Users\Exceptions\UserDeletionBlockedException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1636 — la premiere suppression REELLE d'un compte, reservee au SuperAdmin.
 *
 * ## Ce que ce service est, et ce qu'il n'est pas
 *
 * Il execute ce que `UserDataLifecycleRegistry` declare depuis longtemps. Le
 * registre reste la **source de verite unique** des politiques : ce service ne
 * redeclare rien, il lit. `UserDeletionPolicyCoverageTest` garde ce lien — une
 * policy BLOCK ou TRANSFER ajoutee demain au registre et non prise en charge
 * ici fait rougir la CI, au lieu d'etre ignoree en silence.
 *
 * Ce n'est ni un pipeline, ni un moteur de strategies : deux methodes
 * publiques, `precheck()` et `execute()`.
 *
 * ## Les trois familles de refus
 *
 *  - **hard block** — la donnee interdit la suppression et rien ici ne la
 *    resout : responsable d'Organization, ledger de points, vote, copie rendue,
 *    tentative de quiz, transaction. Sept entrees BLOCK du registre.
 *  - **block resolu** — `dossiers.owner_id` est BLOCK au schema, mais
 *    `DossierTreePurger` le leve pendant l'execution. Huitieme entree BLOCK.
 *  - **sous-cas resolu (TASK-1638)** — `point_ledger` reste BLOCK, mais le
 *    registre declare qu'un type de ligne ne bloque pas : le bonus de
 *    bienvenue, ecrit par la plateforme a l'inscription. C'est un TOUT OU RIEN
 *    par compte — une seule ligne d'une autre raison et l'entree bloque. Le
 *    critere est la raison DECLAREE au registre, jamais `transaction_id`.
 *  - **gouvernance** — le dernier OWNER actif d'une Boucle bloque ; un
 *    facilitator seul ne bloque pas. Cette regle ne vient pas du schema mais de
 *    `LoopGovernanceService`, seule autorite sur le sujet.
 *  - **propriete hors-tenant (TASK-1639)** — une propriete TRANSFER rattachee a
 *    une autre Organization que son proprietaire. Elle ne peut ni rester (le
 *    RESTRICT l'interdit) ni partir vers le repreneur, qui est un AUTEUR visible
 *    dans le produit : ce serait une fausse paternite. Le refus est donc la seule
 *    issue juste, et il est rendu comme un refus metier — pas comme l'erreur SQL
 *    que le clone PROD produisait avant cette TASK.
 *
 * ## Pourquoi `execute()` recontrole tout
 *
 * `precheck()` sert l'ecran : il est lu, affiche, puis l'admin reflechit. Entre
 * les deux, une transaction a pu naitre, une Boucle changer d'owner. Le
 * recontrole a lieu **apres** le `lockForUpdate()`, dans la transaction, et
 * c'est lui qui fait autorite. Le RESTRICT pose par TASK-1635 et la migration
 * M3 reste le dernier filet, sous les deux.
 */
class UserDeletionExecutor
{
    /**
     * Entrees BLOCK du registre que ce service refuse franchement.
     *
     * @var list<string>
     */
    public const HARD_BLOCK_KEYS = [
        'orgs_as_admin',
        'point_ledger',
        'transactions_as_buyer',
        'transactions_as_seller',
        'loop_poll_votes_user_id',
        'course_submissions_user_id',
        'course_quiz_attempts_user_id',
    ];

    /**
     * Entrees BLOCK que ce service sait RESOUDRE pendant l'execution.
     *
     * @var list<string>
     */
    public const RESOLVED_BLOCK_KEYS = ['dossiers'];

    /**
     * Entrees TRANSFER du registre, et la colonne que chacune deplace.
     *
     * @var array<string, array{table: string, column: string}>
     */
    public const TRANSFERABLE = [
        'blog_posts' => ['table' => 'blog_posts', 'column' => 'user_id'],
        'feed_posts' => ['table' => 'feed_posts', 'column' => 'user_id'],
        'services' => ['table' => 'services', 'column' => 'user_id'],
        'service_requests' => ['table' => 'service_requests', 'column' => 'user_id'],
    ];

    public function __construct(
        private readonly DossierTreePurger $purger,
        private readonly LoopGovernanceService $governance,
    ) {}

    /**
     * Ce qui empecherait la suppression, et ce qu'elle emporterait.
     *
     * Ne verrouille rien et n'ecrit rien : cet etat sert l'ecran, jamais la
     * decision finale.
     *
     * @return array{blocks: list<array{key: string, count: int, message: string}>, transferable: array<string, int>, requires_transfer: bool}
     */
    public function precheck(User $user): array
    {
        $blocks = $this->detectBlocks($user);
        $transferable = $this->transferableCounts($user);

        return [
            'blocks' => $blocks,
            'transferable' => $transferable,
            'requires_transfer' => array_sum($transferable) > 0,
        ];
    }

    /**
     * Supprime reellement le compte, ou refuse sans rien avoir touche.
     *
     * @return array{transferred: array<string, int>, deleted: array<string, int>, dossiers: array<string, int>, resolved: array<string, int>}
     *
     * @throws UserDeletionBlockedException
     */
    public function execute(User $user, ?string $transferToId = null): array
    {
        return DB::transaction(function () use ($user, $transferToId) {
            // 1. Le verrou d'abord : tout ce qui suit lit un etat qui ne peut
            //    plus bouger sous nos pieds.
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->first();

            if ($locked === null) {
                throw new UserDeletionBlockedException([[
                    'key' => 'user_missing',
                    'count' => 0,
                    'message' => __('admin.user_delete.block.user_missing'),
                ]]);
            }

            // 2. Recontrole AUTORITATIF. Le precheck de l'ecran ne vaut rien ici.
            $blocks = $this->detectBlocks($locked);

            if ($blocks !== []) {
                throw new UserDeletionBlockedException($blocks);
            }

            // 3. Compteurs recalcules sous verrou.
            $transferable = $this->transferableCounts($locked);

            if (array_sum($transferable) > 0 && $transferToId === null) {
                throw new UserDeletionBlockedException([[
                    'key' => 'transfer_required',
                    'count' => array_sum($transferable),
                    'message' => __('admin.user_delete.block.transfer_required'),
                ]]);
            }

            $target = $transferToId === null ? null : $this->resolveTarget($locked, $transferToId);

            // 4. TRANSFER — un UPDATE par table, borne a l'Organization.
            $transferred = $target === null ? [] : $this->transfer($locked, $target);

            // 5. Dossiers personnels : le seul BLOCK que l'on sait resoudre.
            $dossiers = $this->purgePersonalDossiers($locked);

            // 6. Les lignes BLOCK que le registre declare resolvables.
            //
            //    On n'arrive ici QUE si le recontrole autoritatif (2) n'a rien
            //    trouve : si une seule ligne d'une autre raison existait,
            //    `point_ledger` aurait bloque et cette ligne n'aurait jamais
            //    ete atteinte. La purge est donc, par construction, un tout ou
            //    rien par compte.
            $resolved = $this->purgeResolvableRows($locked);

            // 7. Les DELETE explicites du registre.
            $deleted = $this->deleteOwnedRows($locked);

            // 8. La suppression elle-meme. Ce qui reste part en CASCADE, et les
            //    ANONYMIZE / RETAIN / DETACH deviennent NULL par le schema —
            //    aucun UPDATE applicatif ne double ce travail.
            $locked->forceDelete();

            return [
                'transferred' => $transferred,
                'deleted' => $deleted,
                'dossiers' => $dossiers,
                'resolved' => $resolved,
            ];
        });
    }

    /**
     * Les refus, dans l'ordre ou un humain les comprend.
     *
     * @return list<array{key: string, count: int, message: string}>
     */
    private function detectBlocks(User $user): array
    {
        $blocks = [];

        foreach (self::HARD_BLOCK_KEYS as $key) {
            $count = $this->countBlockingRows($user, $key);

            if ($count > 0) {
                $blocks[] = [
                    'key' => $key,
                    'count' => $count,
                    'message' => __('admin.user_delete.block.'.$key, ['count' => $count]),
                ];
            }
        }

        $lastOwnerOf = $this->loopsWhereLastActiveOwner($user);

        if ($lastOwnerOf > 0) {
            $blocks[] = [
                'key' => 'loop_last_owner',
                'count' => $lastOwnerOf,
                'message' => __('admin.user_delete.block.loop_last_owner', ['count' => $lastOwnerOf]),
            ];
        }

        // TASK-1639 — une propriete rattachee a une autre Organization.
        //
        // Elle ne peut ni rester (le RESTRICT l'interdit) ni etre transferee : le
        // repreneur est un AUTEUR visible dans le produit, et lui attribuer un
        // contenu d'une autre organisation fabriquerait une fausse paternite.
        // Le seul geste juste est donc de REFUSER, en le disant. Ce refus est
        // detecte ici, c'est-a-dire a la fois par `precheck()` (l'ecran) et par
        // le recontrole sous verrou (l'autorite) — jamais par une QueryException
        // arrivee trop tard.
        $crossTenant = $this->crossTenantTransferables($user);

        if ($crossTenant > 0) {
            $blocks[] = [
                'key' => 'cross_tenant_transfer',
                'count' => $crossTenant,
                'message' => __('admin.user_delete.block.cross_tenant_transfer', ['count' => $crossTenant]),
            ];
        }

        return $blocks;
    }

    /**
     * Compte les lignes d'une entree BLOCK, en lisant la table et la colonne
     * DANS le registre — jamais en les redeclarant ici.
     */
    private function countBlockingRows(User $user, string $key): int
    {
        $entry = $this->entry($key);

        if ($entry === null || ! isset($entry['table'], $entry['column'])) {
            return 0;
        }

        if (! Schema::hasTable($entry['table'])) {
            return 0;
        }

        $query = DB::table($entry['table'])->where($entry['column'], $user->id);

        // TASK-1638 — la MEME regle declarative que `preview()`, lue au registre.
        // Ecrire ici la raison exclue — meme en commentaire — aurait fabrique
        // une seconde copie de la politique, libre de deriver de l'ecran sans
        // que rien ne le dise. Elle ne vit qu'au registre.
        UserDataLifecycleRegistry::excludeResolvableRows($query, $entry, $entry['table']);

        return $query->count();
    }

    /**
     * Dans combien de Boucles ce membre est-il le DERNIER owner actif ?
     *
     * Un facilitator seul ne compte pas : `LoopGovernanceService` est la seule
     * autorite sur cette question, et elle n'est pas reimplementee ici.
     */
    private function loopsWhereLastActiveOwner(User $user): int
    {
        if (! Schema::hasTable('loop_members')) {
            return 0;
        }

        return LoopMember::query()
            ->where('user_id', $user->id)
            ->get()
            ->filter(fn (LoopMember $member) => $this->governance->isLastActiveOwner($member))
            ->count();
    }

    /**
     * @return array<string, int>
     */
    private function transferableCounts(User $user): array
    {
        $counts = [];

        foreach (self::TRANSFERABLE as $key => $target) {
            $counts[$key] = Schema::hasTable($target['table'])
                ? DB::table($target['table'])->where($target['column'], $user->id)->count()
                : 0;
        }

        return $counts;
    }

    /**
     * TASK-1639 — les proprietes que le transfert NE deplacerait PAS.
     *
     * Ce compte n'est pas defini par une regle qui lui est propre : c'est le
     * **complement exact** de ce que `transfer()` deplace, obtenu en reutilisant
     * son predicat (`transferQuery()`). Une propriete hors-tenant serait donc
     * comptee comme « a transferer », jamais deplacee, et le `forceDelete()`
     * heurterait le RESTRICT — c'est le defaut mesure sur le clone PROD au
     * scenario B de TASK-1638.
     *
     * Le definir comme un complement, et non comme « organization_id differe »,
     * est deliberé : si le predicat de `transfer()` change un jour, ce compte
     * suit automatiquement. Les deux ne peuvent plus diverger.
     */
    private function crossTenantTransferables(User $user): int
    {
        $bloquantes = 0;

        foreach (self::TRANSFERABLE as $spec) {
            if (! Schema::hasTable($spec['table'])) {
                continue;
            }

            $possedees = DB::table($spec['table'])->where($spec['column'], $user->id)->count();
            $deplacables = $this->transferQuery($user, $spec)->count();

            $bloquantes += $possedees - $deplacables;
        }

        return $bloquantes;
    }

    /**
     * Le predicat UNIQUE des proprietes qu'un transfert deplace.
     *
     * Detenu a un seul endroit, lu par `transfer()` (qui met a jour) et par
     * `crossTenantTransferables()` (qui compte ce qui reste). Le contenu ne
     * franchit jamais la frontiere d'une Organization : c'est cette borne-la que
     * le filtre exprime, et non une optimisation.
     *
     * @param  array{table: string, column: string}  $spec
     */
    private function transferQuery(User $user, array $spec): \Illuminate\Database\Query\Builder
    {
        $query = DB::table($spec['table'])->where($spec['column'], $user->id);

        if (Schema::hasColumn($spec['table'], 'organization_id')) {
            // `whereRaw` avec `IS NOT DISTINCT FROM` serait plus juste sur le
            // papier, mais `where('organization_id', $x)` est ce que le produit
            // fait depuis TASK-1636 : on garde EXACTEMENT ce comportement et on
            // compte ce qu'il laisse derriere, plutot que de le changer ici.
            $query->where('organization_id', $user->organization_id);
        }

        return $query;
    }

    /**
     * La cible d'un transfert, validee sous verrou.
     *
     * Les quatre refus sont distincts et le restent : « inexistante »,
     * « soi-meme », « bannie » et « autre tenant » ne disent pas la meme chose
     * a l'admin qui lit le message.
     */
    private function resolveTarget(User $user, string $transferToId): User
    {
        $target = User::query()->whereKey($transferToId)->lockForUpdate()->first();

        $refuse = function (string $reason): never {
            throw new UserDeletionBlockedException([[
                'key' => 'transfer_target_'.$reason,
                'count' => 0,
                'message' => __('admin.user_delete.block.transfer_target_'.$reason),
            ]]);
        };

        if ($target === null) {
            $refuse('missing');
        }

        if ($target->id === $user->id) {
            $refuse('self');
        }

        if ($target->banned_at !== null) {
            $refuse('banned');
        }

        // Organization = Tenant : une propriete ne traverse jamais la frontiere.
        if ($target->organization_id !== $user->organization_id) {
            $refuse('cross_tenant');
        }

        return $target;
    }

    /**
     * Un UPDATE par table, borne a l'Organization du User source.
     *
     * Le `where organization_id` n'est pas une precaution decorative : si le
     * User possede des lignes HORS de son Organization, elles ne sont pas
     * reassignees — les reattribuer traverserait la frontiere de tenant. Elles
     * restent donc en place, et c'est le RESTRICT de M3 qui fera echouer la
     * suppression, bruyamment, plutot que de laisser passer une fuite.
     *
     * @return array<string, int>
     */
    private function transfer(User $user, User $target): array
    {
        $transferred = [];

        foreach (self::TRANSFERABLE as $key => $spec) {
            if (! Schema::hasTable($spec['table'])) {
                continue;
            }

            // MEME predicat que celui qui compte les proprietes hors-tenant :
            // les deux ne peuvent plus decrire des ensembles differents.
            $transferred[$key] = $this->transferQuery($user, $spec)
                ->update([$spec['column'] => $target->id]);
        }

        return $transferred;
    }

    /**
     * Purge les arborescences de Dossiers dont ce membre est proprietaire.
     *
     * Toutes les RACINES, pas seulement `system_role = personal_documents` :
     * les racines legacy appartiennent aussi au membre et bloqueraient tout
     * autant. `withTrashed()` parce qu'une racine en corbeille reste une ligne
     * qui reference le proprietaire.
     *
     * La logique d'arborescence n'est pas reimplementee : `DossierTreePurger`
     * en est l'unique detenteur, ici comme dans l'outil SuperAdmin.
     *
     * @return array<string, int>
     */
    private function purgePersonalDossiers(User $user): array
    {
        if (! Schema::hasTable('dossiers')) {
            return [];
        }

        $racines = Dossier::withTrashed()
            ->whereNull('parent_id')
            ->where('owner_id', $user->id)
            ->lockForUpdate()
            ->get();

        if ($racines->isEmpty()) {
            return [];
        }

        return $this->purger->purge($racines);
    }

    /**
     * TASK-1638 — les lignes BLOCK que le registre declare resolvables.
     *
     * Une seule requete par entree, bornee a `user_id` ET a la valeur declaree.
     * Aucune autre ligne de la table n'est touchee : un `adjustment` ou un
     * `exchange_earned` du meme compte ne peut pas etre emporte ici, puisque le
     * recontrole aurait deja refuse la suppression.
     *
     * @return array<string, int>
     */
    private function purgeResolvableRows(User $user): array
    {
        $purged = [];

        foreach (self::HARD_BLOCK_KEYS as $key) {
            $entry = $this->entry($key);
            $resolvable = UserDataLifecycleRegistry::resolvableRows($key);

            if ($entry === null || $resolvable === null) {
                continue;
            }

            $table = $entry['table'] ?? null;
            $column = $entry['column'] ?? null;

            if ($table === null || $column === null
                || ! Schema::hasTable($table)
                || ! Schema::hasColumn($table, $resolvable['column'])) {
                continue;
            }

            $count = DB::table($table)
                ->where($column, $user->id)
                ->where($resolvable['column'], $resolvable['value'])
                ->delete();

            if ($count > 0) {
                $purged[$key] = $count;
            }
        }

        return $purged;
    }

    /**
     * Les entrees POLICY_DELETE du registre, en bulk, comptees.
     *
     * Toutes sont supprimees explicitement, y compris celles dont la FK
     * cascaderait de toute facon. C'est deliberé : le comptage rendu a l'admin
     * est alors exact pour chaque table, et il ne depend pas de la regle ON
     * DELETE du moment. Le cout est nul a cette echelle, et aucune liste en dur
     * ne vient doubler le registre.
     *
     * @return array<string, int>
     */
    private function deleteOwnedRows(User $user): array
    {
        $deleted = [];

        foreach (self::entriesWithPolicy(UserDataLifecycleRegistry::POLICY_DELETE) as $entry) {
            $table = $entry['table'] ?? null;
            $column = $entry['column'] ?? null;

            // `sessions` est declaree sans paire SQL au registre ; la table
            // existe et porte `user_id`.
            if ($entry['key'] === 'sessions') {
                $table = 'sessions';
                $column = 'user_id';
            }

            if ($table === null || $column === null || ! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            $count = DB::table($table)->where($column, $user->id)->delete();

            if ($count > 0) {
                $deleted[$entry['key']] = $count;
            }
        }

        return $deleted;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function entry(string $key): ?array
    {
        foreach (UserDataLifecycleRegistry::entries() as $entry) {
            if ($entry['key'] === $key) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function entriesWithPolicy(string $policy): array
    {
        return array_values(array_filter(
            UserDataLifecycleRegistry::entries(),
            fn (array $entry) => ($entry['policy'] ?? null) === $policy
        ));
    }

    /**
     * Les Organizations dont ce membre est responsable — lu par l'ecran pour
     * expliquer le refus, jamais pour le contourner.
     */
    public function organizationsAdministeredBy(User $user): int
    {
        return Organization::query()->where('admin_id', $user->id)->count();
    }
}
