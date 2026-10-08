<?php

namespace App\Models\Scopes;

use App\Support\Ai\AiCorrelation;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class BelongsToOrganizationScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $organization = $this->resolveOrganization();

        if ($organization) {
            $builder->where($model->getTable().'.organization_id', $organization->id);

            return;
        }

        // TASK-1670 — le refus reste IDENTIQUE, il devient seulement ATTRIBUABLE.
        //
        // Ce qui ne change pas : `whereRaw('0 = 1')`. Sans contexte tenant, une
        // requete sur un modele scope ne rend RIEN. Aucun repli vers une
        // Organization par defaut, aucun liage automatique, aucun
        // `withoutGlobalScope` : le fail-closed est la garde anti-fuite
        // cross-tenant, et cette TASK ne l'effleure pas.
        //
        // CE QUI CHANGE, et pourquoi.
        //
        // 1. LE MESSAGE ETAIT FAUX. Il disait « data inaccessible until a
        //    Default Organization exists », donc il accusait la Default
        //    Organization. Or ce scope ne consulte QUE `current_organization`
        //    (via `CurrentOrganization::get()`) et n'appelle JAMAIS
        //    `DefaultOrganizationResolver`. Creer une Organization par defaut
        //    n'aurait rien resolu : l'investigation partait sur une fausse
        //    piste. Le message nomme desormais la seule cause reelle —
        //    l'absence de contexte tenant courant.
        //
        // 2. LE WARNING N'AVAIT AUCUN CONTEXTE. Il etait emis sans le moindre
        //    argument : des centaines d'occurrences strictement identiques,
        //    dont aucune n'etait imputable a un appelant. On ne savait ni quel
        //    modele, ni depuis quelle route, ni meme si l'on etait en HTTP ou
        //    en console. Le contexte ci-dessous ne coute rien : `$model` est
        //    deja un parametre de `apply()`, et le reste est de la lecture
        //    d'etat deja etabli.
        Log::warning(
            'BelongsToOrganizationScope: no current Organization context; query denied fail-closed.',
            $this->attributionContext($model)
        );

        $builder->whereRaw('0 = 1');
    }

    private function resolveOrganization(): mixed
    {
        return CurrentOrganization::get();
    }

    /**
     * Le contexte d'attribution du refus.
     *
     * REGLE DE CONFIDENTIALITE, et elle est stricte. On ne journalise QUE des
     * identifiants techniques et des coordonnees d'appel : jamais un payload,
     * jamais un contenu metier, jamais une adresse e-mail, jamais un secret.
     * En particulier la CHAINE DE REQUETE est volontairement exclue de `uri`
     * (`$request->path()`, pas `fullUrl()`) : un filtre d'ecran peut y porter
     * un e-mail ou un nom, et un log n'est pas l'endroit pour cela. `user_id`
     * est un identifiant opaque, pas une identite.
     *
     * REGLE D'INNOCUITE. Cette methode ne doit RIEN declencher. Journaliser un
     * refus ne peut pas, par effet de bord, authentifier un visiteur, ouvrir
     * une session, demarrer une correlation ni emettre une requete SQL — sans
     * quoi l'observabilite changerait le comportement qu'elle observe. D'ou
     * `Auth::hasUser()` et `AiCorrelation::peek()` plus bas.
     *
     * @return array<string, mixed>
     */
    private function attributionContext(Model $model): array
    {
        $http = $this->isHttpExecution();

        $context = [
            'model' => $model::class,
            'table' => $model->getTable(),
            'execution_context' => $http ? 'http' : 'console',
        ];

        $context += $http ? $this->httpContext() : $this->consoleContext();

        // L'identite de l'appelant, SANS JAMAIS LA RESOUDRE.
        //
        // `auth()->id()` aurait semble le geste naturel. Il est ecarte a
        // dessein : sur le garde de session, il RESOUT l'utilisateur — lecture
        // de session puis `retrieveById()`, donc une requete SQL — et il le
        // ferait ici au milieu de la construction d'une AUTRE requete. Un log
        // se mettrait alors a authentifier, c'est-a-dire a modifier le
        // deroulement qu'il est cense decrire.
        //
        // `hasUser()` ne demande que « un utilisateur est-il DEJA resolu ? » :
        // aucune session touchee, aucun SQL. En pratique la pile `web` l'a
        // resolu tot (`EnsureUserIsNotBanned`), donc l'identifiant est presque
        // toujours la quand elle existe ; et quand elle n'est pas encore la,
        // `null` est la reponse HONNETE plutot qu'un effet de bord.
        if (Auth::hasUser()) {
            $context['user_id'] = Auth::id();
        }

        // La correlation EXISTANTE, jamais une nouvelle.
        //
        // `AiCorrelation` est le seul mecanisme de correlation du depot
        // (TASK-1131) : porte par `Context`, scope par requete et par job,
        // propage a travers la queue. Mais il n'est peuple que sur les chemins
        // IA, et `AiCorrelation::id()` en CREE une si elle manque.
        //
        // On lit donc `peek()`, et la clef n'apparait que lorsqu'une operation
        // est reellement en cours. Journaliser ne fabrique pas l'identifiant
        // d'une operation qui n'existe pas : une correlation inventee ici ne
        // relierait ce warning a rien, tout en laissant croire le contraire.
        $correlation = AiCorrelation::peek();

        if ($correlation !== null) {
            $context['correlation_id'] = $correlation;
        }

        return $context;
    }

    /**
     * HTTP ou console — et le SAPI NE SUFFIT PAS a en decider.
     *
     * `runningInConsole()` tranche sur `PHP_SAPI` (`Application::329`). En
     * production il a raison : php-fpm rend `fpm-fcgi` donc HTTP, une commande
     * et un worker de queue rendent `cli` donc console.
     *
     * Mais SOUS PHPUNIT, `PHP_SAPI` vaut `cli` pour tout le processus — y
     * compris pendant un `$this->get('/…')`. S'en tenir au SAPI aurait donc
     * classe CONSOLE la totalite des requetes de test, et la branche HTTP
     * ci-dessous — celle qui porte la route et l'URI, c'est-a-dire tout
     * l'interet de cette TASK — n'aurait ete atteinte par AUCUN test. Du code
     * livre, teste vert, et jamais execute sur le chemin qu'il pretend decrire.
     *
     * On ajoute donc la question qui porte reellement la distinction : une
     * ROUTE a-t-elle ete resolue ? Le routeur la pose avant la pile de
     * middleware, donc elle est la des le liage de modeles de route. Une
     * commande, un job, un test unitaire n'en ont aucune.
     *
     * Table de verite :
     *
     *   prod HTTP (fpm)        SAPI != cli            -> http
     *   prod `artisan serve`   SAPI = cli-server      -> http
     *   prod commande / worker  cli + aucune route    -> console
     *   test `$this->get()`     cli + route resolue   -> http
     *   test unitaire           cli + aucune route    -> console
     */
    private function isHttpExecution(): bool
    {
        if (! app()->runningInConsole()) {
            return true;
        }

        return request()->route() !== null;
    }

    /**
     * Les coordonnees de l'appel HTTP.
     *
     * Chaque lecture est defensive : ce scope s'applique aussi tot dans la
     * pile — le liage de modeles de route tourne AVANT bien des middlewares —
     * et une route peut n'etre pas encore resolue.
     *
     * @return array<string, mixed>
     */
    private function httpContext(): array
    {
        $request = request();

        $route = $request->route();

        return [
            'method' => $request->getMethod(),
            // `path()` et non `fullUrl()` : voir la regle de confidentialite.
            'uri' => '/'.ltrim($request->path(), '/'),
            // Le NOM de route quand il existe, sinon le MOTIF (`services/{service}`).
            // Le motif ne porte aucune valeur : c'est une adresse, pas une donnee.
            'route' => $route?->getName() ?? $route?->uri(),
        ];
    }

    /**
     * Le nom de la commande en cours, quand il est lisible simplement.
     *
     * Le depot n'a AUCUNE infrastructure de capture du contexte console
     * (aucun ecouteur `CommandStarting`, aucune lecture d'`argv`), et le
     * mandat de TASK-1670 interdit d'en creer une. On lit donc `argv`
     * directement, et on s'arrete au premier jeton qui n'est pas une option.
     *
     * Les options sont ECARTEES, pas seulement ignorees : `--password=…` ou
     * `--token=…` n'ont rien a faire dans un log. Seul le nom de commande est
     * retenu.
     *
     * @return array<string, mixed>
     */
    private function consoleContext(): array
    {
        $argv = $_SERVER['argv'] ?? null;

        if (! is_array($argv)) {
            return ['command' => null];
        }

        foreach (array_slice($argv, 1) as $argument) {
            if (is_string($argument) && $argument !== '' && ! str_starts_with($argument, '-')) {
                return ['command' => $argument];
            }
        }

        return ['command' => null];
    }
}
