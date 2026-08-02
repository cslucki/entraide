<?php

namespace App\Livewire;

use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\LoopRoadmapItem;
use App\Models\User;
use App\Services\LoopService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class LoopRoadmapCard extends Component
{
    public Loop $loop;

    // Create form (premium "+" modal).
    public string $newTitle = '';

    public string $newStatus = LoopRoadmapItem::STATUS_TODO;

    /** @var array<int,string> */
    public array $newAssignees = [];

    public ?string $newDueAt = null;

    // Inline edit.
    public ?string $editingId = null;

    public string $editingTitle = '';

    /** @var array<int,string> */
    public array $editingAssignees = [];

    public ?string $editingDueAt = null;

    public ?string $errorMessage = null;

    /**
     * Create a new roadmap action (any active member). The status is pre-filled by the
     * column the "+" was clicked from; assignee/due date are optional.
     */
    public function createAction(): void
    {
        $this->errorMessage = null;

        if (! $this->isMemberOrAdmin()) {
            return;
        }

        $title = trim($this->newTitle);
        if ($title === '') {
            $this->errorMessage = __('loops.roadmap_title_required');

            return;
        }
        $title = mb_substr($title, 0, 255);

        $status = LoopRoadmapItem::isValidStatus($this->newStatus)
            ? $this->newStatus
            : LoopRoadmapItem::STATUS_TODO;

        $assignees = $this->validAssignees($this->newAssignees);
        if ($assignees === null) {
            $this->errorMessage = __('loops.roadmap_invalid_assignee');

            return;
        }

        $maxPosition = LoopRoadmapItem::query()
            ->where('organization_id', $this->loop->organization_id)
            ->where('loop_id', $this->loop->id)
            ->where('status', $status)
            ->max('position');

        $item = LoopRoadmapItem::create([
            'organization_id' => $this->loop->organization_id,
            'loop_id' => $this->loop->id,
            'title' => $title,
            'status' => $status,
            'position' => ($maxPosition === null ? 0 : $maxPosition + 1),
            'due_at' => $this->newDueAt ?: null,
            'completed_at' => $status === LoopRoadmapItem::STATUS_DONE ? now() : null,
            'created_by' => auth()->id(),
        ]);

        if ($assignees !== []) {
            $item->assignees()->sync($assignees);
        }

        $this->reset(['newTitle', 'newAssignees', 'newDueAt']);
        $this->newStatus = LoopRoadmapItem::STATUS_TODO;

        $this->dispatch('roadmap-action-created');
    }

    /**
     * Explicit status change from the actions menu (never by anything implicit).
     * Appends the item at the end of the target column.
     */
    public function setStatus(string $id, string $status): void
    {
        $this->errorMessage = null;

        if (! $this->isMemberOrAdmin() || ! LoopRoadmapItem::isValidStatus($status)) {
            return;
        }

        $item = $this->resolveItem($id);
        if (! $item || $item->status === $status) {
            return;
        }

        $maxPosition = LoopRoadmapItem::query()
            ->where('organization_id', $this->loop->organization_id)
            ->where('loop_id', $this->loop->id)
            ->where('status', $status)
            ->max('position');

        $item->update([
            'status' => $status,
            'position' => ($maxPosition === null ? 0 : $maxPosition + 1),
            'completed_at' => $this->completedAtFor($item->status, $status, $item->completed_at),
        ]);
    }

    /**
     * Legacy quick toggle (checkbox): done <-> todo. Kept for the "mark done / reopen"
     * affordance on a card.
     */
    public function toggle(string $id): void
    {
        $this->errorMessage = null;

        if (! $this->isMemberOrAdmin()) {
            return;
        }

        $item = $this->resolveItem($id);
        if (! $item) {
            return;
        }

        $this->setStatus($id, $item->isDone() ? LoopRoadmapItem::STATUS_TODO : LoopRoadmapItem::STATUS_DONE);
    }

    public function startEdit(string $id): void
    {
        $this->errorMessage = null;

        $item = $this->resolveItem($id);
        if (! $item || ! $this->canModify($item)) {
            return;
        }

        $this->editingId = $item->id;
        $this->editingTitle = $item->title;
        $this->editingAssignees = $item->assignees()->pluck('users.id')->map(fn ($v) => (string) $v)->all();
        $this->editingDueAt = $item->due_at?->format('Y-m-d');
    }

    public function cancelEdit(): void
    {
        $this->reset(['editingId', 'editingTitle', 'editingAssignees', 'editingDueAt', 'errorMessage']);
    }

    public function saveEdit(): void
    {
        $this->errorMessage = null;

        if ($this->editingId === null) {
            return;
        }

        $item = $this->resolveItem($this->editingId);
        if (! $item || ! $this->canModify($item)) {
            $this->cancelEdit();

            return;
        }

        $title = trim($this->editingTitle);
        if ($title === '') {
            $this->errorMessage = __('loops.roadmap_title_required');

            return;
        }
        $title = mb_substr($title, 0, 255);

        // Assignees must be active members of THIS loop (same organization), max 3.
        $assignees = $this->validAssignees($this->editingAssignees);
        if ($assignees === null) {
            $this->errorMessage = __('loops.roadmap_invalid_assignee');

            return;
        }

        $item->update([
            'title' => $title,
            'due_at' => $this->editingDueAt ?: null,
        ]);
        $item->assignees()->sync($assignees);

        $this->cancelEdit();
    }

    /**
     * Set the full assignee list of an item (category A). Max 3 active members.
     *
     * @param  array<int,string>  $userIds
     */
    public function assign(string $id, array $userIds): void
    {
        $this->errorMessage = null;

        $item = $this->resolveItem($id);
        if (! $item || ! $this->canModify($item)) {
            return;
        }

        $assignees = $this->validAssignees($userIds);
        if ($assignees === null) {
            $this->errorMessage = __('loops.roadmap_invalid_assignee');

            return;
        }

        $item->assignees()->sync($assignees);
    }

    /**
     * Category B: the selected user belongs to the same Organization but is not yet a
     * member of this Loop. Add them (member role) then append to the assignees — never
     * silently, never cross-org, never by email invitation. Respects the max of 3.
     */
    public function assignAndAddMember(string $id, string $userId): void
    {
        $this->errorMessage = null;

        $item = $this->resolveItem($id);
        if (! $item || ! $this->canModify($item) || ! $this->canAddMembers()) {
            return;
        }

        // Must be an assignable user of THIS organization.
        $user = User::assignable()
            ->where('organization_id', $this->loop->organization_id)
            ->find($userId);

        if (! $user) {
            $this->errorMessage = __('loops.roadmap_invalid_assignee');

            return;
        }

        if (! $item->assignees()->whereKey($user->id)->exists()
            && $item->assignees()->count() >= LoopRoadmapItem::MAX_ASSIGNEES) {
            $this->errorMessage = __('loops.roadmap_max_assignees');

            return;
        }

        DB::transaction(function () use ($item, $user) {
            if (! $this->isActiveLoopMember($user->id)) {
                app(LoopService::class)->addMemberByUserId($this->loop, $user->id, 'member');
            }
            $item->assignees()->syncWithoutDetaching([$user->id]);
        });
    }

    /**
     * Normalize + validate an assignee id list: unique, ≤ MAX, all active Loop members.
     * Returns the clean list, or null if invalid.
     *
     * @param  array<int,string>  $ids
     * @return array<int,string>|null
     */
    private function validAssignees(array $ids): ?array
    {
        $ids = array_values(array_unique(array_filter(array_map('strval', $ids), fn ($v) => $v !== '')));

        if (count($ids) > LoopRoadmapItem::MAX_ASSIGNEES) {
            return null;
        }

        foreach ($ids as $id) {
            if (! $this->isActiveLoopMember($id)) {
                return null;
            }
        }

        return $ids;
    }

    public function deleteItem(string $id): void
    {
        $this->errorMessage = null;

        $item = $this->resolveItem($id);
        if (! $item || ! $this->canModify($item)) {
            return;
        }

        if ($this->editingId === $item->id) {
            $this->cancelEdit();
        }

        $item->delete();
    }

    /** Keyboard/a11y fallback: move one action up within its status column. */
    public function moveUp(string $id): void
    {
        $this->shiftPosition($id, -1);
    }

    /** Keyboard/a11y fallback: move one action down within its status column. */
    public function moveDown(string $id): void
    {
        $this->shiftPosition($id, 1);
    }

    /**
     * Drag & drop reorder WITHIN one column (status unchanged). The payload contains only
     * the UUIDs of the given column, in their new order, validated exactly before writing.
     */
    public function reorderGroup(string $status, array $orderedIds): void
    {
        $this->errorMessage = null;

        if (! $this->isMemberOrAdmin() || ! LoopRoadmapItem::isValidStatus($status)) {
            $this->errorMessage = __('loops.roadmap_reorder_failed');

            return;
        }

        $orderedIds = $this->cleanIds($orderedIds);
        if ($orderedIds === null) {
            $this->errorMessage = __('loops.roadmap_reorder_failed');

            return;
        }

        $column = $this->columnItems($status);
        if (! $this->setMatches($orderedIds, $column->pluck('id')->all())) {
            $this->errorMessage = __('loops.roadmap_reorder_failed');

            return; // re-render restores the server order
        }

        $byId = $column->keyBy('id');
        DB::transaction(function () use ($orderedIds, $byId) {
            foreach ($orderedIds as $index => $id) {
                if ((int) $byId[$id]->position !== $index) {
                    $byId[$id]->update(['position' => $index]);
                }
            }
        });
    }

    /**
     * Drag & drop BETWEEN columns: the moved item changes status; source and target
     * columns are renormalized in a single transaction. Payload carries the exact ordered
     * UUID sets of both columns AFTER the move.
     */
    public function moveItem(string $itemId, string $sourceStatus, string $targetStatus, array $orderedSourceIds, array $orderedTargetIds): void
    {
        $this->errorMessage = null;

        if (! $this->isMemberOrAdmin()
            || ! LoopRoadmapItem::isValidStatus($sourceStatus)
            || ! LoopRoadmapItem::isValidStatus($targetStatus)
            || $sourceStatus === $targetStatus) {
            $this->errorMessage = __('loops.roadmap_reorder_failed');

            return;
        }

        $item = $this->resolveItem($itemId);
        if (! $item || $item->status !== $sourceStatus) {
            $this->errorMessage = __('loops.roadmap_reorder_failed');

            return;
        }

        $source = $this->cleanIds($orderedSourceIds) ?? [];
        $target = $this->cleanIds($orderedTargetIds);
        if ($target === null) {
            $this->errorMessage = __('loops.roadmap_reorder_failed');

            return;
        }

        // The moved item must be in the target set and absent from the source set.
        if (! in_array($itemId, $target, true) || in_array($itemId, $source, true)) {
            $this->errorMessage = __('loops.roadmap_reorder_failed');

            return;
        }

        // Expected server state AFTER the move.
        $currentSource = $this->columnItems($sourceStatus)->pluck('id')->all();
        $currentTarget = $this->columnItems($targetStatus)->pluck('id')->all();
        $expectedSource = array_values(array_diff($currentSource, [$itemId]));
        $expectedTarget = array_merge($currentTarget, [$itemId]);

        if (! $this->setMatches($source, $expectedSource) || ! $this->setMatches($target, $expectedTarget)) {
            $this->errorMessage = __('loops.roadmap_reorder_failed');

            return;
        }

        // Load every touched row, re-scoped, so we never trust a browser id.
        $rows = LoopRoadmapItem::query()
            ->where('organization_id', $this->loop->organization_id)
            ->where('loop_id', $this->loop->id)
            ->whereIn('id', array_merge($source, $target))
            ->get()
            ->keyBy('id');

        if ($rows->count() !== count(array_unique(array_merge($source, $target)))) {
            $this->errorMessage = __('loops.roadmap_reorder_failed');

            return;
        }

        $completedAt = $this->completedAtFor($sourceStatus, $targetStatus, $item->completed_at);

        DB::transaction(function () use ($source, $target, $rows, $itemId, $targetStatus, $completedAt) {
            foreach ($source as $index => $id) {
                if ((int) $rows[$id]->position !== $index) {
                    $rows[$id]->update(['position' => $index]);
                }
            }
            foreach ($target as $index => $id) {
                $updates = ['position' => $index];
                if ($id === $itemId) {
                    $updates['status'] = $targetStatus;
                    $updates['completed_at'] = $completedAt;
                }
                $rows[$id]->update($updates);
            }
        });
    }

    private function shiftPosition(string $id, int $direction): void
    {
        $this->errorMessage = null;

        if (! $this->isMemberOrAdmin()) {
            return;
        }

        $item = $this->resolveItem($id);
        if (! $item) {
            return;
        }

        $siblings = $this->columnItems($item->status);

        $index = $siblings->search(fn (LoopRoadmapItem $i) => $i->id === $item->id);
        if ($index === false) {
            return;
        }

        $target = $index + $direction;
        if ($target < 0 || $target >= $siblings->count()) {
            return;
        }

        $neighbor = $siblings[$target];
        $itemPos = $item->position;
        $item->update(['position' => $neighbor->position]);
        $neighbor->update(['position' => $itemPos]);
    }

    /** completed_at transition rules (Product Spec §D). */
    private function completedAtFor(string $from, string $to, $current)
    {
        if ($to === LoopRoadmapItem::STATUS_DONE && $from !== LoopRoadmapItem::STATUS_DONE) {
            return now();
        }
        if ($from === LoopRoadmapItem::STATUS_DONE && $to !== LoopRoadmapItem::STATUS_DONE) {
            return null;
        }

        return $current;
    }

    /** Ordered items of one column (org + loop + status), by position then creation. */
    private function columnItems(string $status): Collection
    {
        return LoopRoadmapItem::query()
            ->where('organization_id', $this->loop->organization_id)
            ->where('loop_id', $this->loop->id)
            ->where('status', $status)
            ->orderBy('position')
            ->orderBy('created_at')
            ->get();
    }

    /** Normalize a list of ids: strings, non-empty, no duplicates. Null if invalid. */
    private function cleanIds(array $ids): ?array
    {
        $ids = array_values(array_filter(array_map('strval', $ids), fn ($v) => $v !== ''));
        if (count($ids) !== count(array_unique($ids))) {
            return null;
        }

        return $ids;
    }

    private function setMatches(array $a, array $b): bool
    {
        $a = array_map('strval', $a);
        $b = array_map('strval', $b);
        sort($a);
        sort($b);

        return $a === $b;
    }

    private function isActiveLoopMember(string $userId): bool
    {
        return LoopMember::where('loop_id', $this->loop->id)
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->exists();
    }

    /**
     * Re-scope every mutation to this Organization + Loop. Never trust a browser id.
     */
    private function resolveItem(string $id): ?LoopRoadmapItem
    {
        if (! $this->isMemberOrAdmin()) {
            return null;
        }

        return LoopRoadmapItem::query()
            ->where('organization_id', $this->loop->organization_id)
            ->where('loop_id', $this->loop->id)
            ->find($id);
    }

    private function activeMembership(): ?LoopMember
    {
        $user = auth()->user();
        if (! $user || $user->isDeactivated()) {
            return null;
        }
        if ($this->loop->organization_id !== $user->organization_id && ! $user->is_admin) {
            return null;
        }

        return LoopMember::where('loop_id', $this->loop->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first();
    }

    private function isMemberOrAdmin(): bool
    {
        $user = auth()->user();
        if (! $user || $user->isDeactivated()) {
            return false;
        }
        if ($user->is_admin) {
            return true;
        }

        return $this->activeMembership() !== null;
    }

    private function isPrivileged(): bool
    {
        $user = auth()->user();
        if ($user && $user->is_admin) {
            return true;
        }

        $membership = $this->activeMembership();

        return $membership !== null && in_array($membership->role, ['owner', 'moderator'], true);
    }

    /** Adding a not-yet-member Org user to the Loop is reserved to privileged users. */
    private function canAddMembers(): bool
    {
        return $this->isPrivileged();
    }

    private function canModify(LoopRoadmapItem $item): bool
    {
        if (! $this->isMemberOrAdmin()) {
            return false;
        }

        return $item->created_by === auth()->id() || $this->isPrivileged();
    }

    /** Category A: active members of this Loop. */
    private function assignableMembers(): Collection
    {
        return LoopMember::where('loop_id', $this->loop->id)
            ->where('status', 'active')
            ->with('user')
            ->get()
            ->filter(fn (LoopMember $m) => $m->user !== null)
            ->map(fn (LoopMember $m) => [
                'id' => $m->user_id,
                'name' => $m->user->publicDisplayName(),
            ])
            ->values();
    }

    /** Category B: Organization users assignable but not yet active members of this Loop. */
    private function organizationCandidates(): Collection
    {
        $memberIds = LoopMember::where('loop_id', $this->loop->id)
            ->where('status', 'active')
            ->pluck('user_id')
            ->all();

        return User::assignable()
            ->where('organization_id', $this->loop->organization_id)
            ->whereNotIn('id', $memberIds)
            ->orderBy('name')
            ->limit(50)
            ->get()
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->publicDisplayName()])
            ->values();
    }

    public function render()
    {
        $canManage = $this->isMemberOrAdmin();

        $items = $canManage
            ? LoopRoadmapItem::query()
                ->where('organization_id', $this->loop->organization_id)
                ->where('loop_id', $this->loop->id)
                ->with('assignees')
                ->ordered()
                ->get()
            : collect();

        $columns = [
            LoopRoadmapItem::STATUS_TODO => $items->where('status', LoopRoadmapItem::STATUS_TODO)->values(),
            LoopRoadmapItem::STATUS_IN_PROGRESS => $items->where('status', LoopRoadmapItem::STATUS_IN_PROGRESS)->values(),
            LoopRoadmapItem::STATUS_DONE => $items->where('status', LoopRoadmapItem::STATUS_DONE)->values(),
        ];

        $canModify = [];
        foreach ($items as $item) {
            $canModify[$item->id] = $this->canModify($item);
        }

        $canAddMembers = $canManage && $this->canAddMembers();

        return view('livewire.loop-roadmap-card', [
            'items' => $items,
            'columns' => $columns,
            'openCount' => $columns[LoopRoadmapItem::STATUS_TODO]->count() + $columns[LoopRoadmapItem::STATUS_IN_PROGRESS]->count(),
            'canManage' => $canManage,
            'canModify' => $canModify,
            'canAddMembers' => $canAddMembers,
            'members' => $canManage ? $this->assignableMembers() : collect(),
            'orgCandidates' => $canAddMembers ? $this->organizationCandidates() : collect(),
        ]);
    }
}
