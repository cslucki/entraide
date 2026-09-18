<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Workshops\WorkshopResumption;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;

class VerifyEmailController extends Controller
{
    /**
     * Mark the authenticated user's email address as verified.
     */
    public function __invoke(EmailVerificationRequest $request): RedirectResponse
    {
        if (! $request->user()->hasVerifiedEmail() && $request->user()->markEmailAsVerified()) {
            event(new Verified($request->user()));
        }

        // TASK-1453 (V3 §11, MASTER Q80) : la reprise du parcours Workshop — reference structuree relue,
        // revalidee pour CE User et ce tenant, route interne reconstruite, cle consommee. Sinon repli normal.
        // TASK-1465 (audit OPUS final P1-3, F6) : la reprise est consommee MEME si l'email est deja verifie —
        // un lien rejoue (double clic, verification faite dans un autre navigateur, client mail qui pre-ouvre)
        // ramene toujours le membre la ou son parcours l'attendait ; la cle ne reste jamais en session.
        if ($resume = rescue(fn () => app(WorkshopResumption::class)->consume($request->user()))) {
            return redirect()->to($resume.'?verified=1');
        }

        return redirect()->intended(route('dashboard', absolute: false).'?verified=1');
    }
}
