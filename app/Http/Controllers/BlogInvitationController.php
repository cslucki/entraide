<?php

namespace App\Http\Controllers;

use App\Models\BlogPost;
use App\Models\EmailLog;
use App\Models\SystemEmailTemplate;
use App\Models\User;
use App\Services\EmailerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Throwable;

class BlogInvitationController extends Controller
{
    public function store(Request $request, BlogPost $post): RedirectResponse
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

        if ($post->status !== 'published') {
            throw ValidationException::withMessages([
                'recipient_email' => __('blog-invitation.draft_not_allowed'),
            ]);
        }

        $sender = $request->user();
        $organization = currentOrganization();
        $existingMember = User::where('organization_id', $organization->id)
            ->where('email', $data['recipient_email'])
            ->first();

        $isExistingMember = $existingMember !== null;
        $invitationType = $isExistingMember ? 'existing_member' : 'external';

        $articleUrl = route('blog.show', ['post' => $post]);
        $senderMessage = filled($data['message'] ?? null)
            ? $data['message']
            : __('blog-invitation.default_message');

        $vars = [
            'sender_name' => $sender->fullName,
            'recipient_name' => $data['recipient_name'] ?? $data['recipient_email'],
            'sender_message' => $senderMessage,
            'article_url' => $articleUrl,
            'article_title' => $post->title,
        ];

        $extraKeys = ['sender_name', 'recipient_name', 'sender_message', 'article_url', 'article_title'];

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
                'articleUrl' => $articleUrl,
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
                    'blog_post_id' => $post->id,
                    'blog_post_slug' => $post->slug,
                    'sender_id' => $sender->id,
                    'recipient_name' => $data['recipient_name'] ?? null,
                    'invitation_type' => $invitationType,
                ],
            ]);

            $flashKey = 'success';
            $flashMsg = $isExistingMember
                ? __('blog-invitation.sent_to_member')
                : __('blog-invitation.sent_to_external');
        } catch (Throwable $e) {
            EmailLog::create([
                'user_id' => $sender->id,
                'organization_id' => $organization->id,
                'to_email' => $data['recipient_email'],
                'subject' => $subject,
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'data' => [
                    'source' => 'blog-contribution-invitation',
                    'blog_post_id' => $post->id,
                    'blog_post_slug' => $post->slug,
                    'sender_id' => $sender->id,
                    'recipient_name' => $data['recipient_name'] ?? null,
                    'invitation_type' => $invitationType,
                ],
            ]);

            $flashKey = 'error';
            $flashMsg = __('blog-invitation.email_error');
        }

        return back()->with($flashKey, $flashMsg);
    }

    public function orgStore(Request $request, string $org, BlogPost $post): RedirectResponse
    {
        return $this->store($request, $post);
    }
}
