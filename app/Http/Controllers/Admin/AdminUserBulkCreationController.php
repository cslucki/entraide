<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Services\OrganizationInvitationMailer;
use App\Services\OrganizationInvitationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * "Création de comptes en masse" — TASK-1659, SuperAdmin-only (MASTER,
 * 30/09 16h50: ORGADMIN_SURFACE = OUT_OF_SCOPE). Several people prepared in
 * one operation, tracked, resendable, revocable — reusing
 * OrganizationInvitationService/Mailer, never a second invitation system.
 */
class AdminUserBulkCreationController extends Controller
{
    public function __construct(
        private OrganizationInvitationService $invitations,
        private OrganizationInvitationMailer $mailer,
    ) {}

    public function index(Request $request): View
    {
        // Scenario Manager sandboxes never receive real invitations
        // (TASK-1650, third expression of the same guard) — they are not
        // offered as a target at all, rather than accepted and refused later.
        $organizations = Organization::whereNull('scenario_sandbox_created_at')
            ->orderBy('name')
            ->get();

        $invitations = OrganizationInvitation::with(['organization', 'createdBy', 'acceptedBy'])
            ->when($request->filled('organization_id'), fn ($q) => $q->where('organization_id', $request->input('organization_id')))
            ->orderByDesc('created_at')
            ->paginate(30)
            ->withQueryString();

        return view('admin.users.bulk-create', [
            'organizations' => $organizations,
            'invitations' => $invitations,
            'selectedOrganizationId' => $request->input('organization_id'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'organization_id' => ['required', 'uuid', 'exists:organizations,id'],
            'locale' => ['nullable', Rule::in(OrganizationInvitation::LOCALES)],
            'people' => ['required', 'array', 'min:1'],
            'people.*.first_name' => ['required', 'string', 'max:255'],
            'people.*.last_name' => ['required', 'string', 'max:255'],
            'people.*.email' => ['required', 'email', 'max:255'],
        ]);

        $organization = Organization::findOrFail($validated['organization_id']);

        if ($organization->scenario_sandbox_created_at !== null) {
            // Defensive: the select is already filtered, but the value is
            // client-submitted and re-validated here before anything else.
            return back()->withInput()->with('error', __('organization_invitations.admin_sandbox_forbidden'));
        }

        $created = 0;
        $resent = 0;
        $alreadyMember = [];
        $usedElsewhere = [];

        foreach ($validated['people'] as $person) {
            $outcome = $this->invitations->invite(
                $organization,
                $request->user(),
                $person['email'],
                $person['first_name'],
                $person['last_name'],
                $validated['locale'] ?? OrganizationInvitation::DEFAULT_LOCALE,
            );

            if ($outcome['case'] === OrganizationInvitationService::CASE_CREATED) {
                $this->mailer->send($outcome['invitation']);
                $created++;
            } elseif ($outcome['case'] === OrganizationInvitationService::CASE_RESENT) {
                $this->mailer->send($outcome['invitation']);
                $resent++;
            } elseif ($outcome['case'] === OrganizationInvitationService::CASE_ALREADY_MEMBER) {
                $alreadyMember[] = $person['email'];
            } elseif ($outcome['case'] === OrganizationInvitationService::CASE_EMAIL_USED_ELSEWHERE) {
                $usedElsewhere[] = $person['email'];
            }
            // CASE_SANDBOX_FORBIDDEN cannot occur here: already refused above
            // for the whole batch before the loop starts.
        }

        return back()->with('bulkCreateResult', [
            'created' => $created,
            'resent' => $resent,
            'alreadyMember' => $alreadyMember,
            'usedElsewhere' => $usedElsewhere,
        ]);
    }

    public function resend(Request $request, OrganizationInvitation $invitation): RedirectResponse
    {
        $outcome = $this->invitations->invite(
            $invitation->organization,
            $request->user(),
            $invitation->recipient_email,
            $invitation->recipient_first_name,
            $invitation->recipient_name,
            $invitation->locale,
        );

        if (in_array($outcome['case'], [OrganizationInvitationService::CASE_CREATED, OrganizationInvitationService::CASE_RESENT], true)) {
            $this->mailer->send($outcome['invitation']);

            return back()->with('success', __('organization_invitations.admin_resent'));
        }

        return back()->with('error', __('organization_invitations.admin_resend_impossible'));
    }

    public function revoke(Request $request, OrganizationInvitation $invitation): RedirectResponse
    {
        try {
            $this->invitations->revoke($invitation);
        } catch (\RuntimeException) {
            return back()->with('error', __('organization_invitations.admin_revoke_impossible'));
        }

        return back()->with('success', __('organization_invitations.admin_revoked'));
    }

    public function show(OrganizationInvitation $invitation): View
    {
        $invitation->load(['organization', 'createdBy', 'acceptedBy']);

        return view('admin.users.bulk-create-show', compact('invitation'));
    }
}
