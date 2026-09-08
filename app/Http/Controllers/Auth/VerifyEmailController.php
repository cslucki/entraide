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
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->intended(route('dashboard', absolute: false).'?verified=1');
        }

        if ($request->user()->markEmailAsVerified()) {
            event(new Verified($request->user()));
        }

        // TASK-1453 (V3 §11, MASTER Q80) : la reprise du parcours Workshop — reference structuree relue,
        // revalidee pour CE User et ce tenant, route interne reconstruite, cle consommee. Sinon repli normal.
        if ($resume = rescue(fn () => app(WorkshopResumption::class)->consume($request->user()))) {
            return redirect()->to($resume.'?verified=1');
        }

        return redirect()->intended(route('dashboard', absolute: false).'?verified=1');
    }
}
