<?php

namespace App\Support\Integrity;

/**
 * TASK-1632 — les quatre statuts d'un controle d'integrite.
 *
 * Quatre, et pas un score. Un « 87 % d'integrite » ne dit a personne s'il
 * faut agir ce soir ; « 1 action requise » si. Le mandat le demandait
 * explicitement, et c'est aussi ce qui permet au resume de tenir en une
 * ligne.
 *
 * L'ordre des cas est celui de la GRAVITE : `Ok` d'abord, `ActionRequired`
 * en dernier. `worst()` s'en sert pour resumer une page entiere sans que
 * chaque appelant reecrive la comparaison.
 */
enum IntegrityStatus: string
{
    /** References coherentes, ou garanties par une cle etrangere. */
    case Ok = 'ok';

    /** Etat normal et explique : global, ou historique volontairement conserve. */
    case Information = 'information';

    /**
     * Techniquement valide, semantiquement suspect : un parent en corbeille,
     * du legacy a rattacher. On le montre ; on ne le corrige pas d'ici.
     */
    case Watch = 'watch';

    /** Une reference pointe dans le vide la ou cela ne devrait pas arriver. */
    case ActionRequired = 'action_required';

    public function labelKey(): string
    {
        return 'admin.integrity.status_'.$this->value;
    }

    /** Du plus benin au plus grave — l'ordre de declaration. */
    private function severity(): int
    {
        return match ($this) {
            self::Ok => 0,
            self::Information => 1,
            self::Watch => 2,
            self::ActionRequired => 3,
        };
    }

    /**
     * Le statut le plus grave d'un ensemble — celui qui resume la page.
     *
     * @param  iterable<IntegrityStatus>  $statuses
     */
    public static function worst(iterable $statuses): self
    {
        $worst = self::Ok;

        foreach ($statuses as $status) {
            if ($status->severity() > $worst->severity()) {
                $worst = $status;
            }
        }

        return $worst;
    }
}
