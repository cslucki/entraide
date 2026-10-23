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
@component('mail::message')
{!! $html !!}
@endcomponent
