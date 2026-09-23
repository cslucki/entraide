<?php

namespace App\Services\Ai;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Le catalogue des modeles OpenRouter, et la PREUVE de leur gratuite.
 * (TASK-1617)
 *
 * Lecture seule, plateforme, sans tenant : `GET {base_url}/models` avec la
 * cle PLATEFORME. Ce n'est pas une generation — aucun token n'est produit,
 * aucune Organization n'est facturee, et c'est pour cela que la cle
 * plateforme est legitime ici alors qu'elle ne l'est pas pour un appel
 * tenant (invariant de `ProviderResolver`, inchange).
 *
 * ## La gratuite se PROUVE sur les donnees de tarif
 *
 * Jamais sur le nom. Le suffixe `:free` est informatif et ne fait autorite
 * sur rien : un modele peut le porter et facturer une requete, ou le perdre
 * en restant gratuit. Seuls les champs `pricing` decident.
 *
 * Un modele est VERIFIE GRATUIT si, et seulement si :
 *
 *  - il produit du texte (`output_modalities` contient `text`) et accepte du
 *    texte — un modele d'image ou d'audio n'est pas un candidat, meme a 0 ;
 *  - `prompt`, `completion` ET `request` valent 0 lorsqu'ils sont exposes ;
 *  - TOUS les autres postes applicables a une generation texte valent 0 ;
 *  - aucun champ pertinent n'est manquant, non numerique ou ambigu.
 *
 * Tout le reste est NOT VERIFIED FREE — pas « probablement gratuit ».
 *
 * Les postes qui ne concernent pas notre generation texte (recherche web,
 * image, audio, cache interne) sont IGNORES plutot que exiges a 0 : nous ne
 * les declenchons pas. Mais ils sont ignores par une liste EXPLICITE, jamais
 * par defaut — un poste inconnu compte, et suffit a refuser.
 */
class OpenRouterModelCatalog
{
    /** Le catalogue est relu au plus toutes les 15 minutes, sauf geste explicite. */
    public const CACHE_TTL_SECONDS = 900;

    public const CACHE_KEY = 'ai.openrouter.models_catalog';

    /**
     * Postes de tarif qui ne concernent PAS une generation texte, et qu'on
     * n'exige donc pas a zero — parce qu'on ne les declenche jamais.
     *
     * Liste EXPLICITE et fermee : un poste absent d'ici est considere comme
     * applicable, et doit valoir 0. C'est le sens de « tout autre poste
     * applicable doit etre 0 » — l'inconnu refuse, il n'est pas tolere.
     *
     * @var list<string>
     */
    private const IGNORED_PRICING_FIELDS = [
        'image',
        'audio',
        'web_search',
        'internal_reasoning',
        'input_cache_read',
        'input_cache_write',
        'discount',
    ];

    /**
     * Slugs ROUTEURS : exclus des modeles selectionnables, meme gratuits.
     *
     * `openrouter/free` (« Free Models Router ») choisit LUI-MEME parmi les
     * modeles gratuits a chaque appel. L'affecter a un assistant ferait perdre
     * le controle de ce qui repond reellement : Aperio pourrait etre servi par
     * un modele different a chaque tour, et la trace du ledger nommerait le
     * routeur plutot que le modele employe.
     *
     * Le produit exige des slugs DETERMINISTES — un assistant, un modele
     * nomme. Ce n'est pas une question de tarif : ce routeur est bien gratuit,
     * et il est refuse quand meme.
     *
     * @var list<string>
     */
    private const ROUTER_SLUGS = ['openrouter/free', 'openrouter/auto'];

    /**
     * Postes qui, lorsqu'ils sont exposes, DOIVENT valoir 0.
     *
     * `request` est ici parce qu'un modele peut afficher `prompt` et
     * `completion` a 0 tout en facturant chaque requete : c'est exactement le
     * faux gratuit que cette liste ferme.
     *
     * @var list<string>
     */
    private const REQUIRED_ZERO_FIELDS = ['prompt', 'completion', 'request'];

    /**
     * Le catalogue, depuis le cache ou depuis OpenRouter.
     *
     * @return array{models: array<int, array<string, mixed>>, fetched_at: ?string, ok: bool, error: ?string}
     */
    public function catalogue(bool $forceRefresh = false): array
    {
        if ($forceRefresh) {
            Cache::forget(self::CACHE_KEY);
        }

        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached) && ! $forceRefresh) {
            return $cached;
        }

        $releve = $this->fetch();

        // On ne met en cache QUE les releves reussis : cacher un echec
        // ferait passer une panne de quinze secondes pour quinze minutes
        // d'indisponibilite.
        if ($releve['ok']) {
            Cache::put(self::CACHE_KEY, $releve, self::CACHE_TTL_SECONDS);
        }

        return $releve;
    }

    /**
     * Les modeles VERIFIES GRATUITS, indexes par slug.
     *
     * @return array<string, array<string, mixed>>
     */
    public function verifiedFreeModels(bool $forceRefresh = false): array
    {
        $catalogue = $this->catalogue($forceRefresh);

        if (! $catalogue['ok']) {
            // Fermeture par defaut : un catalogue qu'on n'a pas pu lire ne
            // declare AUCUN modele gratuit. Il ne rend pas le dernier connu.
            return [];
        }

        $libres = [];

        foreach ($catalogue['models'] as $model) {
            $slug = $model['id'] ?? null;

            if (! is_string($slug) || $slug === '' || ! $this->isVerifiedFree($model)) {
                continue;
            }

            // Un routeur est gratuit mais NON DETERMINISTE : il n'est pas un
            // choix de modele, il est l'absence de choix.
            if ($this->isRouter($slug)) {
                continue;
            }

            $libres[$slug] = [
                'slug' => $slug,
                'name' => is_string($model['name'] ?? null) ? $model['name'] : $slug,
                'context_length' => is_numeric($model['context_length'] ?? null)
                    ? (int) $model['context_length']
                    : null,
            ];
        }

        ksort($libres);

        return $libres;
    }

    /** Ce slug est-il, MAINTENANT, verifie gratuit ? */
    public function isSlugVerifiedFree(string $slug, bool $forceRefresh = false): bool
    {
        return array_key_exists($slug, $this->verifiedFreeModels($forceRefresh));
    }

    /**
     * Ce slug EXISTE-t-il au catalogue OpenRouter, quel que soit son tarif ?
     * (TASK-1622 — le mode « Payant approuve » exige un slug CONNU, pas un
     * slug gratuit.)
     *
     * Meme fermeture par defaut que le reste : catalogue illisible = slug
     * inconnu, et un routeur n'est jamais un choix de modele.
     */
    public function isSlugKnown(string $slug, bool $forceRefresh = false): bool
    {
        if ($slug === '' || $this->isRouter($slug)) {
            return false;
        }

        $catalogue = $this->catalogue($forceRefresh);

        if (! $catalogue['ok']) {
            return false;
        }

        foreach ($catalogue['models'] as $model) {
            if (($model['id'] ?? null) === $slug) {
                return true;
            }
        }

        return false;
    }

    // ── La preuve ───────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $model
     */
    public function isVerifiedFree(array $model): bool
    {
        if (! $this->producesText($model)) {
            return false;
        }

        $pricing = $model['pricing'] ?? null;

        if (! is_array($pricing) || $pricing === []) {
            // Pas de tarif publie = pas de preuve. Ce n'est pas « gratuit ».
            return false;
        }

        $vuAuMoinsUnPoste = false;

        foreach ($pricing as $poste => $valeur) {
            if (in_array($poste, self::IGNORED_PRICING_FIELDS, true)) {
                continue;
            }

            // Un poste applicable doit etre LISIBLE et NUL. Manquant,
            // non numerique, ambigu : refus.
            if (! is_numeric($valeur)) {
                return false;
            }

            if ((float) $valeur !== 0.0) {
                return false;
            }

            $vuAuMoinsUnPoste = true;
        }

        if (! $vuAuMoinsUnPoste) {
            return false;
        }

        // `prompt` et `completion` doivent etre PRESENTS : un tarif qui ne les
        // expose pas ne dit rien de ce que coute une generation.
        foreach (['prompt', 'completion'] as $obligatoire) {
            if (! array_key_exists($obligatoire, $pricing)) {
                return false;
            }
        }

        // `request`, lui, n'est exige que s'il est expose — mais alors il doit
        // etre nul, ce que la boucle ci-dessus a deja verifie.
        return true;
    }

    /** Ce slug designe-t-il un ROUTEUR plutot qu'un modele nomme ? */
    public function isRouter(string $slug): bool
    {
        return in_array($slug, self::ROUTER_SLUGS, true);
    }

    /**
     * Le modele produit-il du TEXTE ?
     *
     * OpenRouter decrit les modalites dans `architecture`. Quand elles sont
     * absentes, on ne devine pas : on refuse. Une generation d'image a 0 n'est
     * pas un assistant gratuit, c'est un modele qu'on n'appellera jamais.
     *
     * @param  array<string, mixed>  $model
     */
    private function producesText(array $model): bool
    {
        $architecture = $model['architecture'] ?? null;

        if (! is_array($architecture)) {
            return false;
        }

        $sorties = $architecture['output_modalities'] ?? null;
        $entrees = $architecture['input_modalities'] ?? null;

        if (! is_array($sorties) || ! is_array($entrees)) {
            return false;
        }

        // La SORTIE doit etre du texte, et RIEN d'autre : un modele qui rend
        // aussi de l'image peut facturer ce poste-la.
        if ($sorties !== ['text']) {
            return false;
        }

        return in_array('text', $entrees, true);
    }

    // ── Le releve ───────────────────────────────────────────────────────────

    /**
     * @return array{models: array<int, array<string, mixed>>, fetched_at: ?string, ok: bool, error: ?string}
     */
    private function fetch(): array
    {
        $base = rtrim((string) config('ai.openrouter.base_url', 'https://openrouter.ai/api/v1'), '/');
        $key = (string) config('ai.openrouter.api_key', '');

        $vide = fn (string $erreur): array => [
            'models' => [], 'fetched_at' => null, 'ok' => false, 'error' => $erreur,
        ];

        if ($base === '') {
            return $vide('missing_base_url');
        }

        try {
            $requete = Http::timeout((int) config('ai.openrouter.timeout', 30))
                ->acceptJson();

            // La cle est PLATEFORME et ne sort jamais vers le navigateur.
            // `GET /models` est d'ailleurs public : si aucune cle n'est
            // configuree, le releve reste possible.
            if ($key !== '') {
                $requete = $requete->withToken($key);
            }

            $reponse = $requete->get($base.'/models');

            if (! $reponse->successful()) {
                return $vide('http_'.$reponse->status());
            }

            $data = $reponse->json('data');

            if (! is_array($data)) {
                return $vide('malformed_payload');
            }

            return [
                'models' => array_values(array_filter($data, 'is_array')),
                'fetched_at' => Carbon::now()->toIso8601String(),
                'ok' => true,
                'error' => null,
            ];
        } catch (\Throwable $e) {
            Log::warning('ai.openrouter.catalog_fetch_failed', ['exception' => $e::class]);

            return $vide('unreachable');
        }
    }
}
