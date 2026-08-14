{{--
    Safety fallback only. The administrable `loop_invitation` system template is
    the normal path; this view renders when that template is missing, disabled or
    fails to interpolate — and the reason is always written to the EmailLog
    payload and the application log, never swallowed.
--}}
<div style="font-family: -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif; color: #1f2937; line-height: 1.6;">
    <h1 style="font-size: 20px; font-weight: 700; margin: 0 0 16px;">
        {{ __('loops.invitation_mail_heading', ['sender' => $sender?->fullName ?? '', 'loop' => $loop?->name ?? '']) }}
    </h1>

    <p style="margin: 0 0 12px;">
        {{ __('loops.invitation_mail_greeting', ['name' => $invitation->recipient_name ?: $invitation->recipient_email]) }}
    </p>

    <p style="margin: 0 0 12px;">
        {{ __('loops.invitation_mail_body', [
            'sender' => $sender?->fullName ?? '',
            'loop' => $loop?->name ?? '',
            'organization' => $organization?->name ?? '',
        ]) }}
    </p>

    @if(filled($loop?->tagline))
        <p style="margin: 0 0 12px; font-style: italic; color: #4b5563;">{{ $loop->tagline }}</p>
    @endif

    @if(filled($personalMessage))
        <blockquote style="margin: 0 0 16px; padding: 12px 16px; border-left: 3px solid #6366f1; background: #f5f3ff; color: #374151;">
            {!! nl2br(e($personalMessage)) !!}
        </blockquote>
    @endif

    <p style="margin: 0 0 20px;">
        <a href="{{ $landingUrl }}" style="display: inline-block; padding: 12px 20px; border-radius: 10px; background: #4f46e5; color: #ffffff; text-decoration: none; font-weight: 600;">
            {{ __('loops.invitation_mail_cta') }}
        </a>
    </p>

    @if($invitation->expires_at)
        <p style="margin: 0 0 8px; font-size: 13px; color: #6b7280;">
            {{ __('loops.invitation_mail_expires', ['date' => $invitation->expires_at->isoFormat('LL')]) }}
        </p>
    @endif

    <p style="margin: 0; font-size: 13px; color: #9ca3af;">— {{ config('app.name') }}</p>
</div>
