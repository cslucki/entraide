<?php

use App\Console\Commands\CheckAiBudgets;
use App\Console\Commands\FeedPublishScheduled;
use App\Jobs\ProcessImageVariants;
use App\Services\Dossiers\DossierArticleIndexingDispatcher;
use App\Services\Dossiers\DossierFileIndexingDispatcher;
use App\Services\Knowledge\LoopConversationKnowledgeDispatcher;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('ai:check-budgets', function () {
    $this->call(CheckAiBudgets::class);
})->purpose('Check AI monthly budgets and alert admins if exceeded');

Artisan::command('feed:publish-scheduled', function () {
    $this->call(FeedPublishScheduled::class);
})->purpose('Publish scheduled feed announcements whose date has passed');

Schedule::command('feed:publish-scheduled')->everyMinute();

// TASK-1511 — l'indexation RAG etait dispatchee mais JAMAIS consommee : aucun
// worker ne tournait sur ce banc, et les jobs dormaient indefiniment sur leur
// queue dediee. Un fichier depose restait « non indexe » sans le moindre
// signal — ni erreur, ni echec, ni trace.
//
// Le cron `schedule:run` tourne deja chaque minute : c'est lui qu'on branche,
// plutot qu'un demon a surveiller et a relancer au reboot. `queue:work` natif
// porte tout ce qu'il faut, aucun emballage maison n'est necessaire :
//   --stop-when-empty : le worker sort des que la file est vide (le cas
//                       courant), il ne squatte pas la minute ;
//   --max-time        : borne dure, pour qu'un job pathologique ne laisse
//                       jamais un worker eternel ;
//   withoutOverlapping: deux minutes consecutives ne se marchent pas dessus ;
//   runInBackground   : `schedule:run` rend la main aussitot.
//
// La liste des queues est un LITTERAL, relu en revue, et jamais construit a
// l'execution : c'est ce qui rend l'allowlist verifiable. `default` n'y figure
// pas et ne doit jamais y figurer — elle porte 207 jobs historiques mis en
// quarantaine le 23/08/2026, qui se rapportent et ne se consomment pas.
// `TASK1511SchedulerIndexingTest` lit la ligne effectivement planifiee et
// rougit si l'une de ces deux regles est rompue.
Schedule::command('queue:work', [
    '--queue' => DossierFileIndexingDispatcher::DEDICATED_QUEUE
        .','.DossierArticleIndexingDispatcher::DEDICATED_QUEUE
        // TASK-1539 : la compilation des conversations rejoint l'allowlist.
        // Sans cette ligne, ses jobs seraient dispatches et jamais consommes —
        // exactement le defaut silencieux que T1511 avait corrige pour
        // l'indexation.
        .','.LoopConversationKnowledgeDispatcher::DEDICATED_QUEUE
        // TASK-1873 : la derivation des images, EN DERNIER — l'indexation
        // passe avant. Un job par image, borne a 75 s et a 25 Mpx. Utile
        // seulement si la connexion par defaut est asynchrone : en PROD
        // (`sync`, 21/11/2026) les images exigent un worker dedie (T1874).
        .','.ProcessImageVariants::QUEUE,
    // Sans valeur, et sans cle : `'--stop-when-empty' => true` rendrait
    // `--stop-when-empty='1'`, que Symfony REFUSE (« does not accept a
    // value »). La commande planifiee aurait echoue chaque minute, en
    // silence — mesure faite avant de figer cette ligne.
    '--stop-when-empty',
    '--max-time' => 55,
])->everyMinute()->withoutOverlapping(5)->runInBackground();

// TASK-1539 — BouclePro apprend en travaillant.
//
// Toutes les dix minutes, et non chaque minute : la fenetre d'inactivite qui
// decide qu'une conversation est posee vaut dix minutes par defaut, balayer
// plus souvent ne ferait que relire la meme absence de travail.
//
// Le balayeur ne compile rien : il met en file. Le cout reel se decide en aval,
// ou le deriver compare l'empreinte de la source avant toute depense.
//
// TASK-1832 — derriere `knowledge.derive_due_scheduled_enabled` (FAUX par
// defaut, voir config/knowledge.php) : sous QUEUE_CONNECTION=sync, ce
// balayage dispatche des jobs qui s'executent EN LIGNE dans le Scheduler,
// sans la strategie asynchrone que T1829/T1830 ont validee pour les
// notifications. L'executable manuel (`php artisan knowledge:derive-due`)
// reste disponible quelle que soit cette valeur — seule la ligne PLANIFIEE
// en depend.
if (config('knowledge.derive_due_scheduled_enabled')) {
    Schedule::command('knowledge:derive-due')->everyTenMinutes()->withoutOverlapping(10);
}

// TASK-1808 §6 — l'invitation a evaluer l'aidant tournait ici, planifiee a
// dix minutes apres la cloture (`EntraideInviteToEvaluate`, commande
// supprimee). TASK-1809 §3 (MASTER, « simplification finale ») : « BouclePro
// lutte contre l'infobesite » — un seul email, envoye IMMEDIATEMENT par
// `TransactionController::complete()` a la cloture, plus de balayage
// planifie. `transactions.evaluation_invited_at` garde le MEME role
// (une invitation maximum), poste desormais depuis ce point synchrone.

// TASK-1433 — SW-3 : la retention des visiteurs du Shell Welcome est une promesse.
Schedule::command('guest:purge-expired')->daily();

// TASK-1829 — filet de securite independant de la file : une livraison
// bloquee en `sending` (worker tue, memoire epuisee) n'est jamais reprise par
// aucun rejeu (`prendre()` n'accepte que `pending`). Toutes les 5 minutes,
// largement au-dessus du plus long `$timeout` de job (90 s), pour qu'une
// livraison legitimement en cours ne soit jamais touchee.
//
// TASK-1830 — `onOneServer()` : doc Laravel Cloud, verifiee, « make sure
// you are using Laravel's onOneServer method when scheduling your tasks.
// Otherwise, your tasks will run on every replica ». Sans effet aujourd'hui
// (1 seule instance, PROD mesuree T1778) mais sans lui, le jour ou l'App
// cluster passe a plusieurs replicas, CHAQUE replica relancerait la
// recuperation en parallele — inoffensif pour la donnee (chaque ligne est
// reverifiee sous verrou dans sa propre transaction) mais redondant.
// `withoutOverlapping` seul ne couvre que les executions successives sur
// LA MEME instance, pas les executions simultanees entre instances.
Schedule::command('notifications:recover-stale-deliveries')->everyFiveMinutes()->withoutOverlapping(5)->onOneServer();

// TASK-1886 — les messages vocaux sont promis pour cinq jours. La route gardee
// les refuse des l'echeance ; ce balayage supprime ensuite leurs FICHIERS.
// Quinze minutes : un fichier echu ne survit jamais plus d'un quart d'heure a
// sa fin promise, et une passe sans travail ne coute qu'une requete indexee
// (`messages.voice_expires_at`). `onOneServer()` : meme raison que la ligne
// ci-dessus (doc Laravel Cloud, replicas). Laravel Cloud ne lit cette liste
// qu'AU DEPLOIEMENT (runbook T1830) : le Scheduler est actif en PROD (mesure
// CLI 22/11), un redeploiement suffit.
Schedule::command('messages:purge-expired-voices')->everyFifteenMinutes()->withoutOverlapping(10)->onOneServer();
