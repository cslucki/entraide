<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Intervention\Image\Laravel\ServiceProvider as InterventionImageServiceProvider;

/**
 * Laravel 13.19+ embarque son PROPRE composant `illuminate/image`
 * (`Illuminate\Image\ImageServiceProvider`), lie au MEME nom de service
 * `'image'` qu'`intervention/image-laravel`. Son enregistrement, qui tourne
 * apres celui d'Intervention, ECRASE la liaison : `app('image')` rend alors
 * `Illuminate\Image\ImageManager`, dont l'API ne connait pas `decode()` —
 * toute la chaine d'upload d'images (`CreateFeedPost`, `MessageThread`,
 * `LoopChat`, `EditFeedPost`, `GenerateServiceThumbnail`,
 * `ServiceController`, `ProfileController`) leve alors une
 * `Error: Call to undefined method Illuminate\Image\Drivers\GdDriver::decode()`.
 *
 * `register()` tourne pour TOUS les fournisseurs avant que `boot()` tourne
 * pour aucun : reappeler ICI le `register()` d'Intervention, en dernier,
 * reimpose sa liaison sans dupliquer sa logique de construction (pilotes,
 * orientation automatique, animation, couleur de fond) — qui resterait
 * sinon a synchroniser a la main a chaque mise a jour du paquet.
 */
class InterventionImageBindingProvider extends ServiceProvider
{
    public function boot(): void
    {
        // `Illuminate\Image\ImageServiceProvider` implemente
        // `DeferrableProvider` : tant que `'image'` reste dans
        // `Application::$deferredServices`, TOUT appel a `app('image')`
        // — ou qu'un autre fournisseur, le Facade `Image`, ou le framework
        // lui-meme declenche ailleurs — recharge ce fournisseur et REECRASE
        // n'importe quelle liaison posee entre-temps, peu importe quand.
        // Resoudre 'image' une premiere fois ICI consomme ce drapeau
        // differe (Laravel le retire de la liste une fois charge) ; la
        // reliaison qui suit devient alors definitive.
        $this->app->make('image');

        (new InterventionImageServiceProvider($this->app))->register();
    }
}
