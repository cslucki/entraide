<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\GuestShell\GuestClaimService;
use Illuminate\Auth\Events\Verified;

/**
 * TASK-1445 — SW-11 : a la verification de l'email, le visiteur pseudonyme du
 * Shell Welcome de la MEME Organization est rattache au compte (V3 §22).
 *
 * Decouvert automatiquement (app/Listeners). Le cookie `bp_guest` est celui
 * de la requete de verification (le clic sur le lien, dans le meme navigateur) ;
 * sans cookie, ou avec le cookie d'un autre tenant, rien ne se passe et rien
 * n'est revele. Un echec du claim ne casse JAMAIS une verification (`rescue()`).
 */
class ClaimGuestVisitorOnVerification
{
    public function __construct(private readonly GuestClaimService $claims) {}

    public function handle(Verified $event): void
    {
        $user = $event->user;
        if (! $user instanceof User || $user->organization_id === null) {
            return;
        }

        rescue(fn () => $this->claims->claim($user, request()));
    }
}
