<?php

namespace App\Http\Controllers;

use App\Models\EmailLog;
use App\Models\Loop;
use App\Models\LoopInvitation;
use App\Models\Organization;
use App\Models\SystemEmailTemplate;
use App\Services\EmailerService;
use App\Services\LoopInvitationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

/**
 * Targeted e-mail invitations to a Loop.
 *
 * Route shape: the public landing page and the accept endpoint are flat
 * (/loop-invitations/{token}) rather than nested under the Loop. A token is
 * already globally unique, and LoopController's dual path/org-prefixed
 * union-typed $loopOrOrganization/$loop pattern only reserves the first two
 * positional slots — a third nested segment raises a TypeError.
 *
 * GET is read-only throughout: accepting always goes through a POST.
 */
class LoopInvitationController extends Controller
{
    public function __construct(private LoopInvitationService $invitations) {}

    /** Send an invitation from the Loop's own screens. */
    public function store(Request $request, Loop|Organization|string $loopOrOrganization, ?Loop $loop = null): RedirectResponse
    {
        $loop = $this->resolveLoop($loopOrOrganization, $loop);

        $this->authorize('update', $loop);

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);

        $invitation = $this->invitations->invite(
            $loop,
            $request->user(),
            $validated['email'],
            $validated['name'] ?? null,
            $validated['message'] ?? null,
        );

        $this->sendInvitationMail($invitation);

        return back()->with('success', __('loops.invitation_sent', ['email' => $invitation->recipient_email]));
    }

    public function revoke(Request $request, LoopInvitation $invitation): RedirectResponse
    {
        $this->authorize('update', $invitation->loop);

        try {
            $this->invitations->revoke($invitation);
        } catch (\RuntimeException) {
            return back()->with('error', __('loops.invitation_revoke_impossible'));
        }

        return back()->with('success', __('loops.invitation_revoked'));
    }

    /**
     * Public landing page. Read-only on purpose: it renders what the invitation
     * is and which action to take, but never mutates anything.
     */
    public function show(Request $request, string $token): View
    {
        $invitation = LoopInvitation::where('token', $token)
            ->with(['loop', 'organization', 'sender'])
            ->firstOrFail();

        $user = $request->user();

        return view('loops.invitation', [
            'invitation' => $invitation,
            'loop' => $invitation->loop,
            'organization' => $invitation->organization,
            'sender' => $invitation->sender,
            'isExpired' => $invitation->isExpired(),
            'isRevoked' => $invitation->isRevoked(),
            'isAccepted' => $invitation->isAccepted(),
            'emailMatches' => $user ? $invitation->matchesEmail($user->email) : null,
        ]);
    }

    /** Accept — POST only, authentication required. */
    public function accept(Request $request, string $token): RedirectResponse
    {
        $outcome = $this->invitations->accept($token, $request->user());

        return $this->redirectForOutcome($outcome, $token);
    }

    /**
     * Park the token and send the visitor to log in or register.
     *
     * A POST rather than a link: it writes to the session, and the registration
     * branch needs the Organization resolved from the invitation itself.
     */
    public function prepare(Request $request, string $token): RedirectResponse
    {
        $invitation = LoopInvitation::where('token', $token)->with('organization')->firstOrFail();

        session([LoopInvitation::SESSION_KEY => $invitation->token]);

        $target = $request->input('intent') === 'register' ? 'register' : 'login';
        $organization = $invitation->organization;

        if ($organization && Route::has("organization.{$target}")) {
            return redirect()->route("organization.{$target}", array_filter([
                'organization' => $organization->slug,
                // Prefilled purely for comfort — the server re-checks the address
                // after authentication and that check is the only guarantee.
                'email' => $invitation->recipient_email,
            ]));
        }

        return redirect()->route($target, ['email' => $invitation->recipient_email]);
    }

    /**
     * Shared landing after an accept attempt, wherever it was triggered from.
     *
     * @param  array{result: string, invitation: ?LoopInvitation}  $outcome
     */
    public function redirectForOutcome(array $outcome, ?string $token = null): RedirectResponse
    {
        $invitation = $outcome['invitation'];
        $loop = $invitation?->loop;

        $success = in_array($outcome['result'], [
            LoopInvitationService::RESULT_ACCEPTED,
            LoopInvitationService::RESULT_ALREADY_ACCEPTED_BY_SAME_USER,
        ], true);

        if ($success && $loop) {
            return redirect()->route('loops.show', $loop)
                ->with('success', __('loops.invitation_accepted', ['loop' => $loop->name]));
        }

        $message = match ($outcome['result']) {
            LoopInvitationService::RESULT_EMAIL_MISMATCH => __('loops.invitation_email_mismatch'),
            LoopInvitationService::RESULT_EXPIRED => __('loops.invitation_expired'),
            LoopInvitationService::RESULT_REVOKED => __('loops.invitation_revoked_notice'),
            LoopInvitationService::RESULT_TAKEN_BY_ANOTHER_USER => __('loops.invitation_taken'),
            LoopInvitationService::RESULT_USER_DEACTIVATED => __('loops.invitation_user_deactivated'),
            LoopInvitationService::RESULT_LOOP_UNAVAILABLE => __('loops.invitation_loop_unavailable'),
            default => __('loops.invitation_invalid'),
        };

        if ($token) {
            return redirect()->route('loop-invitations.show', $token)->with('error', $message);
        }

        return redirect()->route('loops.index')->with('error', $message);
    }

    /**
     * Render through the administrable `loop_invitation` system template, with an
     * explicit, logged Blade fallback.
     *
     * The fallback must never hide a configuration or rendering problem: the
     * reason it fired is written to the EmailLog payload and to the application
     * log, so a missing or broken template is visible rather than silently
     * papered over. A disabled template behaves exactly as elsewhere in the app
     * (referral_invitation): it is skipped and the fallback renders.
     */
    private function sendInvitationMail(LoopInvitation $invitation): void
    {
        $loop = $invitation->loop;
        $organization = $invitation->organization;
        $sender = $invitation->sender;
        $landingUrl = route('loop-invitations.show', $invitation->token);
        $personalMessage = filled($invitation->message)
            ? $invitation->message
            : __('loops.invitation_default_message', ['loop' => $loop?->name]);

        $template = SystemEmailTemplate::where('slug', 'loop_invitation')
            ->where('enabled', true)
            ->where('organization_id', $invitation->organization_id)
            ->where('locale', app()->getLocale())
            ->first();

        $emailer = app(EmailerService::class);
        $extraKeys = [
            'recipient_name', 'recipient_email', 'sender_name', 'organization_name',
            'loop_name', 'loop_tagline', 'personal_message', 'invitation_url',
            'expires_at', 'app_name',
        ];
        $vars = array_merge(
            $sender ? $emailer->availableVariables($sender) : [],
            [
                'recipient_name' => $invitation->recipient_name ?: $invitation->recipient_email,
                'recipient_email' => $invitation->recipient_email,
                'sender_name' => $sender?->fullName ?? '',
                'organization_name' => $organization?->name ?? '',
                'loop_name' => $loop?->name ?? '',
                'loop_tagline' => $loop?->tagline ?? '',
                'personal_message' => $personalMessage,
                'invitation_url' => $landingUrl,
                'expires_at' => optional($invitation->expires_at)->isoFormat('LL') ?? '',
                'app_name' => config('app.name'),
            ],
        );

        $fallbackReason = null;

        if ($template) {
            try {
                $subject = $emailer->interpolateSubject($template->subject, $vars, $extraKeys);
                $html = $emailer->interpolate($template->content_html, $vars, $extraKeys);
            } catch (\Throwable $e) {
                $fallbackReason = 'render_error: '.$e->getMessage();
            }
        } else {
            $fallbackReason = SystemEmailTemplate::where('slug', 'loop_invitation')
                ->where('organization_id', $invitation->organization_id)
                ->where('locale', app()->getLocale())
                ->exists()
                ? 'template_disabled'
                : 'template_missing';
        }

        if ($fallbackReason !== null) {
            Log::warning('loop_invitation e-mail fell back to the Blade template', [
                'reason' => $fallbackReason,
                'invitation_id' => $invitation->id,
                'organization_id' => $invitation->organization_id,
                'locale' => app()->getLocale(),
            ]);

            $subject = __('loops.invitation_mail_subject', ['loop' => $loop?->name]);
            $html = view('emails.loop-invitation', [
                'invitation' => $invitation,
                'loop' => $loop,
                'organization' => $organization,
                'sender' => $sender,
                'personalMessage' => $personalMessage,
                'landingUrl' => $landingUrl,
            ])->render();
        }

        $logData = [
            'source' => 'loop-invitation',
            'invitation_id' => $invitation->id,
            'loop_id' => $invitation->loop_id,
            'organization_id' => $invitation->organization_id,
            'invitation_type' => $invitation->invitation_type,
            'recipient_email' => $invitation->recipient_email,
            'template_used' => $fallbackReason === null ? 'system_email_template' : 'blade_fallback',
            'fallback_reason' => $fallbackReason,
        ];

        try {
            Mail::html($html, function ($message) use ($invitation, $subject) {
                $message->to($invitation->recipient_email)->subject($subject);
            });

            EmailLog::create([
                'user_id' => $invitation->sender_id,
                'organization_id' => $invitation->organization_id,
                'to_email' => $invitation->recipient_email,
                'subject' => $subject,
                'status' => 'sent',
                'data' => $logData,
            ]);
        } catch (\Throwable $e) {
            // The invitation row is already persisted and its link stays valid,
            // so a delivery failure is recoverable by re-sending; it must be
            // recorded rather than swallowed.
            EmailLog::create([
                'user_id' => $invitation->sender_id,
                'organization_id' => $invitation->organization_id,
                'to_email' => $invitation->recipient_email,
                'subject' => $subject,
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'data' => $logData,
            ]);
        }
    }

    private function resolveLoop(Loop|Organization|string $loopOrOrganization, ?Loop $loop): Loop
    {
        return $loop instanceof Loop ? $loop : $loopOrOrganization;
    }
}
