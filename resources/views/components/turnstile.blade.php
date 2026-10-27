{{--
    TASK-1713 — le widget Cloudflare Turnstile.

    Rendu UNIQUEMENT si le captcha est actif ET si une cle publique existe. Les
    deux conditions sont distinctes : `data-sitekey=""` produit un widget qui
    echoue sans dire pourquoi, et il valait mieux ne rien afficher que d'afficher
    un defi impossible. L'absence de cle avec le drapeau actif ne laisse pas
    passer pour autant — `TurnstileVerifier` refuse l'inscription cote serveur
    (fail-closed, motif `missing-secret`).

    La cle publique n'est pas un secret : Cloudflare la publie dans le HTML du
    widget, c'est sa raison d'etre. Seule `TURNSTILE_SECRET_KEY` en est un, et
    elle ne quitte jamais le serveur.

    Le THEME ne peut pas etre rendu par le serveur. `data-theme="auto"` suit
    `prefers-color-scheme`, c'est-a-dire le reglage du SYSTEME — alors que le
    mode sombre de BouclePro est une classe `dark` posee sur `<html>` depuis
    `localStorage` par le script de `layouts/guest.blade.php`. Les deux
    divergent des que quelqu'un choisit un theme different de celui de son OS,
    et la mesure l'a montre : widget clair sur page sombre.

    D'ou le petit script ci-dessous. Il lit la classe DEJA posee — le script du
    layout est dans le `<head>`, donc il s'est execute avant que ce corps ne
    soit analyse — et ecrit `data-theme` AVANT que `api.js` ne lise l'attribut :
    ce dernier est `defer`, il ne s'execute donc qu'une fois le document
    analyse. L'ordre est garanti par la specification, pas par la chance.

    `data-language` suit la locale de l'application : Turnstile accepte `fr` et
    `en`.

    `data-marqueur` n'est pas une option Cloudflare — c'est une ancre de test.
    Les gardes mesurent CE conteneur, jamais la page : une page de 9 Ko contient
    assez de texte pour rendre vraie n'importe quelle assertion (lecon T1697).
--}}
@php
    $turnstileActif = app(\App\Services\Security\TurnstileVerifier::class);
    $turnstileCle = $turnstileActif->siteKey();
@endphp

@if ($turnstileActif->enabled() && $turnstileCle !== null)
    @push('head')
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    @endpush

    <div data-marqueur="turnstile">
        <div class="cf-turnstile"
             data-sitekey="{{ $turnstileCle }}"
             data-theme="auto"
             data-language="{{ app()->getLocale() === 'en' ? 'en' : 'fr' }}"></div>
        <script>
            (function () {
                var defi = document.currentScript.previousElementSibling;
                if (defi) {
                    defi.setAttribute('data-theme', document.documentElement.classList.contains('dark') ? 'dark' : 'light');
                }
            })();
        </script>
        <x-input-error :messages="$errors->get('cf-turnstile-response')" class="mt-1.5" />
    </div>
@endif
