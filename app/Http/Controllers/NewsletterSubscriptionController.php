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

        return $this->ecran($compte, $compte->newsletter_unsubscribed_at !== null ? 'desinscrit' : 'question');
    }

    public function destroy(string $compte): View
    {
        $compte = $this->compte($compte);

        if ($compte->newsletter_unsubscribed_at === null) {
            $compte->forceFill(['newsletter_unsubscribed_at' => now()])->save();
        }

        return $this->ecran($compte, 'desinscrit');
    }

    /** Annuler un clic malheureux, depuis la meme page, sans connexion. */
    public function resubscribe(string $compte): View
    {
        $compte = $this->compte($compte);
        $compte->forceFill(['newsletter_unsubscribed_at' => null])->save();

        return $this->ecran($compte, 'reinscrit');
    }

    /**
     * La page porte l'Organization du membre (nom, logo, couleurs de son theme)
     * et parle sa langue : c'est SA newsletter qu'il quitte.
     */
    private function ecran(User $compte, string $etat): View
    {
        if (in_array($compte->preferred_locale, ['fr', 'en'], true)) {
            app()->setLocale($compte->preferred_locale);
        }

        $org = $compte->organization()->withoutGlobalScopes()->first();
        $themes = bp_themes();
        $tokens = $themes['themes'][$org ? bp_organization_theme_key($org) : $themes['default']]['tokens']
            ?? $themes['themes'][$themes['default']]['tokens'];

        return view('newsletter.unsubscribe', [
            'compte' => $compte,
            'etat' => $etat,
            'organisation' => $org,
            'tokens' => $tokens,
            'accueil' => $org && ! $org->is_default ? route('organization.home', ['organization' => $org->slug]) : url('/'),
            'urlConfirmer' => URL::signedRoute('newsletter.unsubscribe.confirm', ['compte' => $compte->id]),
            'urlAnnuler' => URL::signedRoute('newsletter.unsubscribe.undo', ['compte' => $compte->id]),
        ]);
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
