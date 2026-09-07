<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcquisitionJourney;
use App\Models\Organization;
use App\Models\OrganizationShortcut;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * TASK-1447 — OrganizationShortcut, SuperAdmin-managed V1 (MASTER Q75) : liste,
 * creation (code global unique, destination canonique, Journey par cle de
 * l'Organization visee, campagne), activation / desactivation. Aucune URL libre.
 */
class AdminShortcutController extends Controller
{
    public function index(): View
    {
        return view('admin.shortcuts.index', [
            'shortcuts' => OrganizationShortcut::query()->with(['organization', 'author'])->orderBy('code')->get(),
            'organizations' => Organization::query()->orderBy('name')->get(['id', 'name', 'slug', 'is_public', 'is_active']),
            'destinations' => OrganizationShortcut::DESTINATIONS,
            'journeys' => AcquisitionJourney::query()->published()->orderBy('key')->get(['organization_id', 'key', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'organization_id' => ['required', 'string', 'exists:organizations,id'],
            'code' => ['required', 'string', 'max:32', 'regex:'.OrganizationShortcut::CODE_PATTERN, Rule::notIn(OrganizationShortcut::RESERVED_CODES), 'unique:organization_shortcuts,code'],
            'destination' => ['required', 'string', Rule::in(OrganizationShortcut::DESTINATIONS)],
            'acquisition_journey_key' => ['nullable', 'string', 'max:'.AcquisitionJourney::MAX_KEY_CHARS],
            'campaign' => ['nullable', 'string', 'max:'.AcquisitionJourney::MAX_CAMPAIGN_CHARS],
        ]);

        $journeyKey = trim((string) ($data['acquisition_journey_key'] ?? ''));
        if ($journeyKey !== '' && ! AcquisitionJourney::query()->forOrganization($data['organization_id'])->forKey($journeyKey)->exists()) {
            return back()->withErrors(['acquisition_journey_key' => __('admin.shortcut_journey_unknown')])->withInput();
        }

        OrganizationShortcut::query()->create([
            'organization_id' => $data['organization_id'],
            'code' => $data['code'],
            'destination' => $data['destination'],
            'acquisition_journey_key' => $journeyKey === '' ? null : $journeyKey,
            'campaign' => trim((string) ($data['campaign'] ?? '')) === '' ? null : trim((string) $data['campaign']),
            'active' => true,
            'created_by' => $request->user()->getKey(),
        ]);

        return redirect()->route('admin.shortcuts')->with('success', __('admin.shortcut_created', ['code' => $data['code']]));
    }

    public function toggle(OrganizationShortcut $shortcut): RedirectResponse
    {
        $shortcut->forceFill(['active' => ! $shortcut->active])->save();

        return redirect()->route('admin.shortcuts')->with('success', __($shortcut->active ? 'admin.shortcut_activated' : 'admin.shortcut_deactivated', ['code' => $shortcut->code]));
    }
}
