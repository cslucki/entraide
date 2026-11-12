{{--
    TASK-1809 (micro-sequence B) — meme structure en tableaux que le theme
    `mail::` du framework (`vendor/laravel/framework/.../html/layout.blade.php`
    + `header.blade.php` + `footer.blade.php`) et memes classes CSS que
    `resources/views/vendor/mail/html/themes/bouclepro.css`, mais le theme
    est incorpore en `<style>` plutot qu'inline par attribut — meme choix
    deja accepte dans ce depot par `blog-invitation.blade.php` et
    `referral-invitation.blade.php`.

    Deux pieges ECARTES sur preuve, pas par choix de depart — voir le
    docbloc de `NotificationEmailDeliverer::habiller()` pour la mesure de
    chacun :

    1. PAS `Illuminate\Mail\Markdown::parse()` (contrairement a
       `emails.system-email.blade.php`) : ce parseur CommonMark ne traite un
       `<div>`/`<p>`... en bloc HTML brut que jusqu'a la premiere ligne
       vide — au-dela, tout repart en markdown ordinaire, et les gabarits de
       repli Blade de ce module (plusieurs lignes, lignes vides entre les
       balises) en faisaient les frais.
    2. PAS `CssToInlineStyles` (donc pas de `DOMDocument::loadHTML/saveHTML`) :
       il re-encode les caracteres qu'il juge invalides dans un `href`
       (`[` devient `%5B`), ce qui deforme le marqueur expurge anti-jeton de
       `NotificationEmailDeliverer`.

    `{!! $html !!}` est donc injecte tel quel, sans aucun parseur ni
    serialiseur DOM entre l'administrateur et le transport.
--}}
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<title>{{ config('app.name') }}</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<style>
{!! $themeCss !!}

@media only screen and (max-width: 600px) {
.inner-body {
width: 100% !important;
}

.footer {
width: 100% !important;
}
}

@media only screen and (max-width: 500px) {
.button {
width: 100% !important;
}
}
</style>
</head>
<body>

<table class="wrapper" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="center">
<table class="content" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="header">
<a href="{{ config('app.url') }}" style="display: inline-block;">{{ config('app.name') }}</a>
</td>
</tr>

<!-- Email Body -->
<tr>
<td class="body" width="100%" cellpadding="0" cellspacing="0" style="border: hidden !important;">
<table class="inner-body" align="center" width="570" cellpadding="0" cellspacing="0" role="presentation">
<!-- Body content -->
<tr>
<td class="content-cell">
{!! $html !!}
</td>
</tr>
</table>
</td>
</tr>

<tr>
<td>
<table class="footer" align="center" width="570" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="content-cell" align="center">
<p>&copy; {{ date('Y') }} {{ config('app.name') }}. {{ __('All rights reserved.') }}</p>
</td>
</tr>
</table>
</td>
</tr>
</table>
</td>
</tr>
</table>
</body>
</html>
