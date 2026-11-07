<?php

namespace Tests\Feature;

use App\Models\EmailLog;
use App\Models\Loop;
use App\Models\LoopMember;
use App\Models\LoopNotificationPreference;
use App\Models\MemberNotification;
use App\Models\MemberNotificationDelivery;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopMessageService;
use App\Support\Notifications\NotificationCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TASK-1778 — le BANC du fan-out des notifications collectives.
 *
 * ## Pourquoi ce banc n'est pas un test, et pourquoi il vit quand meme ici
 *
 * Il ne verrouille rien : il MESURE. Le faire tourner en CI ferait payer a
 * chaque PR 15 scenarios dont le plus gros cree 200 membres, 200
 * notifications et 400 livraisons — pour un chiffre que personne ne lit. Il
 * est donc saute par defaut et ne s'execute que sur demande explicite.
 *
 * Il vit dans `tests/` et non dans `_local/` pour une raison : un banc dont
 * le code n'est pas versionne n'est pas reproductible. Le chiffre AVANT et le
 * chiffre APRES doivent sortir du MEME code, sinon la comparaison ne vaut
 * rien.
 *
 * ## ATTENTION — `safe-test.sh` ne donne PAS PostgreSQL
 *
 * `phpunit.xml` force `DB_CONNECTION=sqlite` / `:memory:`. Un banc lance par
 * `safe-test.sh` mesure donc SQLite en memoire, pas la base de production.
 * Les NOMBRES DE REQUETES sont les memes (c'est le meme code qui les emet),
 * mais les DUREES n'ont aucun rapport.
 *
 * Pour mesurer sur PostgreSQL, comme le demande la mission :
 *
 * ```bash
 * MDP=$(php -r 'require "vendor/autoload.php"; $a=require "bootstrap/app.php"; \
 *   $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); \
 *   echo config("database.connections.pgsql.password");')
 * env DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=bouclepro_test \
 *     DB_USERNAME=bouclepro DB_PASSWORD="$MDP" APP_ENV=testing \
 *     BOUCLEPRO_TEST_DB_APPROVED=1 CACHE_STORE=array QUEUE_CONNECTION=sync \
 *     SESSION_DRIVER=array BENCH_FANOUT=1 BENCH_LABEL=apres-pgsql \
 *     php artisan test --filter=TASK1778FanOutBenchmarkTest
 * ```
 *
 * ## Ce qui est mesure et ce qui est simule — la frontiere est explicite
 *
 * | element | banc | PROD |
 * |---|---|---|
 * | SQL | REEL, sur le moteur choisi (voir ci-dessus) | Neon Serverless |
 * | latence par requete | **locale, ~0,1 ms** | **1 aller-retour reseau chacune** |
 * | chiffrement Web Push | REEL (ECDH P-256 + AES-GCM) | identique |
 * | depot HTTP du push | `Http::fake()` | FCM / Mozilla |
 * | transport email | `array` (le Mailer tourne, rien ne sort) | SMTP |
 *
 * Les trois dernieres lignes sont la raison pour laquelle le banc SOUS-ESTIME
 * la PROD : il mesure le CPU et le SQL, pas le reseau. Le rapport en tire une
 * estimation SEPAREE, jamais melangee a la mesure.
 *
 * Les `SAVEPOINT` ne sont pas comptes non plus : `DB::listen` ne les voit pas,
 * et chaque creation de livraison en pose un. C'est un cout reel de plus,
 * absent de toutes les colonnes ci-dessous.
 *
 * ## Les trois configurations
 *
 * Elles ne changent PAS le catalogue — elles changent ce que les membres ont
 * regle, ce qui est le seul levier reel en PROD :
 *
 * - **A** — in-app seul : chaque membre a coupe email ET push pour la Boucle.
 * - **B** — in-app + email : push coupe.
 * - **C** — tout actif, avec un appareil abonne par membre. C'est le DEFAUT
 *   produit depuis l'arbitrage du 07/11, donc le cas reellement vecu.
 *
 * Mesurer A est le coeur du diagnostic : si A coute presque aussi cher que C,
 * c'est que le pipeline paie les canaux qu'il va refuser.
 */
class TASK1778FanOutBenchmarkTest extends TestCase
{
    use RefreshDatabase;

    /** Les tailles demandees par MASTER. 100 = cible POC OFSH, 200 = plafond Organization. */
    private const TAILLES = [5, 20, 50, 100, 200];

    /** Aller-retour reseau suppose vers Neon, en millisecondes. ESTIMATION, pas mesure. */
    private const RTT_NEON_MS = 1.5;

    /** @var list<array<string, mixed>> */
    private array $resultats = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('BENCH_FANOUT') !== '1') {
            $this->markTestSkipped('Banc de mesure. Relancer avec BENCH_FANOUT=1.');
        }

        // Le Mailer tourne pour de vrai, mais sur le transport `array` : le
        // rendu du gabarit, l'ecriture d'`EmailLog` et le SQL restent reels,
        // seule la sortie SMTP disparait.
        config()->set('mail.default', 'array');

        Http::fake(['*' => Http::response('', 201)]);

        $this->poserLaPaireVapidDuServeur();
    }

    public function test_banc_du_fan_out_par_taille_et_par_configuration(): void
    {
        foreach ($this->tailles() as $taille) {
            foreach (['A', 'B', 'C'] as $configuration) {
                $this->resultats[] = $this->mesurerUnScenario($taille, $configuration);
            }
        }

        $this->ecrireLeRapport();

        // Le banc n'echoue jamais sur un seuil : il rend un chiffre. La seule
        // assertion est qu'il a bien mesure quelque chose, sinon un banc muet
        // passerait pour un banc vert.
        $this->assertCount(count($this->tailles()) * 3, $this->resultats);
    }

    /**
     * Les tailles du banc, surchargeables pour une passe rapide de mise au
     * point (`BENCH_TAILLES=5,20`). Le banc de reference, lui, tourne sur
     * `self::TAILLES` au complet — c'est lui qui alimente le rapport.
     *
     * @return list<int>
     */
    private function tailles(): array
    {
        $demande = (string) (getenv('BENCH_TAILLES') ?: '');

        if ($demande === '') {
            return self::TAILLES;
        }

        return array_values(array_filter(array_map(
            static fn (string $t): int => (int) trim($t),
            explode(',', $demande),
        ), static fn (int $t): bool => $t > 0));
    }

    /**
     * Un scenario = une Organization neuve, une Boucle, N membres, une demande.
     *
     * Chaque scenario repart d'une Organization VIERGE : reutiliser la
     * precedente ferait grossir les tables au fil du banc et melangerait la
     * taille de la Boucle avec la taille de la base.
     *
     * @return array<string, mixed>
     */
    private function mesurerUnScenario(int $taille, string $configuration): array
    {
        [$boucle, $auteur] = $this->preparerLaBoucle($taille, $configuration);

        /** @var list<array{sql: string, time: float}> $requetes */
        $requetes = [];

        /** @var array<string, int> $origines */
        $origines = [];
        $attribuer = getenv('BENCH_ORIGINES') === '1';

        DB::flushQueryLog();
        DB::listen(function ($evenement) use (&$requetes, &$origines, $attribuer): void {
            $requetes[] = ['sql' => $evenement->sql, 'time' => (float) $evenement->time];

            if ($attribuer) {
                $origine = self::origineApplicative();
                $origines[$origine] = ($origines[$origine] ?? 0) + 1;
            }
        });

        // TASK-1778 (arbitrage MASTER) — compter les TRANSACTIONS imbriquees.
        //
        // `DB::listen` ne voit pas les `SAVEPOINT` : `createSavepoint()` passe
        // par `$pdo->exec()` en direct. Chaque transaction imbriquee emet donc
        // UN aller-retour invisible du compteur de requetes (le commit
        // imbrique, lui, n'emet rien : il decremente un compteur en PHP).
        // Les evenements de transaction, eux, sont emis a TOUS les niveaux.
        $transactions = 0;
        $profondeurMax = 0;
        $profondeur = 0;
        $finDeTransaction = null;

        $ecouteur = app('events');
        $ecouteur->listen(\Illuminate\Database\Events\TransactionBeginning::class,
            function () use (&$transactions, &$profondeur, &$profondeurMax): void {
                $transactions++;
                $profondeur++;
                $profondeurMax = max($profondeurMax, $profondeur);
            });
        $ecouteur->listen(\Illuminate\Database\Events\TransactionCommitted::class,
            function () use (&$profondeur): void {
                $profondeur--;
            });

        // `TransactionCommitting` n'est emis QUE pour le commit le plus
        // externe, et JUSTE AVANT `$pdo->commit()`. C'est la seule frontiere
        // exploitable : `TransactionCommitted`, lui, est emis APRES que le
        // gestionnaire de transactions a execute les rappels `afterCommit` —
        // donc apres les envois d'email et de push, ce qui faisait mesurer a
        // mon premier instrument la totalite du fan-out comme « dans la
        // transaction ». Elle ne l'est pas : le commit PDO a lieu d'abord.
        $ecouteur->listen(\Illuminate\Database\Events\TransactionCommitting::class,
            function () use (&$finDeTransaction, &$debut): void {
                if ($finDeTransaction === null) {
                    $finDeTransaction = hrtime(true);
                }
            });

        gc_collect_cycles();
        $memoireAvant = memory_get_usage(true);
        $debut = hrtime(true);

        app(LoopMessageService::class)->sendHelpRequestMessage(
            $boucle,
            $auteur,
            'J’ai besoin d’un coup de main pour une relecture.',
            'Relecture d’un dossier',
            'Une relecture critique',
            'Dossier a rendre cette semaine',
            'relecture',
        );

        $dureeMs = (hrtime(true) - $debut) / 1_000_000;
        $memoireApres = memory_get_peak_usage(true);
        $dureeTransactionMs = $finDeTransaction === null ? null : ($finDeTransaction - $debut) / 1_000_000;

        $ecouteur->forget(\Illuminate\Database\Events\TransactionBeginning::class);
        $ecouteur->forget(\Illuminate\Database\Events\TransactionCommitted::class);
        $ecouteur->forget(\Illuminate\Database\Events\TransactionCommitting::class);

        // Couper l'ecoute : sans cela, les requetes de COMPTAGE ci-dessous
        // s'ajouteraient a la mesure et la fausseraient d'autant.
        DB::getEventDispatcher()->forget(\Illuminate\Database\Events\QueryExecuted::class);

        $notifications = MemberNotification::query()
            ->where('organization_id', $boucle->organization_id)
            ->where('notification_key', NotificationCatalogue::LOOP_HELP_REQUEST_CREATED)
            ->count();

        $livraisons = MemberNotificationDelivery::query()
            ->whereIn('notification_id', MemberNotification::query()
                ->where('organization_id', $boucle->organization_id)
                ->select('id'))
            ->get(['channel', 'status']);

        $parStatut = $livraisons
            ->groupBy(fn ($l) => $l->channel.':'.$l->status)
            ->map->count()
            ->sortKeys()
            ->all();

        // Borne a l'Organization du scenario : `EmailLog` n'est pas vide
        // entre deux scenarios du meme banc, et un compteur cumulatif
        // attribuerait a la configuration C les emails de la configuration B.
        $emails = EmailLog::query()
            ->where('organization_id', $boucle->organization_id)
            ->count();

        return [
            'taille' => $taille,
            'configuration' => $configuration,
            'requetes' => count($requetes),
            'requetes_par_destinataire' => $taille > 0 ? round(count($requetes) / $taille, 1) : 0,
            'duree_ms' => round($dureeMs, 1),
            'duree_sql_ms' => round(array_sum(array_column($requetes, 'time')), 1),
            'memoire_mo' => round(($memoireApres - $memoireAvant) / 1048576, 1),
            'notifications' => $notifications,
            'livraisons' => $livraisons->count(),
            'livraisons_par_statut' => $parStatut,
            'emails_journalises' => $emails,
            // Une transaction imbriquee = 1 `SAVEPOINT`, donc 1 aller-retour
            // de plus, absent de la colonne `requetes`.
            'savepoints' => max(0, $transactions - 1),
            'profondeur_max' => $profondeurMax,
            'duree_transaction_ms' => $dureeTransactionMs === null ? null : round($dureeTransactionMs, 1),
            'latence_reseau_estimee_ms' => round((count($requetes) + max(0, $transactions - 1)) * self::RTT_NEON_MS, 0),
            'top_requetes' => $this->top($requetes),
            'origines' => $this->trier($origines),
        ];
    }

    /**
     * Les 8 formes de requete les plus repetees, normalisees.
     *
     * La normalisation efface les valeurs liees pour que 200 `select ... where
     * id = ?` ne comptent pas pour 200 formes distinctes : c'est la REPETITION
     * d'une meme forme qui nomme un N+1.
     *
     * @param  list<array{sql: string, time: float}>  $requetes
     * @return array<string, int>
     */
    private function top(array $requetes): array
    {
        $formes = [];

        foreach ($requetes as $requete) {
            $forme = preg_replace('/\s+/', ' ', $requete['sql']);
            $forme = preg_replace('/in \((?:\s*\?\s*,?)+\)/i', 'in (?*)', (string) $forme);
            $forme = mb_substr((string) $forme, 0, 110);
            $formes[$forme] = ($formes[$forme] ?? 0) + 1;
        }

        arsort($formes);

        // 8 formes suffisent au tableau de synthese ; la micro-sequence A du
        // diagnostic, elle, a besoin du recensement COMPLET — d'ou la
        // surcharge (`BENCH_FORMES=0` rend tout).
        $limite = getenv('BENCH_FORMES') === false ? 8 : (int) getenv('BENCH_FORMES');

        return $limite <= 0 ? $formes : array_slice($formes, 0, $limite, true);
    }

    /**
     * La premiere frame de `app/` qui a declenche la requete.
     *
     * Compter les requetes par FORME dit QUOI est repete ; compter par
     * ORIGINE dit QUI le repete. Les deux recensements sont necessaires :
     * trois call sites differents produisent la meme forme, et c'est en les
     * separant qu'on voit lequel est superflu.
     *
     * Mode optionnel (`BENCH_ORIGINES=1`) : `debug_backtrace` sur chaque
     * requete coute cher et fausserait la mesure de duree du banc de
     * reference.
     */
    private static function origineApplicative(): string
    {
        $pile = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 40);

        foreach ($pile as $frame) {
            $fichier = $frame['file'] ?? '';

            if ($fichier === '' || ! str_contains($fichier, '/app/')) {
                continue;
            }

            return str_replace(base_path().'/', '', $fichier).':'.($frame['line'] ?? 0);
        }

        return 'hors-app';
    }

    /**
     * @param  array<string, int>  $origines
     * @return array<string, int>
     */
    private function trier(array $origines): array
    {
        arsort($origines);

        return $origines;
    }

    /** @return array{0: Loop, 1: User} */
    private function preparerLaBoucle(int $taille, string $configuration): array
    {
        $organization = Organization::factory()->create(['is_active' => true]);
        $auteur = User::factory()->create(['organization_id' => $organization->id]);

        $boucle = Loop::factory()->create([
            'organization_id' => $organization->id,
            'created_by' => $auteur->id,
            'status' => 'active',
        ]);

        LoopMember::create([
            'loop_id' => $boucle->id,
            'user_id' => $auteur->id,
            'organization_id' => $organization->id,
            'role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        for ($i = 0; $i < $taille; $i++) {
            $membre = User::factory()->create([
                'organization_id' => $organization->id,
                'preferred_locale' => 'fr',
            ]);

            LoopMember::create([
                'loop_id' => $boucle->id,
                'user_id' => $membre->id,
                'organization_id' => $organization->id,
                'role' => 'member',
                'status' => 'active',
                'joined_at' => now(),
            ]);

            if ($configuration !== 'C') {
                // A et B posent un reglage EXPLICITE par Boucle. C ne pose
                // rien : il mesure le defaut produit, qui est tout-actif.
                $reglage = new LoopNotificationPreference([
                    'collective_enabled' => true,
                    'email_enabled' => $configuration === 'B',
                    'web_push_enabled' => false,
                ]);
                $reglage->organization_id = (string) $organization->id;
                $reglage->loop_id = (string) $boucle->id;
                $reglage->user_id = (string) $membre->id;
                $reglage->save();
            }

            if ($configuration === 'C') {
                $this->abonnerUnAppareil($membre);
            }
        }

        return [$boucle->fresh(), $auteur];
    }

    /**
     * Un appareil avec des clefs P-256 REELLES.
     *
     * Une valeur bidon ferait lever le chiffrement, le livreur l'attraperait,
     * et le banc mesurerait un chemin d'echec au lieu du chemin reel. Meme
     * raison qu'en T1740.
     */
    private function abonnerUnAppareil(User $membre): void
    {
        $paire = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $detail = openssl_pkey_get_details($paire);
        $publique = "\x04"
            .str_pad($detail['ec']['x'], 32, "\0", STR_PAD_LEFT)
            .str_pad($detail['ec']['y'], 32, "\0", STR_PAD_LEFT);

        app(\App\Services\Push\PushSubscriptionStore::class)->revendiquer(
            $membre,
            'https://fcm.googleapis.com/fcm/send/banc-'.bin2hex(random_bytes(8)),
            self::base64Url($publique),
            self::base64Url(random_bytes(16)),
            'Android',
        );
    }

    /** La paire du SERVEUR est posee UNE fois : elle ne tourne pas entre deux abonnements. */
    private function poserLaPaireVapidDuServeur(): void
    {
        config()->set('webpush.vapid.subject', 'mailto:banc@exemple.test');

        $serveur = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $detail = openssl_pkey_get_details($serveur);

        config()->set('webpush.vapid.public_key', self::base64Url(
            "\x04"
            .str_pad($detail['ec']['x'], 32, "\0", STR_PAD_LEFT)
            .str_pad($detail['ec']['y'], 32, "\0", STR_PAD_LEFT)
        ));
        config()->set('webpush.vapid.private_key', self::base64Url(
            str_pad($detail['ec']['d'], 32, "\0", STR_PAD_LEFT)
        ));
    }

    private static function base64Url(string $binaire): string
    {
        return rtrim(strtr(base64_encode($binaire), '+/', '-_'), '=');
    }

    /** Le rapport part sur la sortie standard ET dans un fichier, pour la comparaison AVANT/APRES. */
    private function ecrireLeRapport(): void
    {
        $etiquette = getenv('BENCH_LABEL') ?: 'sans-etiquette';
        $lignes = ["# Banc fan-out TASK-1778 — {$etiquette}", ''];
        $lignes[] = '| taille | config | requetes | SAVEPOINT | req.+sp / dest. | duree CPU+SQL (ms) | dont SQL (ms) | dont transaction (ms) | prof. max | memoire (Mo) | notifs | livraisons | latence reseau estimee (ms) |';
        $lignes[] = '|---|---|---|---|---|---|---|---|---|---|---|---|---|';

        foreach ($this->resultats as $r) {
            $lignes[] = sprintf(
                '| %d | %s | %d | %d | %s | %s | %s | %s | %d | %s | %d | %d | %s |',
                $r['taille'], $r['configuration'], $r['requetes'], $r['savepoints'],
                $r['taille'] > 0 ? round(($r['requetes'] + $r['savepoints']) / $r['taille'], 1) : 0,
                $r['duree_ms'], $r['duree_sql_ms'], $r['duree_transaction_ms'] ?? '-',
                $r['profondeur_max'], $r['memoire_mo'],
                $r['notifications'], $r['livraisons'], $r['latence_reseau_estimee_ms'],
            );
        }

        $lignes[] = '';
        $lignes[] = '## Statuts de livraison et formes de requete repetees';

        foreach ($this->resultats as $r) {
            $lignes[] = '';
            $lignes[] = sprintf('### %d membres, configuration %s', $r['taille'], $r['configuration']);
            $lignes[] = '';
            $lignes[] = 'Statuts : '.json_encode($r['livraisons_par_statut'], JSON_UNESCAPED_SLASHES);
            $lignes[] = sprintf('EmailLog : %d', $r['emails_journalises']);
            $lignes[] = '';

            foreach ($r['top_requetes'] as $forme => $nombre) {
                $lignes[] = sprintf('- **%d x** `%s`', $nombre, $forme);
            }

            if ($r['origines'] !== []) {
                $lignes[] = '';
                $lignes[] = 'Origines applicatives :';
                $lignes[] = '';

                foreach ($r['origines'] as $origine => $nombre) {
                    $lignes[] = sprintf('- **%d x** `%s`', $nombre, $origine);
                }
            }
        }

        $rapport = implode("\n", $lignes)."\n";
        $chemin = base_path('_local/bench/TASK-1778-'.$etiquette.'.md');

        if (! is_dir(dirname($chemin))) {
            mkdir(dirname($chemin), 0775, true);
        }

        file_put_contents($chemin, $rapport);
        fwrite(STDERR, "\n".$rapport."\nRapport ecrit : {$chemin}\n");
    }
}
