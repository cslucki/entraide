<?php

namespace Tests\Feature;

use App\Jobs\SendNotificationEmail;
use App\Jobs\SendNotificationWebPush;
use App\Services\Dossiers\DossierArticleIndexingDispatcher;
use App\Services\Dossiers\DossierFileIndexingDispatcher;
use App\Services\Knowledge\LoopConversationKnowledgeDispatcher;
use App\Support\Notifications\NotificationEmailDeliverer;
use App\Support\Notifications\NotificationWebPushDeliverer;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * TASK-1830 — micro-correctif MASTER sur TASK-1829 (DONE/MERGED, 1.829,
 * jamais rouverte).
 *
 * Le plan Starter de la Managed Queue Laravel Cloud n'offre qu'UNE SEULE
 * file par environnement (doc officielle, verifiee : « Each managed queue
 * handles one queue name »). `SendNotificationEmail` et
 * `SendNotificationWebPush` portaient chacun leur propre nom de file DEDIE,
 * pose de facon INCONDITIONNELLE — y compris quand leur CONNEXION bascule
 * sur `cloud`. Sur Starter, ces deux noms ne correspondraient a AUCUNE file
 * reelle. Ce fichier verifie qu'ils convergent desormais sur UNE file
 * PARTAGEE, configurable, et SEULEMENT quand le drapeau cloud est pose —
 * jamais avant.
 */
class TASK1830SharedManagedQueueNameTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.notifications_managed_queue_connection' => null]);
        config(['queue.notifications_managed_queue_name' => 'notifications']);
    }

    // =====================================================================
    // A — sans drapeau : les files DEDIEES, a l'identique (non-regression)
    // =====================================================================

    public function test_sans_drapeau_le_job_email_garde_sa_file_dediee(): void
    {
        Queue::fake();

        SendNotificationEmail::dispatch('fake-id');

        Queue::assertPushed(
            SendNotificationEmail::class,
            fn (SendNotificationEmail $job) => $job->queue === NotificationEmailDeliverer::QUEUE
        );
    }

    public function test_sans_drapeau_le_job_push_garde_sa_file_dediee(): void
    {
        Queue::fake();

        SendNotificationWebPush::dispatch('fake-id');

        Queue::assertPushed(
            SendNotificationWebPush::class,
            fn (SendNotificationWebPush $job) => $job->queue === NotificationWebPushDeliverer::QUEUE
        );
    }

    /** Les deux files dediees restent DISTINCTES l'une de l'autre sans drapeau. */
    public function test_sans_drapeau_les_deux_files_dediees_restent_distinctes(): void
    {
        $this->assertNotSame(NotificationEmailDeliverer::QUEUE, NotificationWebPushDeliverer::QUEUE);
    }

    // =====================================================================
    // B — avec le drapeau : UNE SEULE file partagee, celle configuree
    // =====================================================================

    public function test_avec_le_drapeau_les_deux_jobs_convergent_sur_la_meme_file(): void
    {
        config(['queue.notifications_managed_queue_connection' => 'cloud']);
        Queue::fake();

        SendNotificationEmail::dispatch('fake-id');
        SendNotificationWebPush::dispatch('fake-id');

        Queue::assertPushed(
            SendNotificationEmail::class,
            fn (SendNotificationEmail $job) => $job->connection === 'cloud' && $job->queue === 'notifications'
        );
        Queue::assertPushed(
            SendNotificationWebPush::class,
            fn (SendNotificationWebPush $job) => $job->connection === 'cloud' && $job->queue === 'notifications'
        );
    }

    public function test_le_nom_de_file_partagee_est_configurable(): void
    {
        config([
            'queue.notifications_managed_queue_connection' => 'cloud',
            'queue.notifications_managed_queue_name' => 'notifications-custom',
        ]);
        Queue::fake();

        SendNotificationEmail::dispatch('fake-id');
        SendNotificationWebPush::dispatch('fake-id');

        Queue::assertPushed(
            SendNotificationEmail::class,
            fn (SendNotificationEmail $job) => $job->queue === 'notifications-custom'
        );
        Queue::assertPushed(
            SendNotificationWebPush::class,
            fn (SendNotificationWebPush $job) => $job->queue === 'notifications-custom'
        );
    }

    /** Un `dispatch()` reste UN job, drapeau pose ou non — aucune duplication introduite. */
    public function test_un_dispatch_egale_toujours_un_seul_job(): void
    {
        foreach ([null, 'cloud'] as $connexion) {
            config(['queue.notifications_managed_queue_connection' => $connexion]);
            Queue::fake();

            SendNotificationEmail::dispatch('fake-id');

            Queue::assertPushed(SendNotificationEmail::class, 1);
        }
    }

    // =====================================================================
    // C — la connexion `cloud` : un placeholder honnete, pas de parametre invente
    // =====================================================================

    /**
     * Doc officielle Laravel Cloud (laravel.com/cloud/docs/queues),
     * verifiee : aucun nom de variable d'environnement n'est documente pour
     * les identifiants de la connexion `cloud` — la plateforme les injecte
     * elle-meme, hors de `config/queue.php`. Ce test garde l'ABSENCE de ces
     * trois noms FICTIFS dans le fichier source, pour qu'aucun code futur
     * ne les suppose a tort.
     */
    public function test_la_connexion_cloud_ne_suppose_aucune_variable_fictive(): void
    {
        $source = file_get_contents(config_path('queue.php'));

        // Verifie l'APPEL `env(...)`, pas la simple mention : le nom reste
        // legitime dans un commentaire qui explique pourquoi il ne faut PAS
        // le supposer injecte (voir le docblock de la connexion `cloud`).
        foreach (['CLOUD_QUEUE_PREFIX', 'CLOUD_QUEUE_NAME', 'CLOUD_QUEUE_SUFFIX'] as $fictif) {
            $this->assertStringNotContainsString(
                "env('{$fictif}'",
                $source,
                "{$fictif} n'est documente par AUCUNE doc Laravel Cloud : ne jamais le lire via env()."
            );
        }
    }

    /** Le nom de file de la connexion `cloud` EST celui que les jobs utilisent. */
    public function test_la_file_de_la_connexion_cloud_correspond_a_la_file_partagee_des_jobs(): void
    {
        $this->assertSame(
            config('queue.notifications_managed_queue_name'),
            config('queue.connections.cloud.queue'),
            'la Managed Queue devra etre creee sous ce MEME nom au tableau de bord'
        );
    }

    // =====================================================================
    // D — isolation : les files RAG/connaissances deja planifiees n'ont pas bouge
    // =====================================================================

    public function test_la_file_partagee_des_notifications_n_est_jamais_drainee_par_l_allowlist_rag(): void
    {
        $evenement = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'queue:work'));

        $this->assertNotNull($evenement, 'le drainage planifie des files RAG doit exister (T1511/T1539)');

        $commande = (string) $evenement->command;

        foreach ([
            DossierFileIndexingDispatcher::DEDICATED_QUEUE,
            DossierArticleIndexingDispatcher::DEDICATED_QUEUE,
            LoopConversationKnowledgeDispatcher::DEDICATED_QUEUE,
        ] as $file) {
            $this->assertStringContainsString($file, $commande, "[{$file}] doit rester dans l'allowlist RAG");
        }

        $this->assertStringNotContainsString(
            config('queue.notifications_managed_queue_name'),
            $commande,
            'la file PARTAGEE des notifications ne doit jamais etre drainee par ce worker planifie : '
            .'elle vit sur la connexion `cloud`, jamais sur `database`'
        );
        $this->assertStringNotContainsString(
            NotificationEmailDeliverer::QUEUE,
            $commande,
            'la file dediee email ne doit pas non plus y figurer : elle est consommee sous `sync` aujourd\'hui'
        );
        $this->assertStringNotContainsString(
            NotificationWebPushDeliverer::QUEUE,
            $commande,
            'idem pour la file dediee push'
        );
    }

    /** `notifications:recover-stale-deliveries` tourne sur UN SEUL serveur (doc Cloud, multi-replicas). */
    public function test_la_recuperation_des_orphelines_tourne_sur_un_seul_serveur(): void
    {
        $evenement = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'notifications:recover-stale-deliveries'));

        $this->assertNotNull($evenement);

        $reflexion = new \ReflectionClass($evenement);
        $propriete = $reflexion->getProperty('onOneServer');
        $propriete->setAccessible(true);

        $this->assertTrue(
            $propriete->getValue($evenement),
            'sans onOneServer(), chaque replica de l\'App cluster relancerait la recuperation en parallele '
            .'(doc Laravel Cloud, section "Scheduled tasks and multiple replicas")'
        );
    }
}
