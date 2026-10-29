{{--
    TASK-1725 — repli de securite SEULEMENT, pour la demande d'adhesion
    acceptee. Le gabarit administrable `loop_join_request_accepted` est le
    chemin normal ; cette vue rend quand il manque, est desactive, ou echoue a
    s'interpoler — et la raison est toujours ecrite au journal, jamais avalee.

    Le texte repond a une DEMARCHE : « votre demande a ete acceptee », et non
    « on vous a ajoute ». La personne a frappe a la porte, elle attend un oui.

    Le decideur peut etre absent : une acceptation sans acteur identifie n'a
    personne a nommer, et le second libelle ne cite alors plus qu'une decision.
--}}
<div style="font-family: -apple-system, Segoe UI, Roboto, Helvetica, Arial, sans-serif; color: #111827; line-height: 1.6;">
    <p>{{ __('loops.join_request_accepted_mail_hello', ['name' => $demandeur->publicDisplayName()]) }}</p>

    <p>
        @if($decideur)
            {{ __('loops.join_request_accepted_mail_body_with_actor', [
                'actor' => $decideur->fullName,
                'loop' => $loop->name,
                'organization' => $organization?->name ?? config('app.name'),
            ]) }}
        @else
            {{ __('loops.join_request_accepted_mail_body', [
                'loop' => $loop->name,
                'organization' => $organization?->name ?? config('app.name'),
            ]) }}
        @endif
    </p>

    <p style="margin: 24px 0;">
        <a href="{{ $url }}"
           style="background: #4f46e5; color: #ffffff; padding: 12px 20px; border-radius: 8px; text-decoration: none; font-weight: 600; display: inline-block;">
            {{ __('loops.join_request_accepted_mail_cta') }}
        </a>
    </p>

    <p style="color: #6b7280; font-size: 13px;">
        {{ __('loops.join_request_accepted_mail_plain_link') }}<br>
        <span style="word-break: break-all;">{{ $url }}</span>
    </p>

    <p style="color: #6b7280; font-size: 13px;">{{ config('app.name') }}</p>
</div>
