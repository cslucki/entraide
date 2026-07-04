<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('points.email_default_subject') }}</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; line-height: 1.6; color: #1f2937; margin: 0; padding: 0; background-color: #f3f4f6; }
        .wrapper { max-width: 600px; margin: 0 auto; padding: 24px; }
        .card { background: #ffffff; border-radius: 12px; padding: 32px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        h2 { color: #4338ca; font-size: 20px; margin-top: 0; }
        .message { margin: 20px 0; white-space: pre-wrap; }
        .button { display: inline-block; padding: 12px 24px; background-color: #4338ca; color: #ffffff; text-decoration: none; border-radius: 8px; font-weight: 600; font-size: 14px; }
        .link { margin-top: 16px; word-break: break-all; }
        .link a { color: #4338ca; }
        hr { border: none; border-top: 1px solid #e5e7eb; margin: 24px 0; }
        .footer { font-size: 12px; color: #9ca3af; text-align: center; }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="card">
            <h2>{{ $senderName }} {{ __('points.email_default_subject') }}</h2>
            <div class="message">
                {!! $messageHtml !!}
            </div>
            <div class="link">
                <a href="{{ $referralLink }}">{{ $referralLink }}</a>
            </div>
            <hr>
            <div class="footer">
                {{ config('mail.from.name') ?: config('app.name') }}
            </div>
        </div>
    </div>
</body>
</html>
