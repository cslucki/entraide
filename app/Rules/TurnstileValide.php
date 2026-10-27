<?php

namespace App\Rules;

use App\Services\Security\TurnstileVerifier;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * TASK-1713 — le jeton Turnstile est verifie par Cloudflare, pas cru sur parole.
 *
 * ## Deux messages, parce que deux gestes
 *
 * Un captcha refuse et une verification indisponible demandent a l'humain deux
 * choses differentes : rejouer le defi, ou renvoyer le formulaire plus tard.
 * Un message unique (« captcha invalide ») enverrait quelqu'un rejouer un defi
 * qui ne pouvait pas aboutir, et laisserait croire a une faute de sa part
 * pendant une panne de tiers. `TurnstileVerifier::verify()` rend donc des
 * MOTIFS, pas un booleen, et ce sont eux qui departagent.
 *
 * ## Pourquoi pas de regle implicite
 *
 * Cette regle ne tourne que sur un jeton PRESENT et non vide : le controleur
 * attache `required` a cote quand le captcha est actif, et `required` est
 * implicite. Un jeton absent est donc refuse sans aucun appel reseau — ce qui
 * est la bonne economie, et evite d'implementer `ImplicitRule`, contrat
 * deprecie en Laravel 13.
 *
 * Le repli `missing-input-response` du verificateur reste neanmoins couvert
 * ici : il couvre l'appel direct a la regle, hors branchement du controleur.
 */
class TurnstileValide implements ValidationRule
{
    /**
     * Les motifs qui signalent une PANNE de verification, et non un refus.
     *
     * `internal-error` est le code de Cloudflare pour « le defi a echoue de
     * notre cote » : il appartient a cette famille, pas a celle du refus.
     */
    private const MOTIFS_INDISPONIBLE = [
        'verification-unavailable',
        'missing-secret',
        'invalid-input-secret',
        'missing-input-secret',
        'internal-error',
    ];

    public function __construct(private ?TurnstileVerifier $verificateur = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $verificateur = $this->verificateur ?? app(TurnstileVerifier::class);

        if (! $verificateur->enabled()) {
            return;
        }

        $motifs = $verificateur->verify(is_string($value) ? $value : null);

        if ($motifs === []) {
            return;
        }

        if (array_intersect($motifs, self::MOTIFS_INDISPONIBLE) !== []) {
            $fail('validation.turnstile_indisponible')->translate();

            return;
        }

        $fail('validation.turnstile_invalide')->translate();
    }
}
