<?php

namespace App\Livewire;

use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\LoopRoadmapItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class LoopRoadmapCard extends Component
{
    public Loop $loop;

    public string $newTitle = '';

    public ?string $editingId = null;

    public string $editingTitle = '';

    public ?string $editingAssignee = null;

    public ?string $editingDueAt = null;

    public ?string $errorMessage = null;

    /**
     * Add a new roadmap action (any active member).
     */
    public function add(): void
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
        if (mb_strlen($title) > 255) {
            $title = mb_substr($title, 0, 255);
        }

        $maxPosition = LoopRoadmapItem::query()
            ->where('organization_id', $this->loop->organization_id)
            ->where('loop_id', $this->loop->id)
            ->max('position');

        LoopRoadmapItem::create([
            'organization_id' => $this->loop->organization_id,
            'loop_id' => $this->loop->id,
            'title' => $title,
            'status' => LoopRoadmapItem::STATUS_OPEN,
            'position' => ($maxPosition === null ? 0 : $maxPosition + 1),
            'created_by' => auth()->id(),
        ]);

        $this->newTitle = '';
    }

    /**
     * Toggle open <-> done (any active member).
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

        if ($item->isDone()) {
            $item->update(['status' => LoopRoadmapItem::STATUS_OPEN, 'completed_at' => null]);
        } else {
            $item->update(['status' => LoopRoadmapItem::STATUS_DONE, 'completed_at' => now()]);
        }
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
        $this->editingAssignee = $item->assigned_to;
        $this->editingDueAt = $item->due_at?->format('Y-m-d');
    }

    public function cancelEdit(): void
    {
        $this->editingId = null;
        $this->editingTitle = '';
        $this->editingAssignee = null;
        $this->editingDueAt = null;
        $this->errorMessage = null;
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

        // Assignee must be an active member of THIS loop (same organization).
        $assignee = null;
        if ($this->editingAssignee) {
            $isActiveMember = LoopMember::where('loop_id', $this->loop->id)
                ->where('user_id', $this->editingAssignee)
                ->where('status', 'active')
                ->exists();
            $assignee = $isActiveMember ? $this->editingAssignee : null;
            if (! $isActiveMember) {
                $this->errorMessage = __('loops.roadmap_invalid_assignee');

                return;
            }
        }

        $item->update([
            'title' => $title,
            'assigned_to' => $assignee,
            'due_at' => $this->editingDueAt ?: null,
        ]);

        $this->cancelEdit();
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

    /** Keyboard/a11y fallback: move one action up within its status group. */
    public function moveUp(string $id): void
    {
        $this->shiftPosition($id, -1);
    }

    /** Keyboard/a11y fallback: move one action down within its status group. */
    public function moveDown(string $id): void
    {
        $this->shiftPosition($id, 1);
    }

    /**
     * Drag & drop reorder of ONE status group.
     *
     * The payload contains only the UUIDs of the given group, in their new order.
     * Cross-group moves are impossible (status is driven exclusively by the toggle);
     * the set is validated exactly against the group before any write.
     */
    public function reorderGroup(string $status, array $orderedIds): void
    {
        $this->errorMessage = null;

        if (! $this->isMemberOrAdmin()) {
            return;
        }

        if (! in_array($status, [LoopRoadmapItem::STATUS_OPEN, LoopRoadmapItem::STATUS_DONE], true)) {
            $this->errorMessage = __('loops.roadmap_reorder_failed');

            return;
        }

        // Normalize input, reject empty and duplicates. Never trust the browser order.
        $orderedIds = array_values(array_filter(array_map('strval', $orderedIds), fn ($v) => $v !== ''));
        if ($orderedIds === [] || count($orderedIds) !== count(array_unique($orderedIds))) {
            $this->errorMessage = __('loops.roadmap_reorder_failed');

            return;
        }

        // Exact set of items for THIS org + loop + status group.
        $group = LoopRoadmapItem::query()
            ->where('organization_id', $this->loop->organization_id)
            ->where('loop_id', $this->loop->id)
            ->where('status', $status)
            ->get();

        $groupIds = $group->pluck('id')->map(fn ($v) => (string) $v)->all();

        // Reject foreign / other loop / other org / other status / incomplete / surplus.
        $expected = $groupIds;
        $received = $orderedIds;
        sort($expected);
        sort($received);
        if ($expected !== $received) {
            $this->errorMessage = __('loops.roadmap_reorder_failed');

            return; // re-render restores the server order
        }

        $byId = $group->keyBy('id');

        // Normalize positions 0..n-1 within the group, transactionally (no partial write).
        DB::transaction(function () use ($orderedIds, $byId) {
            foreach ($orderedIds as $index => $id) {
                $item = $byId[$id];
                if ((int) $item->position !== $index) {
                    $item->update(['position' => $index]);
                }
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

        // Reorder only within the same status group, by position.
        $siblings = LoopRoadmapItem::query()
            ->where('organization_id', $this->loop->organization_id)
            ->where('loop_id', $this->loop->id)
            ->where('status', $item->status)
            ->orderBy('position')
            ->orderBy('created_at')
            ->get();

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

    private function canModify(LoopRoadmapItem $item): bool
    {
        if (! $this->isMemberOrAdmin()) {
            return false;
        }

        return $item->created_by === auth()->id() || $this->isPrivileged();
    }

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

    public function render()
    {
        $canManage = $this->isMemberOrAdmin();

        $items = $canManage
            ? LoopRoadmapItem::query()
                ->where('organization_id', $this->loop->organization_id)
                ->where('loop_id', $this->loop->id)
                ->with('assignee')
                ->ordered()
                ->get()
            : collect();

        $openItems = $items->where('status', LoopRoadmapItem::STATUS_OPEN)->values();
        $doneItems = $items->where('status', LoopRoadmapItem::STATUS_DONE)->values();
        $openCount = $openItems->count();

        $canModify = [];
        foreach ($items as $item) {
            $canModify[$item->id] = $this->canModify($item);
        }

        return view('livewire.loop-roadmap-card', [
            'items' => $items,
            'openItems' => $openItems,
            'doneItems' => $doneItems,
            'openCount' => $openCount,
            'canManage' => $canManage,
            'canModify' => $canModify,
            'members' => $canManage ? $this->assignableMembers() : collect(),
        ]);
    }
}
