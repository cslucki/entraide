<?php

use App\Console\Commands\CheckAiBudgets;
use App\Services\Dossiers\DossierArticleIndexingDispatcher;
use App\Services\Dossiers\DossierFileIndexingDispatcher;
use App\Console\Commands\FeedPublishScheduled;
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
    '--queue' => DossierFileIndexingDispatcher::DEDICATED_QUEUE.','.DossierArticleIndexingDispatcher::DEDICATED_QUEUE,
    // Sans valeur, et sans cle : `'--stop-when-empty' => true` rendrait
    // `--stop-when-empty='1'`, que Symfony REFUSE (« does not accept a
    // value »). La commande planifiee aurait echoue chaque minute, en
    // silence — mesure faite avant de figer cette ligne.
    '--stop-when-empty',
    '--max-time' => 55,
])->everyMinute()->withoutOverlapping(5)->runInBackground();

// TASK-1433 — SW-3 : la retention des visiteurs du Shell Welcome est une promesse.
Schedule::command('guest:purge-expired')->daily();
