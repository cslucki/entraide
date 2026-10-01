<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Loop;
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

        $status = $request->input('status');

        $invitations = OrganizationInvitation::with(['organization', 'loop', 'createdBy', 'acceptedBy'])
            ->when($request->filled('organization_id'), fn ($q) => $q->where('organization_id', $request->input('organization_id')))
            // « Expirée » ne se lit pas dans la seule colonne `status` : une
            // ligne reste `pending` en base jusqu'a ce qu'un passage la
            // perime. La date fait donc foi, exactement comme
            // OrganizationInvitation::isPending() et le badge du tableau —
            // sinon le filtre et l'affichage se contrediraient.
            ->when($status === OrganizationInvitation::STATUS_PENDING, fn ($q) => $q
                ->where('status', OrganizationInvitation::STATUS_PENDING)
                ->where(fn ($sub) => $sub->whereNull('expires_at')->orWhere('expires_at', '>', now())))
            ->when($status === OrganizationInvitation::STATUS_EXPIRED, fn ($q) => $q
                ->where(fn ($sub) => $sub->where('status', OrganizationInvitation::STATUS_EXPIRED)
                    ->orWhere(fn ($stale) => $stale->where('status', OrganizationInvitation::STATUS_PENDING)
                        ->whereNotNull('expires_at')
                        ->where('expires_at', '<=', now()))))
            ->when(in_array($status, [OrganizationInvitation::STATUS_ACCEPTED, OrganizationInvitation::STATUS_REVOKED], true),
                fn ($q) => $q->where('status', $status))
            ->orderByDesc('created_at')
            ->paginate(30)
            ->withQueryString();

        // Les Boucles proposables, groupees par Organization : le selecteur
        // se filtre cote client sur l'Organization choisie, sans aller-retour
        // serveur. Seules les Boucles ACTIVES des Organizations elles-memes
        // proposables sont envoyees.
        $loopsByOrganization = Loop::query()
            ->whereIn('organization_id', $organizations->pluck('id'))
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'organization_id', 'visibility'])
            ->groupBy('organization_id');

        return view('admin.users.bulk-create', [
            'organizations' => $organizations,
            'loopsByOrganization' => $loopsByOrganization,
            'invitations' => $invitations,
            'selectedOrganizationId' => $request->input('organization_id'),
            'selectedStatus' => $status,
            'hostOverrideAllowed' => OrganizationInvitation::hostOverrideAllowed(),
            'statusCounts' => $this->statusCounts($request->input('organization_id')),
        ]);
    }

    /**
     * Combien d'invitations dans chaque etat, pour les onglets du suivi.
     *
     * Meme definition de « expiree » que le filtre et que le badge du
     * tableau : la date fait foi, pas seulement la colonne `status`.
     *
     * @return array<string, int>
     */
    private function statusCounts(?string $organizationId): array
    {
        $base = fn () => OrganizationInvitation::query()
            ->when($organizationId, fn ($q) => $q->where('organization_id', $organizationId));

        $expired = fn ($q) => $q->where(fn ($sub) => $sub->where('status', OrganizationInvitation::STATUS_EXPIRED)
            ->orWhere(fn ($stale) => $stale->where('status', OrganizationInvitation::STATUS_PENDING)
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', now())));

        return [
            'all' => $base()->count(),
            OrganizationInvitation::STATUS_PENDING => $base()
                ->where('status', OrganizationInvitation::STATUS_PENDING)
                ->where(fn ($sub) => $sub->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->count(),
            OrganizationInvitation::STATUS_ACCEPTED => $base()->where('status', OrganizationInvitation::STATUS_ACCEPTED)->count(),
            OrganizationInvitation::STATUS_EXPIRED => $expired($base())->count(),
            OrganizationInvitation::STATUS_REVOKED => $base()->where('status', OrganizationInvitation::STATUS_REVOKED)->count(),
        ];
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'organization_id' => ['required', 'uuid', 'exists:organizations,id'],
            'locale' => ['nullable', Rule::in(OrganizationInvitation::LOCALES)],
            // La Boucle doit appartenir a l'Organization choisie : la
            // frontiere de tenant se verifie dans la requete elle-meme, pas
            // seulement plus loin.
            'loop_id' => ['nullable', 'uuid', Rule::exists('loops', 'id')->where('organization_id', $request->input('organization_id'))],
            // « Host de test » : refuse cote SERVEUR hors local/testing, et
            // pas seulement masque dans la vue. Le masquage d'une UI n'est
            // pas une garde — un POST direct l'ignore.
            'host_override' => [
                'nullable', 'string', 'max:255',
                function (string $attribute, $value, \Closure $fail) {
                    if (blank($value)) {
                        return;
                    }
                    if (! OrganizationInvitation::hostOverrideAllowed()) {
                        $fail(__('organization_invitations.host_not_allowed_here'));

                        return;
                    }
                    if (OrganizationInvitation::normalizeHostOverride($value) === null) {
                        $fail(__('organization_invitations.host_invalid'));
                    }
                },
            ],
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
                $validated['loop_id'] ?? null,
                $validated['host_override'] ?? null,
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
            $invitation->loop_id,
            // Exigence MASTER : une relance ne revient jamais silencieusement
            // au lien canonique — on repasse l'override deja enregistre.
            $invitation->host_override,
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
        $invitation->load(['organization', 'loop', 'createdBy', 'acceptedBy']);

        return view('admin.users.bulk-create-show', compact('invitation'));
    }
}
