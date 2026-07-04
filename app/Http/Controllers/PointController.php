<?php

namespace App\Http\Controllers;

use App\Models\EmailLog;
use App\Models\SystemEmailTemplate;
use App\Models\Transaction;
use App\Services\EmailerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;
use Throwable;

class PointController extends Controller
{
    public function index(): View
    {
        $user = auth()->user();

        $entries = $user->pointLedger()
            ->with('transaction.service', 'transaction.serviceRequest')
            ->orderByDesc('created_at')
            ->paginate(20);

        $earned = $user->pointLedger()->where('delta', '>', 0)->sum('delta');
        $spent = abs($user->pointLedger()->where('delta', '<', 0)->sum('delta'));
        $completedCount = Transaction::where(function ($q) use ($user) {
            $q->where('buyer_id', $user->id)->orWhere('seller_id', $user->id);
        })->where('status', 'completed')->count();

        // Chart data: last 60 entries to calculate cumulative balance
        $chartEntries = $user->pointLedger()
            ->orderByDesc('created_at')
            ->limit(60)
            ->get();

        $history = [];
        $labels = [];
        $currentBalance = $user->points_balance;

        foreach ($chartEntries as $entry) {
            $history[] = $currentBalance;
            $labels[] = $entry->created_at->format('d/m H:i');
            $currentBalance -= $entry->delta;
        }

        // Add starting point (balance before the oldest of the 60 entries)
        if ($chartEntries->isNotEmpty()) {
            $history[] = $currentBalance;
            $labels[] = $chartEntries->last()->created_at->subMinute()->format('d/m H:i');
        } else {
            // If no history, just show current balance
            $history[] = $currentBalance;
            $labels[] = now()->format('d/m H:i');
        }

        $referralPointsEarned = $user->referralRewards()->sum('points');
        $sentReferralsCount = $user->sentReferrals()->count();
        $activatedReferralsCount = $user->sentReferrals()->where('status', 'activated')->count();
        $referralLink = $user->organization?->slug && $user->referral_code
            ? route('organization.register', ['organization' => $user->organization->slug, 'ref' => $user->referral_code])
            : null;

        $orgSlug = $user->organization?->slug;

        $history = array_reverse($history);
        $labels = array_reverse($labels);

        return view('points.index', compact(
            'entries', 'earned', 'spent', 'completedCount', 'history', 'labels',
            'referralPointsEarned', 'sentReferralsCount', 'activatedReferralsCount', 'referralLink',
            'orgSlug',
        ));
    }

    public function sendInvitation(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'recipient_email' => 'required|email',
            'recipient_name' => 'nullable|string|max:255',
            'message' => 'nullable|string|max:2000',
        ]);

        $user = auth()->user();
        $referralLink = $user->organization?->slug && $user->referral_code
            ? route('organization.register', ['organization' => $user->organization->slug, 'ref' => $user->referral_code])
            : null;

        if (! $referralLink) {
            return back()->with('error', __('points.email_error'));
        }

        $template = SystemEmailTemplate::where('slug', 'referral_invitation')
            ->where('enabled', true)
            ->where('organization_id', $user->organization_id)
            ->where('locale', app()->getLocale())
            ->first();

        $emailer = app(EmailerService::class);
        $senderMessage = filled($data['message'] ?? null)
            ? $data['message']
            : __('points.invitation_message_placeholder');

        $vars = array_merge($emailer->availableVariables($user), [
            'sender_name' => $user->fullName,
            'recipient_name' => $data['recipient_name'] ?? '',
            'sender_message' => $senderMessage,
            'referral_link' => $referralLink,
        ]);
        $extraKeys = ['sender_name', 'recipient_name', 'sender_message', 'referral_link'];

        if ($template) {
            $subject = $emailer->interpolateSubject($template->subject, $vars, $extraKeys);
            $html = $emailer->interpolate($template->content_html, $vars, $extraKeys);
        } else {
            $messageHtml = nl2br(e($senderMessage));
            $html = view('emails.referral-invitation', [
                'senderName' => $user->fullName,
                'messageHtml' => $messageHtml,
                'referralLink' => $referralLink,
            ])->render();
            $subject = __('points.email_default_subject');
        }

        try {
            Mail::html($html, function ($message) use ($data, $subject) {
                $message->to($data['recipient_email'])
                    ->subject($subject);
            });

            EmailLog::create([
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'to_email' => $data['recipient_email'],
                'subject' => $subject,
                'status' => 'sent',
                'data' => [
                    'source' => 'referral-invitation',
                ],
            ]);

            return back()->with('success', __('points.email_success'));
        } catch (Throwable $e) {
            EmailLog::create([
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'to_email' => $data['recipient_email'],
                'subject' => $subject,
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'data' => [
                    'source' => 'referral-invitation',
                ],
            ]);

            return back()->with('error', __('points.email_error'));
        }
    }
}
