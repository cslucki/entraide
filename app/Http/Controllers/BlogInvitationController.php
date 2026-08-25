<?php

namespace App\Http\Controllers;

use App\Models\BlogPost;
use App\Models\BlogPostInvitation;
use App\Models\EmailLog;
use App\Models\SystemEmailTemplate;
use App\Models\User;
use App\Services\BlogInvitationService;
use App\Services\EmailerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Throwable;

class BlogInvitationController extends Controller
{
    public function index(BlogPost $post): JsonResponse
    {
        $organization = currentOrganization();
        if (! $organization || $post->organization_id !== $organization->id) {
            abort(404);
        }

        Gate::authorize('update', $post);

        $invitations = BlogPostInvitation::where('blog_post_id', $post->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn (BlogPostInvitation $inv) => [
                'id' => $inv->id,
                'to_email' => $inv->recipient_email,
                'recipient_name' => $inv->recipient_name,
                'invitation_type' => $inv->invitation_type,
                'status' => $inv->isExpired() ? 'failed' : 'sent',
                'sent_at' => $inv->created_at->toISOString(),
            ]);

        return response()->json(['invitations' => $invitations]);
    }

    public function store(Request $request, BlogPost $post): JsonResponse
    {
        $organization = currentOrganization();
        if (! $organization || $post->organization_id !== $organization->id) {
            abort(404);
        }

        Gate::authorize('update', $post);

        $data = $request->validate([
            'recipient_email' => 'required|email',
            'recipient_name' => 'nullable|string|max:255',
            'message' => 'nullable|string|max:2000',
        ]);

        $sender = $request->user();
        $organization = currentOrganization();
        $existingMember = User::discoverable()
            ->where('organization_id', $organization->id)
            ->where('email', $data['recipient_email'])
            ->first();

        $isExistingMember = $existingMember !== null;
        $invitationType = $isExistingMember ? 'existing_member' : 'external';

        $invitation = BlogPostInvitation::create([
            'blog_post_id' => $post->id,
            'sender_id' => $sender->id,
            'recipient_email' => $data['recipient_email'],
            'recipient_name' => $data['recipient_name'] ?? null,
            'message' => $data['message'] ?? null,
            'invitation_type' => $invitationType,
            'organization_id' => $organization->id,
        ]);

        $invitationUrl = route('blog.invite.show', ['token' => $invitation->token]);
        $senderMessage = filled($data['message'] ?? null)
            ? $data['message']
            : __('blog-invitation.default_message');

        $vars = [
            'sender_name' => $sender->fullName,
            'recipient_name' => $data['recipient_name'] ?? $data['recipient_email'],
            'sender_message' => $senderMessage,
            'invitation_url' => $invitationUrl,
            'article_title' => $post->title,
        ];

        $extraKeys = ['sender_name', 'recipient_name', 'sender_message', 'invitation_url', 'article_title'];

        if (! $isExistingMember && $sender->organization?->slug && $sender->referral_code) {
            $vars['register_url'] = route('organization.register', [
                'organization' => $sender->organization->slug,
                'ref' => $sender->referral_code,
            ]);
            $extraKeys[] = 'register_url';
        }

        $template = SystemEmailTemplate::where('slug', 'blog_contribution_invitation')
            ->where('enabled', true)
            ->where('organization_id', $organization->id)
            ->where('locale', app()->getLocale())
            ->first();

        if ($template) {
            $emailer = app(EmailerService::class);
            $subject = $emailer->interpolateSubject($template->subject, $vars, $extraKeys);
            $html = $emailer->interpolate($template->content_html, $vars, $extraKeys);
        } else {
            $subject = __('blog-invitation.email_subject', [
                'sender' => $sender->fullName,
                'title' => $post->title,
            ]);

            $html = view('emails.blog-invitation', [
                'senderName' => $sender->fullName,
                'recipientName' => $data['recipient_name'] ?? $data['recipient_email'],
                'senderMessage' => $senderMessage,
                'invitationUrl' => $invitationUrl,
                'articleTitle' => $post->title,
                'registerUrl' => $vars['register_url'] ?? null,
                'isExistingMember' => $isExistingMember,
            ])->render();
        }

        try {
            Mail::html($html, function ($message) use ($data, $subject) {
                $message->to($data['recipient_email'])
                    ->subject($subject);
            });

            EmailLog::create([
                'user_id' => $sender->id,
                'organization_id' => $organization->id,
                'to_email' => $data['recipient_email'],
                'subject' => $subject,
                'status' => 'sent',
                'data' => [
                    'source' => 'blog-contribution-invitation',
                    'template_slug' => 'blog_contribution_invitation',
                    'blog_post_id' => $post->id,
                    'blog_post_slug' => $post->slug,
                    'sender_id' => $sender->id,
                    'recipient_name' => $data['recipient_name'] ?? null,
                    'invitation_type' => $invitationType,
                    'invitation_id' => $invitation->id,
                    'invitation_token' => $invitation->token,
                ],
            ]);

            $msg = $isExistingMember
                ? __('blog-invitation.sent_to_member')
                : __('blog-invitation.sent_to_external');

            return response()->json(['success' => true, 'message' => $msg, 'invitation_type' => $invitationType]);
        } catch (Throwable $e) {
            $invitation->update(['status' => 'expired']);

            EmailLog::create([
                'user_id' => $sender->id,
                'organization_id' => $organization->id,
                'to_email' => $data['recipient_email'],
                'subject' => $subject,
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'data' => [
                    'source' => 'blog-contribution-invitation',
                    'template_slug' => 'blog_contribution_invitation',
                    'blog_post_id' => $post->id,
                    'blog_post_slug' => $post->slug,
                    'sender_id' => $sender->id,
                    'recipient_name' => $data['recipient_name'] ?? null,
                    'invitation_type' => $invitationType,
                    'invitation_id' => $invitation->id,
                    'invitation_token' => $invitation->token,
                ],
            ]);

            return response()->json([
                'success' => false,
                'message' => __('blog-invitation.email_error'),
            ], 500);
        }
    }

    public function show(string $token)
    {
        $invitation = BlogPostInvitation::where('token', $token)->firstOrFail();

        $post = $invitation->blogPost;
        $sender = $invitation->sender;

        return view('blog.invitation', [
            'invitation' => $invitation,
            'post' => $post,
            'sender' => $sender,
            'isExpired' => $invitation->isExpired(),
            'isAccepted' => $invitation->status === 'accepted',
        ]);
    }

    /** Accept — POST only, authentication required. */
    public function accept(Request $request, string $token)
    {
        $user = $request->user();

        if (! $user) {
            return $this->prepare($request, $token);
        }

        return $this->redirectForOutcome(
            app(BlogInvitationService::class)->accept($token, $user),
            $token,
        );
    }

    /**
     * Park the token and send the visitor to log in or register.
     *
     * A POST rather than a link: it writes to the session, and the acceptance
     * itself is picked up inside the login/registration POST — never on a GET.
     */
    public function prepare(Request $request, string $token)
    {
        $invitation = BlogPostInvitation::where('token', $token)->with('organization')->firstOrFail();

        session([BlogInvitationService::SESSION_KEY => $invitation->token]);

        $target = $request->input('intent') === 'register' ? 'register' : 'login';
        $organization = $invitation->organization;

        // Organization-scoped when possible: an external invitee must land in
        // the tenant the article belongs to, not on the bare route.
        $params = array_filter([
            'ref' => $invitation->sender?->referral_code,
            // Comfort only — the server re-checks the address after
            // authentication and that check is the sole guarantee.
            'email' => $invitation->recipient_email,
        ]);

        if ($organization && Route::has("organization.{$target}")) {
            return redirect()->route("organization.{$target}", array_merge(
                ['organization' => $organization->slug],
                $params,
            ));
        }

        return redirect()->route($target, $params);
    }

    /**
     * Shared landing after an accept attempt, wherever it was triggered from.
     *
     * @param  array{result: string, invitation: ?BlogPostInvitation}  $outcome
     */
    public function redirectForOutcome(array $outcome, ?string $token = null)
    {
        $invitation = $outcome['invitation'];
        $post = $invitation?->blogPost;

        $success = in_array($outcome['result'], [
            BlogInvitationService::RESULT_ACCEPTED,
            BlogInvitationService::RESULT_ALREADY_ACCEPTED_BY_SAME_USER,
        ], true);

        if ($success && $post) {
            // TASK-1281 : la route courte resout l'Organization par defaut —
            // accepter une invitation de co-redaction d'une autre Organization
            // ouvrait l'editeur hors tenant. L'Organization vient de l'Article,
            // jamais de la requete (la route d'acceptation est plate).
            $organization = $post->organization;
            $target = $organization && Route::has('organization.blog.edit')
                ? route('organization.blog.edit', ['organization' => $organization, 'post' => $post->slug])
                : route('blog.edit', ['post' => $post->slug]);

            return redirect($target);
        }

        $message = match ($outcome['result']) {
            BlogInvitationService::RESULT_EMAIL_MISMATCH => __('blog-invitation.accept_email_mismatch'),
            BlogInvitationService::RESULT_EXPIRED => __('blog-invitation.accept_expired'),
            BlogInvitationService::RESULT_TAKEN_BY_ANOTHER_USER => __('blog-invitation.accept_taken'),
            BlogInvitationService::RESULT_USER_DEACTIVATED => __('blog-invitation.accept_user_deactivated'),
            BlogInvitationService::RESULT_POST_UNAVAILABLE => __('blog-invitation.accept_post_unavailable'),
            default => __('blog-invitation.accept_invalid'),
        };

        if ($token) {
            return redirect()->route('blog.invite.show', $token)->with('error', $message);
        }

        return redirect()->route('home')->with('error', $message);
    }

    public function orgIndex(string $org, BlogPost $post): JsonResponse
    {
        return $this->index($post);
    }

    public function orgStore(Request $request, string $org, BlogPost $post): JsonResponse
    {
        return $this->store($request, $post);
    }
}
