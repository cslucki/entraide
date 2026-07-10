<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('blog-invitation.email_subject', ['sender' => $senderName, 'title' => $articleTitle]) }}</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; line-height: 1.6; color: #1f2937; margin: 0; padding: 0; background-color: #f3f4f6; }
        .wrapper { max-width: 600px; margin: 0 auto; padding: 24px; }
        .card { background: #ffffff; border-radius: 12px; padding: 32px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        h2 { color: #4338ca; font-size: 20px; margin-top: 0; }
        .article-badge { display: inline-block; padding: 4px 10px; background-color: #eef2ff; color: #4338ca; border-radius: 6px; font-size: 12px; font-weight: 600; margin-bottom: 12px; }
        .message { margin: 16px 0; white-space: pre-wrap; }
        .buttons { margin: 24px 0; display: flex; gap: 12px; flex-wrap: wrap; }
        .button { display: inline-block; padding: 12px 24px; background-color: #4338ca; color: #ffffff; text-decoration: none; border-radius: 8px; font-weight: 600; font-size: 14px; }
        .button-secondary { display: inline-block; padding: 12px 24px; background-color: #059669; color: #ffffff; text-decoration: none; border-radius: 8px; font-weight: 600; font-size: 14px; }
        .link { margin-top: 16px; word-break: break-all; }
        .link a { color: #4338ca; }
        hr { border: none; border-top: 1px solid #e5e7eb; margin: 24px 0; }
        .footer { font-size: 12px; color: #9ca3af; text-align: center; }
        .notice { margin-top: 20px; padding: 16px; background-color: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; font-size: 13px; color: #6b7280; line-height: 1.5; }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="card">
            <div class="article-badge">{{ __('blog-invitation.email_badge') }}</div>
            <h2>{{ $senderName }} {{ __('blog-invitation.email_heading') }}</h2>
            <p><strong>{{ $recipientName }}</strong></p>
            <div class="message">
                {!! nl2br(e($senderMessage)) !!}
            </div>
            <div class="buttons">
                <a href="{{ $articleUrl }}" class="button">{{ __('blog-invitation.email_read_article') }}</a>
                @if(! $isExistingMember && isset($registerUrl) && $registerUrl)
                <a href="{{ $registerUrl }}" class="button-secondary">{{ __('blog-invitation.email_register') }}</a>
                @endif
            </div>
            <div class="notice">
                {!! __('blog-invitation.email_notice', [
                    'sender_name' => $senderName,
                    'article_title' => $articleTitle,
                    'article_url' => $articleUrl,
                ]) !!}
            </div>
            <hr>
            <div class="footer">
                {{ config('mail.from.name') ?: config('app.name') }}
            </div>
        </div>
    </div>
</body>
</html>
