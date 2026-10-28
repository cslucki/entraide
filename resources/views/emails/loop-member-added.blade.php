{{--
    TASK-1719 — repli de securite SEULEMENT, pour l'ajout direct dans une
    Boucle. Le gabarit administrable `loop_invitation` est le chemin normal ;
    cette vue rend quand il manque, est desactive, ou echoue a s'interpoler —
    et la raison est toujours ecrite au journal, jamais avalee.

    Le texte dit « ajoute », pas « invite » : la personne EST membre quand elle
    lit ce message, il n'y a rien a accepter.
--}}
<div style="font-family: -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif; color: #111827; line-height: 1.6;">
    <p>{{ __('loops.member_added_mail_hello', ['name' => $membre->publicDisplayName()]) }}</p>

    <p>
        @if($acteur)
            {{ __('loops.member_added_mail_body_with_actor', [
                'actor' => $acteur->fullName,
                'loop' => $loop->name,
                'organization' => $organization?->name ?? config('app.name'),
            ]) }}
        @else
            {{ __('loops.member_added_mail_body', [
                'loop' => $loop->name,
                'organization' => $organization?->name ?? config('app.name'),
            ]) }}
        @endif
    </p>

    @if($loop->tagline)
        <p style="color: #6b7280; font-style: italic;">{{ $loop->tagline }}</p>
    @endif

    <p style="margin: 24px 0;">
        <a href="{{ $url }}"
           style="background: #4f46e5; color: #ffffff; padding: 12px 20px; border-radius: 8px; text-decoration: none; font-weight: 600; display: inline-block;">
            {{ __('loops.member_added_mail_cta') }}
        </a>
    </p>

    <p style="color: #6b7280; font-size: 13px;">
        {{ __('loops.member_added_mail_plain_link') }}<br>
        <span style="word-break: break-all;">{{ $url }}</span>
    </p>

    <p style="color: #6b7280; font-size: 13px;">{{ config('app.name') }}</p>
</div>
