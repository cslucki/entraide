<?php

/**
 * TASK-1848 — declencheur de messages REELS (comptes de test uniquement),
 * par les MEMES chemins que `LoopChat::sendMessage()` /
 * `MessageThread::sendMessage()` (verifies ligne a ligne dans
 * `app/Services/LoopMessageService.php` et `app/Livewire/MessageThread.php`
 * avant d'etre reproduits ici) — jamais une insertion brute.
 *
 * EXCLUSION DELIBEREE, pour LES DEUX configurations A et B (comparaison
 * honnete) : `notifierApresCommit()` (email) n'est PAS appele. `.env` local
 * pose `MAIL_MAILER=smtp` — l'appeler aurait tente de vrais envois SMTP
 * sortants vers des adresses `@example.test` fictives, hors perimetre de ce
 * benchmark (qui mesure le delta Reverb/polling, pas la couche email).
 *
 * Usage :
 *   php fire-messages.php --role=loop --count=20 --intervalMs=500 --out=sent-loop.jsonl
 *   php fire-messages.php --role=direct --pairs=0,1,2 --count=5 --intervalMs=1000 --out=sent-direct.jsonl
 *   php fire-messages.php --role=entraide --pairs=0,1,2 --count=5 --intervalMs=1000 --out=sent-entraide.jsonl
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Loop;
use App\Models\Message;
use App\Models\Transaction;
use App\Models\User;
use App\Services\LoopMessageService;
use App\Support\Messaging\ConversationBroadcaster;
use App\Support\Messaging\UnreadCountBroadcaster;
use Illuminate\Support\Facades\DB;

$opts = ['role' => 'loop', 'count' => 10, 'intervalMs' => 1000, 'pairs' => '0', 'out' => 'sent.jsonl', 'fixtures' => null];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-zA-Z]+)=(.*)$/', $arg, $m)) {
        $opts[$m[1]] = $m[2];
    }
}

$fixturesPath = $opts['fixtures'] ?? (getenv('TASK1848_FIXTURES_PATH') ?: __DIR__.'/../../storage/app/benchmarks/task-1848-fixtures.json');
$fixtures = json_decode(file_get_contents($fixturesPath), true, flags: JSON_THROW_ON_ERROR);

app()->instance('current_organization', \App\Models\Organization::find($fixtures['organization_id']));

$events = [];
function record(array &$events, string $type, array $extra = []): void
{
    $events[] = array_merge(['t' => round(microtime(true) * 1000), 'type' => $type], $extra);
}

$count = (int) $opts['count'];
$intervalUs = (int) ((float) $opts['intervalMs'] * 1000);

if ($opts['role'] === 'loop') {
    $loop = Loop::find($fixtures['loop_id']);
    $sender = User::find($fixtures['users'][0]['id']);
    $service = app(LoopMessageService::class);

    for ($i = 0; $i < $count; $i++) {
        $msg = $service->sendUserMessage($loop, $sender, "Benchmark TASK-1848 — message loop #{$i}");
        record($events, 'sent', ['channel' => "loop.{$loop->id}", 'seq' => $i, 'message_id' => $msg->id]);
        if ($i < $count - 1) {
            usleep($intervalUs);
        }
    }
} elseif (in_array($opts['role'], ['direct', 'entraide'], true)) {
    $pairIndexes = array_map('intval', explode(',', (string) $opts['pairs']));
    $isDirectRole = $opts['role'] === 'direct';

    for ($i = 0; $i < $count; $i++) {
        foreach ($pairIndexes as $pairIndex) {
            $pair = $fixtures['pairs'][$pairIndex] ?? null;
            if ($pair === null) {
                continue;
            }

            $transactionId = $isDirectRole ? $pair['direct_transaction_id'] : $pair['entraide_transaction_id'];
            $buyer = User::find($fixtures['users'][$pair['buyer_index']]['id']);

            $msg = DB::transaction(function () use ($transactionId, $buyer, $i, $pairIndex) {
                $transaction = Transaction::whereKey($transactionId)->lockForUpdate()->firstOrFail();

                $msg = Message::create([
                    'transaction_id' => $transaction->id,
                    'sender_id' => $buyer->id,
                    'body' => "Benchmark TASK-1848 — message paire {$pairIndex} #{$i}",
                    'type' => 'user',
                    'organization_id' => $transaction->organization_id,
                ]);

                $transaction->touch();

                ConversationBroadcaster::diffuserApresCommit($msg);
                UnreadCountBroadcaster::diffuserApresCreationMessage($msg, $transaction);

                return $msg;
            });

            record($events, 'sent', ['channel' => "conversation.{$transactionId}", 'seq' => $i, 'pair' => $pairIndex, 'message_id' => $msg->id]);
        }
        if ($i < $count - 1) {
            usleep($intervalUs);
        }
    }
} else {
    fwrite(STDERR, "TASK-1848: --role doit etre loop|direct|entraide\n");
    exit(1);
}

file_put_contents($opts['out'], implode("\n", array_map('json_encode', $events))."\n");
fwrite(STDOUT, '[fire-messages] '.count($events)." messages envoyes, log: {$opts['out']}\n");
