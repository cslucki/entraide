{{--
    Card « Membres » du workspace.

    Une coquille : tout ce qui touche aux personnes vit dans le composant
    Livewire. Le trombinoscope y est aussi, et non a cote — sinon ajouter
    quelqu'un ne se voit qu'au rechargement suivant.

    Le registre des Cards designe cette vue ; elle designe le composant.
--}}
@livewire('loop-members-card', ['loop' => $currentLoop], key('members-card-'.$currentLoop->id))
