<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;

/**
 * TASK-1883 (demande Cyril, 22/11) — desinscription de la newsletter
 * hebdomadaire (activee par defaut, arbitrage n°1).
 *
 * Lien SIGNE, sans connexion : il part dans chaque newsletter. Ouvrir le lien
 * n'enregistre rien (les antivirus de messagerie ouvrent les liens) : la page
 * demande une confirmation, envoyee en POST sur la meme URL signee.
 */
class NewsletterSubscriptionController extends Controller
{
    public static function urlDesinscription(User $user): string
    {
        return URL::signedRoute('newsletter.unsubscribe', ['compte' => $user->id]);
    }

    public function show(string $compte): View
    {
        $compte = $this->compte($compte);

        return view('newsletter.unsubscribe', [
            'compte' => $compte,
            'fait' => $compte->newsletter_unsubscribed_at !== null,
        ]);
    }

    public function destroy(string $compte): View
    {
        $compte = $this->compte($compte);

        if ($compte->newsletter_unsubscribed_at === null) {
            $compte->forceFill(['newsletter_unsubscribed_at' => now()])->save();
        }

        return view('newsletter.unsubscribe', ['compte' => $compte, 'fait' => true]);
    }

    /** Case de la page des preferences (membre connecte). */
    public function update(Request $request): RedirectResponse
    {
        $abonne = $request->boolean('newsletter');

        $request->user()->forceFill(['newsletter_unsubscribed_at' => $abonne ? null : now()])->save();

        return back()->with('success', $abonne ? __('newsletter.subscribed') : __('newsletter.unsubscribed'));
    }

    private function compte(string $id): User
    {
        // Parametre `{compte}` et non `{user}` : `{user}` a une liaison explicite
        // (slug resolu DANS l'Organization courante, AppServiceProvider), vide
        // ici puisque ce lien s'ouvre hors de toute session.
        return User::query()->findOrFail($id);
    }
}
