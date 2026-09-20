<?php

namespace App\Services\OpenSource;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * TASK-1612 — l'instantane du depot public, assaini a la source.
 *
 * Ce service est le SEUL point du produit qui parle a GitHub, et le seul qui
 * connaisse l'owner du depot. Il ne renvoie jamais un morceau de la reponse
 * de l'API : il RECONSTRUIT un tableau a partir de champs nommes un par un.
 * C'est la garantie forte de la regle de presentation posee par MASTER —
 * `login`, `avatar_url`, `html_url`, `owner` ne peuvent pas fuir par un
 * passe-plat oublie, puisqu'aucun passe-plat n'existe.
 *
 * Deux mesures faites sur l'API reelle avant d'ecrire une ligne :
 *
 *  - la racine du depot compte 31 entrees, et l'API n'offre AUCUN moyen
 *    d'obtenir « le dernier commit de chaque entree » en un appel. Un appel
 *    par entree aurait coute 31 requetes sur les 60/h d'une IP anonyme.
 *    Arbitrage MASTER : les 31 entrees sont listees, mais seuls les
 *    DOSSIERS portent un message de commit. Budget ramene a 14 appels.
 *    Un fichier n'affiche jamais un message : il n'en a pas, et en inventer
 *    un serait mentir.
 *
 *  - les messages de commit de ce depot contiennent un corps multi-lignes
 *    avec `Co-Authored-By:` et une URL `Claude-Session:`. Seule la PREMIERE
 *    ligne est conservee — le sujet. Le corps ne traverse pas ce service.
 */
class OpenSourceSnapshotService
{
    private const FRESH_KEY = 'open_source.snapshot.v1';

    private const STALE_KEY = 'open_source.snapshot.stale.v1';

    private const LOCK_KEY = 'open_source.snapshot.refresh.v1';

    /** Longueur max d'un sujet de commit affiche. */
    private const SUBJECT_MAX = 120;

    /**
     * Le depot a repondu qu'il n'etait PLUS public.
     *
     * A distinguer d'un echec reseau : un echec merite le repli date, un
     * depot referme ne le merite pas. Servir sa racine depuis un cache
     * constitue au temps ou il etait ouvert serait une fuite — le seul cas
     * ou l'instantane conserve doit etre detruit plutot que rejoue.
     */
    private bool $repositoryIsClosed = false;

    /**
     * L'instantane servi a l'interface. Ne leve jamais : une panne GitHub
     * produit un instantane `available: false`, pas une exception.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $fresh = Cache::get(self::FRESH_KEY);

        if (is_array($fresh)) {
            return $fresh;
        }

        $lock = Cache::lock(self::LOCK_KEY, (int) config('open_source.cache.lock_seconds', 30));

        /*
         * Le verrou n'est pas attendu : un visiteur qui arrive pendant un
         * rafraichissement est servi par le repli tout de suite. Faire la
         * queue lui ferait regarder un squelette pendant plusieurs secondes
         * pour obtenir exactement la meme donnee.
         */
        if (! $lock->get()) {
            return $this->fallback();
        }

        try {
            $snapshot = $this->build();

            if ($snapshot === null) {
                return $this->fallback();
            }

            Cache::put(self::FRESH_KEY, $snapshot, now()->addMinutes((int) config('open_source.cache.ttl_minutes', 60)));
            Cache::put(self::STALE_KEY, $snapshot, now()->addHours((int) config('open_source.cache.stale_hours', 24)));

            return $snapshot;
        } finally {
            $lock->release();
        }
    }

    /**
     * Dernier instantane connu, sinon l'etat « indisponible ».
     *
     * @return array<string, mixed>
     */
    private function fallback(): array
    {
        if ($this->repositoryIsClosed) {
            Cache::forget(self::STALE_KEY);

            return $this->unavailable();
        }

        $stale = Cache::get(self::STALE_KEY);

        if (is_array($stale)) {
            return ['stale' => true] + $stale;
        }

        return $this->unavailable();
    }

    /**
     * @return array<string, mixed>
     */
    private function unavailable(): array
    {
        return [
            'available' => false,
            'stale' => false,
            'name' => (string) config('open_source.display_name'),
            'badges' => $this->declaredBadges(null),
            'activity' => null,
            'entries' => [],
        ];
    }

    /**
     * @return array<string, mixed>|null  null = rien d'exploitable obtenu
     */
    private function build(): ?array
    {
        $budget = new CallBudget((int) config('open_source.call_budget', 15));

        $repository = $this->fetchRepository($budget);

        if ($repository === null) {
            return null;
        }

        $entries = $this->fetchRootEntries($budget);

        if ($entries === null) {
            return null;
        }

        $entries = $this->withDirectoryCommits($entries, $budget);

        /*
         * Les compteurs sont demandes APRES la racine, et dans cet ordre
         * precis. Le budget ne peut pas tout payer — mesure sur le depot
         * reel : 1 (depot) + 1 (racine) + 11 (dossiers) + 3 (compteurs) =
         * 16, pour un plafond de 15. Quelque chose doit sauter, et
         * l'ordre decide QUOI :
         *
         *   1. les messages de commit de la racine — le coeur de la TASK,
         *      servis en premier, jamais amputes ;
         *   2. les branches, puis les commits : les deux chiffres qui
         *      disent qu'un projet vit ;
         *   3. les tags en dernier — ce depot en compte un, et « 1 tag »
         *      n'apprend rien a personne.
         *
         * Le 16e appel n'est donc pas perdu au hasard : c'est le moins
         * utile qui est sacrifie, et il le restera si la racine grossit.
         */
        $branches = $this->countViaLastPage('branches', [], $budget);
        $commits = $this->countViaLastPage('commits', ['sha' => $this->branch()], $budget);
        $tags = $this->countViaLastPage('tags', [], $budget);

        return [
            'available' => true,
            'stale' => false,
            'name' => (string) config('open_source.display_name'),
            'badges' => $this->declaredBadges($repository['language']),
            'activity' => [
                'default_branch' => $this->branch(),
                'last_activity_at' => $repository['pushed_at'],
                'branches' => $branches,
                'tags' => $tags,
                'commits' => $commits,
            ],
            'entries' => $entries,
        ];
    }

    /**
     * Badges. `public` et `license` sont DECLARES (cf. config/open_source.php),
     * `language` seul vient de l'API.
     *
     * @return array<string, mixed>
     */
    private function declaredBadges(?string $language): array
    {
        return [
            'public' => true,
            'license' => (string) config('open_source.license'),
            'stack' => array_values((array) config('open_source.stack', [])),
            'language' => $language,
        ];
    }

    /**
     * @return array{language: ?string, pushed_at: ?string}|null
     */
    private function fetchRepository(CallBudget $budget): ?array
    {
        $response = $this->get('', [], $budget);

        if ($response === null || ! $response->successful()) {
            return null;
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            return null;
        }

        /*
         * Un depot passe en prive n'a plus rien a montrer publiquement. Le
         * drapeau fait detruire le repli : c'est le seul cas ou l'ancien
         * instantane ne doit pas etre rejoue.
         */
        if (($payload['private'] ?? false) === true) {
            $this->repositoryIsClosed = true;

            return null;
        }

        return [
            'language' => $this->asNullableString($payload['language'] ?? null),
            'pushed_at' => $this->asNullableString($payload['pushed_at'] ?? null),
        ];
    }

    /**
     * La racine, triee comme un explorateur la presente : dossiers d'abord,
     * puis fichiers, chacun par ordre alphabetique insensible a la casse.
     *
     * @return list<array<string, mixed>>|null
     */
    private function fetchRootEntries(CallBudget $budget): ?array
    {
        $response = $this->get('/contents', ['ref' => $this->branch()], $budget);

        if ($response === null || ! $response->successful()) {
            return null;
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            return null;
        }

        $entries = [];

        foreach ($payload as $item) {
            if (! is_array($item)) {
                continue;
            }

            $name = $this->asNullableString($item['name'] ?? null);
            $type = $item['type'] ?? null;

            if ($name === null || ! in_array($type, ['dir', 'file'], true)) {
                continue;
            }

            $entries[] = [
                'name' => $name,
                'type' => $type,
                'commit' => null,
            ];
        }

        if ($entries === []) {
            return null;
        }

        usort($entries, function (array $a, array $b): int {
            if ($a['type'] !== $b['type']) {
                return $a['type'] === 'dir' ? -1 : 1;
            }

            return strcasecmp($a['name'], $b['name']);
        });

        return $entries;
    }

    /**
     * Attache a chaque DOSSIER le sujet et la date de son dernier commit.
     *
     * Les appels partent en parallele (`Http::pool`) : 9 appels sequentiels a
     * ~200 ms auraient fait attendre le premier visiteur pres de deux
     * secondes de plus pour exactement la meme donnee.
     *
     * Un dossier dont l'appel echoue reste liste, sans message. La liste est
     * le produit ; le message de commit est un agrement.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return list<array<string, mixed>>
     */
    private function withDirectoryCommits(array $entries, CallBudget $budget): array
    {
        $directories = [];

        foreach ($entries as $index => $entry) {
            if ($entry['type'] !== 'dir') {
                continue;
            }

            if (! $budget->consume()) {
                break;
            }

            $directories[$index] = $entry['name'];
        }

        if ($directories === []) {
            return $entries;
        }

        $url = $this->baseUrl().'/commits';
        $headers = $this->headers();
        $timeout = (int) config('open_source.timeout_seconds', 8);
        $branch = $this->branch();

        try {
            $responses = Http::pool(function (Pool $pool) use ($directories, $url, $headers, $timeout, $branch) {
                $requests = [];

                foreach ($directories as $index => $name) {
                    $requests[] = $pool
                        ->as((string) $index)
                        ->withHeaders($headers)
                        ->timeout($timeout)
                        ->connectTimeout($timeout)
                        ->get($url, ['sha' => $branch, 'path' => $name, 'per_page' => 1]);
                }

                return $requests;
            });
        } catch (\Throwable $exception) {
            $this->report('pool des commits par dossier', $exception);

            return $entries;
        }

        foreach ($directories as $index => $name) {
            $response = $responses[(string) $index] ?? null;

            if (! $response instanceof Response || ! $response->successful()) {
                continue;
            }

            $entries[$index]['commit'] = $this->firstCommitOf($response);
        }

        return $entries;
    }

    /**
     * Sujet + date du premier commit d'une reponse `/commits`.
     *
     * @return array{subject: string, at: ?string}|null
     */
    private function firstCommitOf(Response $response): ?array
    {
        $payload = $response->json();

        if (! is_array($payload) || ! isset($payload[0]) || ! is_array($payload[0])) {
            return null;
        }

        $commit = $payload[0]['commit'] ?? null;

        if (! is_array($commit)) {
            return null;
        }

        $subject = $this->subjectOf($this->asNullableString($commit['message'] ?? null));

        if ($subject === null) {
            return null;
        }

        return [
            'subject' => $subject,
            'at' => $this->asNullableString(
                $commit['committer']['date'] ?? $commit['author']['date'] ?? null
            ),
        ];
    }

    /**
     * La PREMIERE ligne d'un message de commit, et rien d'autre.
     *
     * Mesure sur le depot reel : les corps portent `Co-Authored-By:` et une
     * URL `Claude-Session:`. Couper au premier saut de ligne n'est donc pas
     * une coquetterie de mise en page.
     */
    private function subjectOf(?string $message): ?string
    {
        if ($message === null) {
            return null;
        }

        $subject = trim((string) strtok($message, "\n"));
        $subject = trim(preg_replace('/\s+/u', ' ', $subject) ?? '');

        if ($subject === '') {
            return null;
        }

        if (mb_strlen($subject) > self::SUBJECT_MAX) {
            $subject = rtrim(mb_substr($subject, 0, self::SUBJECT_MAX)).'…';
        }

        return $subject;
    }

    /**
     * Compte un collection paginee sans la parcourir : GitHub place le
     * numero de la DERNIERE page dans l'en-tete `Link` quand on demande une
     * page d'un seul element. Un appel, quel que soit le volume.
     *
     * Sans en-tete `Link`, il n'y a qu'une page : on compte ce qui revient
     * (c'est le cas des tags de ce depot, qui sont a zero).
     *
     * @param  array<string, mixed>  $query
     */
    private function countViaLastPage(string $collection, array $query, CallBudget $budget): ?int
    {
        $response = $this->get('/'.$collection, $query + ['per_page' => 1], $budget);

        if ($response === null || ! $response->successful()) {
            return null;
        }

        $link = (string) $response->header('Link');

        if ($link !== '' && preg_match('/[?&]page=(\d+)>;\s*rel="last"/', $link, $matches) === 1) {
            return (int) $matches[1];
        }

        $payload = $response->json();

        return is_array($payload) ? count($payload) : null;
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function get(string $path, array $query, CallBudget $budget): ?Response
    {
        if (! $budget->consume()) {
            return null;
        }

        $timeout = (int) config('open_source.timeout_seconds', 8);

        try {
            return Http::withHeaders($this->headers())
                ->timeout($timeout)
                ->connectTimeout($timeout)
                ->get($this->baseUrl().$path, $query);
        } catch (\Throwable $exception) {
            $this->report('GET '.$path, $exception);

            return null;
        }
    }

    private function baseUrl(): string
    {
        return sprintf(
            'https://api.github.com/repos/%s/%s',
            (string) config('open_source.repository.owner'),
            (string) config('open_source.repository.name'),
        );
    }

    private function branch(): string
    {
        return (string) config('open_source.repository.branch', 'main');
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        $headers = [
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
            'User-Agent' => 'BoucleProBot/1.0',
        ];

        $token = config('open_source.token');

        if (is_string($token) && $token !== '') {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        return $headers;
    }

    private function report(string $context, \Throwable $exception): void
    {
        Log::warning('[open-source] '.$context.' a echoue', [
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);
    }

    private function asNullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
