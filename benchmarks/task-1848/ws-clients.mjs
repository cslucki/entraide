#!/usr/bin/env node
// TASK-1848 — simulateur de clients WebSocket REELS (laravel-echo +
// pusher-js, les MEMES paquets que le navigateur), comptes de test
// (database/seeders/Task1848BenchmarkSeeder.php) exclusivement.
//
// Repartition des roles (mandat MASTER, section "scenarios obligatoires") :
// chaque client simule, selon son index, consulte une Boucle, une
// conversation directe, un echange d'entraide, ou navigue ailleurs en
// restant abonne aux badges — jamais une connexion anonyme generique.
//
// Usage :
//   node ws-clients.mjs --count=10 --duration=60 --config=A --out=out.jsonl
//
// --config=A : drapeaux OFF, la connexion Echo n'est JAMAIS ouverte (A
//   mesure le polling pur) — ce script sort alors immediatement avec un
//   resume vide, le poids de A est mesure par simulate-http-polling.mjs.
// --config=B : drapeaux ON, connexions Echo REELLES ouvertes et maintenues.

import Pusher from 'pusher-js';
import { writeFileSync } from 'node:fs';
import { login, makeAuthorizer } from './lib/auth-session.mjs';

process.env.NODE_TLS_REJECT_UNAUTHORIZED = '0'; // certificat local auto-signe, outil de benchmark UNIQUEMENT

globalThis.Pusher = Pusher;
const { default: Echo } = await import('laravel-echo');

function parseArgs() {
    const args = Object.fromEntries(
        process.argv.slice(2).map((a) => {
            const [k, v] = a.replace(/^--/, '').split('=');
            return [k, v ?? true];
        })
    );
    return {
        count: Number(args.count ?? 10),
        duration: Number(args.duration ?? 60),
        config: String(args.config ?? 'B'),
        out: String(args.out ?? 'results.jsonl'),
        baseUrl: String(args.baseUrl ?? process.env.BENCH_BASE_URL ?? 'https://test.laravel'),
        reverbHost: String(args.reverbHost ?? process.env.BENCH_REVERB_HOST ?? '127.0.0.1'),
        reverbPort: Number(args.reverbPort ?? process.env.BENCH_REVERB_PORT ?? 8080),
        reverbKey: String(args.reverbKey ?? process.env.BENCH_REVERB_KEY ?? ''),
        fixtures: String(args.fixtures ?? process.env.TASK1848_FIXTURES_PATH ?? 'storage/app/benchmarks/task-1848-fixtures.json'),
    };
}

const opts = parseArgs();

if (opts.config === 'A') {
    writeFileSync(opts.out, JSON.stringify({ config: 'A', note: 'Reverb desactive par mandat — aucune connexion WS ouverte.', clients: [] }) + '\n');
    console.log('[ws-clients] config=A : aucune connexion WS (attendu, voir simulate-http-polling.mjs).');
    process.exit(0);
}

if (!opts.reverbKey) {
    console.error('TASK-1848: --reverbKey (ou BENCH_REVERB_KEY) est requis pour config=B.');
    process.exit(1);
}

const fixturesRaw = await import('node:fs').then((fs) => fs.readFileSync(opts.fixtures, 'utf8'));
const fixtures = JSON.parse(fixturesRaw);

const ROLES = ['loop', 'direct_conversation', 'entraide_conversation', 'idle_badges_only'];

function roleFor(index) {
    return ROLES[index % ROLES.length];
}

function pairFor(fixtures, userIndex) {
    return fixtures.pairs.find((p) => p.buyer_index === userIndex || p.seller_index === userIndex);
}

const events = [];
function record(type, extra) {
    events.push({ t: Date.now(), type, ...extra });
}

async function runClient(userIndex) {
    const user = fixtures.users[userIndex];
    const role = roleFor(userIndex);
    const label = `u${userIndex}:${role}`;

    const tLoginStart = Date.now();
    let session;
    try {
        session = await login(opts.baseUrl, fixtures.organization_slug, user.email, fixtures.password);
    } catch (err) {
        record('login_failed', { label, error: String(err) });
        return null;
    }
    record('login_ok', { label, ms: Date.now() - tLoginStart });

    const echo = new Echo({
        broadcaster: 'reverb',
        Pusher,
        key: opts.reverbKey,
        wsHost: opts.reverbHost,
        wsPort: opts.reverbPort,
        wssPort: opts.reverbPort,
        forceTLS: false,
        enabledTransports: ['ws'],
        authorizer: makeAuthorizer(session),
    });

    const tConnectStart = Date.now();
    await new Promise((resolve) => {
        echo.connector.pusher.connection.bind('connected', () => {
            record('ws_connected', { label, ms: Date.now() - tConnectStart });
            resolve();
        });
        echo.connector.pusher.connection.bind('error', (err) => {
            record('ws_connection_error', { label, error: JSON.stringify(err) });
        });
    });

    // Badges : TOUS les roles y restent abonnes (navigation normale).
    const userChannel = echo.private(`user.${user.id}`);
    userChannel.listen('.message.unread-count-changed', () => record('recv', { label, channel: `user.${user.id}`, which: 'unread_count' }));
    userChannel.listen('.notification.count-changed', () => record('recv', { label, channel: `user.${user.id}`, which: 'notification_count' }));

    if (role === 'loop') {
        const loopChannel = echo.private(`loop.${fixtures.loop_id}`);
        loopChannel.listen('.loop.message.created', () => record('recv', { label, channel: `loop.${fixtures.loop_id}`, which: 'loop_message' }));
    }

    if (role === 'direct_conversation' || role === 'entraide_conversation') {
        const pair = pairFor(fixtures, userIndex);
        const transactionId = role === 'direct_conversation' ? pair?.direct_transaction_id : pair?.entraide_transaction_id;
        if (transactionId) {
            const convChannel = echo.private(`conversation.${transactionId}`);
            convChannel.listen('.conversation.message.created', () => record('recv', { label, channel: `conversation.${transactionId}`, which: 'conversation_message' }));
        }
    }

    // idle_badges_only : rien de plus — navigue ailleurs, reste abonne aux badges.

    return echo;
}

console.log(`[ws-clients] config=${opts.config} count=${opts.count} duration=${opts.duration}s`);

const clients = [];
for (let i = 0; i < opts.count; i++) {
    clients.push(runClient(i));
}
const echoInstances = (await Promise.all(clients)).filter(Boolean);

record('all_connected', { connected: echoInstances.length, requested: opts.count });
console.log(`[ws-clients] ${echoInstances.length}/${opts.count} clients connectes.`);

await new Promise((resolve) => setTimeout(resolve, opts.duration * 1000));

for (const echo of echoInstances) {
    echo.disconnect();
}
record('run_complete', { connected: echoInstances.length });

writeFileSync(opts.out, events.map((e) => JSON.stringify(e)).join('\n') + '\n');
console.log(`[ws-clients] ${events.length} evenements ecrits dans ${opts.out}`);
process.exit(0);
