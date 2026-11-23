{{--
    TASK-1694 bug P — un gabarit administrable part desormais HABILLE.

    Ce fichier etait, en entier, `{!! $html !!}`. Mesure du 23/10 sur le meme
    email : **261 octets de HTML nu** quand le gabarit vient de la base, contre
    **10 923 octets** de mise en page quand c'est le repli Blade qui part — ni
    `<style>`, ni structure en tableau, donc aucune lisibilite dans un client
    mail.

    Ce n'etait pas theorique : deux Organizations avaient des lignes ACTIVES, et
    leurs emails partaient deja nus.

    C'est la regression du 01/10 citee par TASK-1681 (« un gabarit seme en HTML
    brut qui prenait le pas sur le repli Blade style »), vue par son autre bout :
    la cause n'etait pas le gabarit, c'etait l'absence d'habillage autour.

    `mail::message` apporte l'en-tete, le pied et le theme ; l'habillage est le
    MEME que celui des replis Blade, de sorte qu'activer un gabarit ne change
    plus l'apparence, seulement le texte. Les appelants passent par
    `->markdown()` : c'est ce chemin, et pas `->view()`, qui declenche
    l'incorporation des styles. Le HTML brut y survit — verifie,
    `Illuminate\Mail\Markdown::parse()` le laisse intact.
--}}
{{-- TASK-1891 (Cyril, 23/11) — la newsletter parle au nom de l'organisation :
     en-tete et pied portent SON nom (`$marque`), pas la marque de la
     plateforme. Sans `$marque`, habillage standard, inchange pour les autres
     courriels systeme. --}}
@isset($marque)
<x-mail::layout>
<x-slot:header>
<x-mail::header :url="$marqueUrl ?? config('app.url')">
{{ $marque }}
</x-mail::header>
</x-slot:header>

{!! $html !!}

<x-slot:footer>
<x-mail::footer>
© {{ date('Y') }} {{ $marque }}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
@else
@component('mail::message')
{!! $html !!}
@endcomponent
@endisset
