{{--
    Safety fallback only. The administrable `organization_invitation` system
    template is the normal path; this view renders when that template is
    missing, disabled or fails to interpolate — and the reason is always
    written to the EmailLog payload and the application log, never
    swallowed.
--}}
<div style="font-family: -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif; color: #1f2937; line-height: 1.6;">
    <h1 style="font-size: 20px; font-weight: 700; margin: 0 0 16px;">
        {{ __('organization_invitations.mail_heading', ['organization' => $organization?->name ?? '']) }}
    </h1>

    <p style="margin: 0 0 12px;">
        {{ __('organization_invitations.mail_greeting', ['name' => $invitation->recipientFullName()]) }}
    </p>

    <p style="margin: 0 0 12px;">
        {{ __('organization_invitations.mail_body', [
            'organization' => $organization?->name ?? '',
            'sender' => $sender?->fullName ?? '',
        ]) }}
    </p>

    <p style="margin: 0 0 20px;">
        <a href="{{ $landingUrl }}" style="display: inline-block; padding: 12px 20px; border-radius: 10px; background: #4f46e5; color: #ffffff; text-decoration: none; font-weight: 600;">
            {{ __('organization_invitations.mail_cta', ['organization' => $organization?->name ?? '']) }}
        </a>
    </p>

    @if($invitation->expires_at)
        <p style="margin: 0 0 8px; font-size: 13px; color: #6b7280;">
            {{ __('organization_invitations.mail_expires', ['date' => $invitation->expires_at->isoFormat('LL')]) }}
        </p>
    @endif

    <p style="margin: 0; font-size: 13px; color: #9ca3af;">— {{ config('app.name') }}</p>
</div>
