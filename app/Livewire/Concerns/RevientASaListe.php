<?php

namespace App\Livewire\Concerns;

use Livewire\Attributes\On;

/**
 * TASK-1888 — une Card rouverte depuis le MENU revient a sa liste.
 *
 * Les Cards d'une Boucle sont montees UNE fois avec la page
 * (`loops/show.blade.php`) puis seulement masquees ou affichees par
 * `x-show`. Fermer une Card ne touche pas son composant : un formulaire laisse
 * ouvert le restait, et la Card rouverte montrait ce formulaire au lieu de
 * ses elements — surtout sur telephone, ou l'on quitte le panneau sans
 * « Annuler ».
 *
 * `openCard()` emet `loop-card-opened-from-menu` (TASK-1879, idiome de
 * `LoopDossiersCard`). Chaque Card ne reagit qu'a SA cle. Les ouvertures
 * depuis le fil (`bp-open-loop-card`) visent un element precis et ne passent
 * pas par ici.
 */
trait RevientASaListe
{
    /** La cle de la Card dans `config/loop_cards.php`. */
    abstract protected function cleDeCard(): string;

    /** Referme formulaire, detail et confirmation : la Card montre sa liste. */
    abstract protected function revenirALaListe(): void;

    #[On('loop-card-opened-from-menu')]
    public function reouvertureDepuisLeMenu(string $card = ''): void
    {
        if ($card === $this->cleDeCard()) {
            $this->revenirALaListe();
        }
    }
}
