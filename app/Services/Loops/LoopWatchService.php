<?php

namespace App\Services\Loops;

use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\LoopWatchCategory;
use App\Models\LoopWatchItem;
use App\Models\User;
use App\Services\LoopMessageService;
use App\Support\Loops\LoopPermissionResolver;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * TASK-1879 — tout ce qu'on peut faire de la Veille d'une Boucle, et rien d'autre.
 *
 * Le composant Livewire n'ecrit jamais dans les tables : il appelle ici. Chaque
 * ecriture verifie, dans cet ordre :
 *
 *   1. le plugin `veille` est ACTIF dans la Boucle (gate Organization compris) ;
 *   2. la permission, par LoopPermissionResolver — qui refuse deja l'autre
 *      Organization, le compte desactive, la Card absente (plugin eteint) et
 *      toute ecriture dans une Boucle archivee, SuperAdmin compris ;
 *   3. l'adhesion ACTIVE pour publier ;
 *   4. l'appartenance de l'objet vise a CETTE Boucle : un identifiant recu
 *      d'un formulaire est confronte a `loop_id`, sa forme UUID verifiee
 *      AVANT la requete (PostgreSQL leverait 22P02, soit une 500).
 *
 * `organization_id` est TOUJOURS celui de la Boucle, jamais celui d'une session.
 */
class LoopWatchService
{
    public const PLUGIN = 'veille';

    public function __construct(
        private LoopPermissionResolver $permissions,
        private LoopPluginActivation $activation,
    ) {}

    // ── Droits ──────────────────────────────────────────────────────────────

    public function isEnabled(Loop $loop): bool
    {
        return in_array(self::PLUGIN, $this->activation->enabledPluginKeysFor($loop), true);
    }

    public function canView(User $user, Loop $loop): bool
    {
        return $this->isEnabled($loop) && $this->permissions->can($user, $loop, 'veille.view');
    }

    public function canCreate(User $user, Loop $loop): bool
    {
        return $this->isEnabled($loop)
            && $this->permissions->can($user, $loop, 'veille.create')
            && $this->isActiveMember($user, $loop);
    }

    public function canManageCategories(User $user, Loop $loop): bool
    {
        return $this->isEnabled($loop) && $this->permissions->can($user, $loop, 'veille.manage_categories');
    }

    /**
     * Modifier ou supprimer CETTE veille.
     *
     * `veille.manage` pour toutes ; sinon l'auteur, s'il peut encore publier
     * (membre actif, Boucle non archivee). Une veille d'une autre Boucle n'est
     * jamais gerable d'ici.
     */
    public function canManageItem(User $user, LoopWatchItem $item, Loop $loop): bool
    {
        if ($item->loop_id !== $loop->id || $item->organization_id !== $loop->organization_id) {
            return false;
        }

        if (! $this->isEnabled($loop)) {
            return false;
        }

        if ($this->permissions->can($user, $loop, 'veille.manage')) {
            return true;
        }

        return $item->created_by !== null
            && $item->created_by === $user->id
            && $this->canCreate($user, $loop);
    }

    // ── Lecture ─────────────────────────────────────────────────────────────

    /**
     * Les veilles de la Boucle, plus recentes d'abord.
     *
     * @return Collection<int, LoopWatchItem>
     */
    public function itemsFor(Loop $loop, ?string $categoryId = null, int $limit = 100): Collection
    {
        $query = LoopWatchItem::query()
            ->where('loop_id', $loop->id)
            ->where('organization_id', $loop->organization_id)
            ->with(['category:id,name,archived_at', 'author:id,name'])
            ->orderByDesc('observed_on')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit);

        if ($categoryId !== null) {
            $category = $this->findCategory($loop, $categoryId);
            $query->where('category_id', $category?->id ?? '00000000-0000-0000-0000-000000000000');
        }

        return $query->get();
    }

    /** @return Collection<int, LoopWatchCategory> */
    public function categoriesFor(Loop $loop, bool $withArchived = false): Collection
    {
        return LoopWatchCategory::query()
            ->where('loop_id', $loop->id)
            ->where('organization_id', $loop->organization_id)
            ->when(! $withArchived, fn ($q) => $q->whereNull('archived_at'))
            ->ordered()
            ->get();
    }

    /** Une veille de CETTE Boucle, ou null. UUID verifie avant la requete. */
    public function findItem(Loop $loop, ?string $id, bool $withTrashed = false): ?LoopWatchItem
    {
        if (! is_string($id) || ! Str::isUuid($id)) {
            return null;
        }

        return LoopWatchItem::query()
            ->when($withTrashed, fn ($q) => $q->withTrashed())
            ->whereKey($id)
            ->where('loop_id', $loop->id)
            ->where('organization_id', $loop->organization_id)
            ->first();
    }

    /** Une categorie de CETTE Boucle, ou null. UUID verifie avant la requete. */
    public function findCategory(Loop $loop, ?string $id): ?LoopWatchCategory
    {
        if (! is_string($id) || ! Str::isUuid($id)) {
            return null;
        }

        return LoopWatchCategory::query()
            ->whereKey($id)
            ->where('loop_id', $loop->id)
            ->where('organization_id', $loop->organization_id)
            ->first();
    }

    // ── Veilles ─────────────────────────────────────────────────────────────

    /**
     * Publier une veille, puis l'annoncer dans ChatLoop.
     *
     * La veille est enregistree D'ABORD, dans sa transaction. L'annonce est
     * tentee ENSUITE, hors de cette transaction : son echec ne retire pas la
     * veille (meme doctrine que les Sondages).
     *
     * @param  array{observed_on?: mixed, category_id?: mixed, information?: mixed, impact?: mixed, source_url?: mixed}  $data
     *
     * @throws WatchException
     */
    public function create(User $user, Loop $loop, array $data): LoopWatchItem
    {
        $this->assert($this->canCreate($user, $loop), 'veille.error_not_allowed');

        $propre = $this->validateItem($loop, $data, null);

        $item = DB::transaction(fn () => LoopWatchItem::create([
            'organization_id' => $loop->organization_id,
            'loop_id' => $loop->id,
            'category_id' => $propre['category_id'],
            'observed_on' => $propre['observed_on'],
            'information' => $propre['information'],
            'impact' => $propre['impact'],
            'source_url' => $propre['source_url'],
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]));

        $this->announce($loop, $user, $item);

        return $item;
    }

    /**
     * @param  array{observed_on?: mixed, category_id?: mixed, information?: mixed, impact?: mixed, source_url?: mixed}  $data
     *
     * @throws WatchException
     */
    public function update(User $user, Loop $loop, string $itemId, array $data): LoopWatchItem
    {
        $item = $this->findItem($loop, $itemId);

        $this->assert($item !== null, 'veille.error_not_found');
        $this->assert($this->canManageItem($user, $item, $loop), 'veille.error_not_allowed');

        $propre = $this->validateItem($loop, $data, $item);

        return DB::transaction(function () use ($item, $propre, $user) {
            $fresh = LoopWatchItem::whereKey($item->id)->lockForUpdate()->firstOrFail();

            $fresh->update([
                'category_id' => $propre['category_id'],
                'observed_on' => $propre['observed_on'],
                'information' => $propre['information'],
                'impact' => $propre['impact'],
                'source_url' => $propre['source_url'],
                'updated_by' => $user->id,
            ]);

            return $fresh;
        });
    }

    /**
     * Suppression DOUCE. L'annonce ChatLoop reste dans le fil, mais elle relit
     * la veille : supprimee, elle n'en montre plus rien.
     *
     * @throws WatchException
     */
    public function delete(User $user, Loop $loop, string $itemId): void
    {
        $item = $this->findItem($loop, $itemId);

        $this->assert($item !== null, 'veille.error_not_found');
        $this->assert($this->canManageItem($user, $item, $loop), 'veille.error_not_allowed');

        DB::transaction(function () use ($item, $user) {
            $fresh = LoopWatchItem::whereKey($item->id)->lockForUpdate()->firstOrFail();
            $fresh->forceFill(['updated_by' => $user->id])->save();
            $fresh->delete();
        });
    }

    // ── Categories ──────────────────────────────────────────────────────────

    /** @throws WatchException */
    public function createCategory(User $user, Loop $loop, mixed $name): LoopWatchCategory
    {
        $this->assert($this->canManageCategories($user, $loop), 'veille.error_not_allowed');

        $nom = $this->cleanCategoryName($name);

        return $this->writeCategory(function () use ($loop, $user, $nom) {
            $this->assertNameFree($loop, $nom, null);

            $position = (int) LoopWatchCategory::query()
                ->where('loop_id', $loop->id)
                ->whereNull('archived_at')
                ->max('position');

            return LoopWatchCategory::create([
                'organization_id' => $loop->organization_id,
                'loop_id' => $loop->id,
                'name' => $nom,
                'position' => $this->categoriesFor($loop)->isEmpty() ? 0 : $position + 1,
                'created_by' => $user->id,
            ]);
        });
    }

    /** @throws WatchException */
    public function renameCategory(User $user, Loop $loop, string $categoryId, mixed $name): LoopWatchCategory
    {
        $this->assert($this->canManageCategories($user, $loop), 'veille.error_not_allowed');

        $categorie = $this->findCategory($loop, $categoryId);
        $this->assert($categorie !== null && ! $categorie->isArchived(), 'veille.error_category_not_found');

        $nom = $this->cleanCategoryName($name);

        return $this->writeCategory(function () use ($loop, $categorie, $nom) {
            $this->assertNameFree($loop, $nom, $categorie->id);
            $categorie->update(['name' => $nom]);

            return $categorie;
        });
    }

    /**
     * Monter ou descendre une categorie active d'un cran. Les positions sont
     * renumerotees 0..n dans la meme transaction : l'ordre reste total.
     *
     * @param  'up'|'down'  $direction
     *
     * @throws WatchException
     */
    public function moveCategory(User $user, Loop $loop, string $categoryId, string $direction): void
    {
        $this->assert($this->canManageCategories($user, $loop), 'veille.error_not_allowed');
        $this->assert(in_array($direction, ['up', 'down'], true), 'veille.error_category_not_found');

        $categorie = $this->findCategory($loop, $categoryId);
        $this->assert($categorie !== null && ! $categorie->isArchived(), 'veille.error_category_not_found');

        DB::transaction(function () use ($loop, $categorie, $direction) {
            $ordre = LoopWatchCategory::query()
                ->where('loop_id', $loop->id)
                ->whereNull('archived_at')
                ->ordered()
                ->lockForUpdate()
                ->get()
                ->values();

            $index = $ordre->search(fn (LoopWatchCategory $c) => $c->id === $categorie->id);
            $cible = $direction === 'up' ? $index - 1 : $index + 1;

            if ($index === false || $cible < 0 || $cible >= $ordre->count()) {
                return;
            }

            $ids = $ordre->pluck('id')->all();
            [$ids[$index], $ids[$cible]] = [$ids[$cible], $ids[$index]];

            foreach ($ids as $position => $id) {
                LoopWatchCategory::whereKey($id)->update(['position' => $position]);
            }
        });
    }

    /**
     * Archiver : la categorie n'est plus proposee, les veilles qui la portent
     * la gardent. Rien n'est supprime.
     *
     * @throws WatchException
     */
    public function archiveCategory(User $user, Loop $loop, string $categoryId): void
    {
        $this->assert($this->canManageCategories($user, $loop), 'veille.error_not_allowed');

        $categorie = $this->findCategory($loop, $categoryId);
        $this->assert($categorie !== null, 'veille.error_category_not_found');

        if ($categorie->isArchived()) {
            return;
        }

        // Au moins UNE categorie active doit rester : sans elle, plus personne
        // ne pourrait publier de veille (categorie obligatoire).
        $this->assert($this->categoriesFor($loop)->count() > 1, 'veille.error_last_category');

        DB::transaction(fn () => $categorie->update(['archived_at' => now()]));
    }

    // ── Interne ─────────────────────────────────────────────────────────────

    /**
     * @return array{category_id: ?string, observed_on: string, information: string, impact: string, source_url: string}
     *
     * @throws WatchException
     */
    private function validateItem(Loop $loop, array $data, ?LoopWatchItem $existing): array
    {
        // Date : AAAA-MM-JJ valide.
        $date = trim((string) ($data['observed_on'] ?? ''));
        $this->assert($date !== '', 'veille.error_date_required');
        $jour = null;

        try {
            $jour = Carbon::createFromFormat('!Y-m-d', $date);
        } catch (\Throwable) {
            $jour = null;
        }

        $this->assert($jour instanceof Carbon && $jour->format('Y-m-d') === $date, 'veille.error_date_invalid');

        // Textes.
        $information = trim((string) ($data['information'] ?? ''));
        $impact = trim((string) ($data['impact'] ?? ''));
        $this->assert($information !== '', 'veille.error_information_required');
        $this->assert(mb_strlen($information) <= LoopWatchItem::INFORMATION_MAX, 'veille.error_information_too_long');
        $this->assert($impact !== '', 'veille.error_impact_required');
        $this->assert(mb_strlen($impact) <= LoopWatchItem::IMPACT_MAX, 'veille.error_impact_too_long');

        // Source : HTTP(S) seulement.
        $url = trim((string) ($data['source_url'] ?? ''));
        $this->assert($url !== '', 'veille.error_source_required');
        $this->assert(mb_strlen($url) <= LoopWatchItem::SOURCE_URL_MAX && self::isSafeUrl($url), 'veille.error_source_invalid');

        // Categorie.
        $categoryId = $data['category_id'] ?? null;
        $categoryId = is_string($categoryId) && trim($categoryId) !== '' ? trim($categoryId) : null;
        if ($categoryId === null) {
            // Demande Cyril (22/11) : une veille sans categorie est IMPOSSIBLE.
            // Sans categorie active dans la Boucle, on ne publie pas : le
            // proprietaire ou l'animateur doit d'abord en creer une. Seule une
            // veille ANCIENNE deja sans categorie peut etre modifiee telle quelle.
            $legacy = $existing !== null && $existing->category_id === null;
            $this->assert($legacy, $this->categoriesFor($loop)->isEmpty()
                ? 'veille.error_no_category_yet'
                : 'veille.error_category_required');
        } else {
            $categorie = $this->findCategory($loop, $categoryId);
            $this->assert($categorie !== null, 'veille.error_category_not_found');

            // Une categorie archivee n'est plus selectionnable — sauf a la
            // laisser en place sur la veille qui la portait deja.
            $inchangee = $existing !== null && $existing->category_id === $categorie->id;
            $this->assert(! $categorie->isArchived() || $inchangee, 'veille.error_category_archived');

            $categoryId = $categorie->id;
        }

        return [
            'category_id' => $categoryId,
            'observed_on' => $jour->format('Y-m-d'),
            'information' => $information,
            'impact' => $impact,
            'source_url' => $url,
        ];
    }

    /**
     * HTTP ou HTTPS, et une URL bien formee. Refuse `javascript:`, `data:`,
     * `file:`, `mailto:` et tout autre schema — meme regle que
     * LoopEventService::isSafeUrl().
     */
    public static function isSafeUrl(?string $url): bool
    {
        if (! is_string($url) || $url === '') {
            return false;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        return in_array(strtolower((string) $scheme), ['http', 'https'], true)
            && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /** @throws WatchException */
    private function cleanCategoryName(mixed $name): string
    {
        $nom = trim((string) (is_string($name) ? $name : ''));

        $this->assert($nom !== '', 'veille.error_category_name_required');
        $this->assert(mb_strlen($nom) <= LoopWatchCategory::NAME_MAX, 'veille.error_category_name_too_long');

        return $nom;
    }

    /**
     * Un nom deja porte par une categorie ACTIVE de la Boucle, sans tenir
     * compte de la casse, est refuse. Compare en PHP (`mb_strtolower`) : le
     * `lower()` de SQLite ne connait que l'ASCII. L'index unique en base reste
     * le filet contre une course.
     *
     * @throws WatchException
     */
    private function assertNameFree(Loop $loop, string $nom, ?string $saufId): void
    {
        $cle = mb_strtolower($nom);

        $pris = LoopWatchCategory::query()
            ->where('loop_id', $loop->id)
            ->whereNull('archived_at')
            ->when($saufId !== null, fn ($q) => $q->whereKeyNot($saufId))
            ->pluck('name')
            ->contains(fn (string $existant) => mb_strtolower(trim($existant)) === $cle);

        $this->assert(! $pris, 'veille.error_category_name_taken');
    }

    /**
     * @template T
     *
     * @param  callable(): T  $ecriture
     * @return T
     *
     * @throws WatchException
     */
    private function writeCategory(callable $ecriture): mixed
    {
        try {
            return DB::transaction($ecriture);
        } catch (QueryException $e) {
            // L'index unique (loop_id, lower(name)) a refuse une course.
            if (in_array((string) $e->getCode(), ['23505', '23000', '19'], true)) {
                throw new WatchException(__('veille.error_category_name_taken'));
            }

            throw $e;
        }
    }

    private function announce(Loop $loop, User $user, LoopWatchItem $item): void
    {
        try {
            app(LoopMessageService::class)->sendWatchItemMessage($loop, $user, $item);
        } catch (\Throwable $e) {
            // L'annonce est un accessoire : la veille existe, c'est l'essentiel.
            Log::warning('TASK-1879 annonce ChatLoop de veille impossible', [
                'loop_id' => $loop->id,
                'item_id' => $item->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function isActiveMember(User $user, Loop $loop): bool
    {
        return LoopMember::where('loop_id', $loop->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->exists();
    }

    /** @throws WatchException */
    private function assert(bool $condition, string $translationKey): void
    {
        if (! $condition) {
            throw new WatchException(__($translationKey));
        }
    }
}
