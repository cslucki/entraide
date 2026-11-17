#!/usr/bin/env node
// TASK-1848 — agrege les fichiers bruts de benchmarks/task-1848/results/
// en chiffres lisibles (latence, trafic normalise, ressources, activite
// PostgreSQL). Lecture seule, ne relance aucune mesure.

import { readFileSync, existsSync } from 'node:fs';

const DIR = 'benchmarks/task-1848/results';

function readJsonl(path) {
    if (!existsSync(path)) return [];
    return readFileSync(path, 'utf8').trim().split('\n').filter(Boolean).map((l) => JSON.parse(l));
}

function psStats(label) {
    const lines = readFileSync(`${DIR}/${label}.ps.jsonl`, 'utf8').trim().split('\n');
    const samples = [];
    let pgBefore = null, pgAfter = null;
    for (const line of lines) {
        if (line.startsWith('pg_before,')) pgBefore = line.split(',').slice(1).map(Number);
        else if (line.startsWith('pg_after,')) pgAfter = line.split(',').slice(1).map(Number);
        else samples.push(JSON.parse(line));
    }
    const avg = (key) => samples.length ? (samples.reduce((s, x) => s + x[key], 0) / samples.length) : 0;
    const max = (key) => samples.length ? Math.max(...samples.map((x) => x[key])) : 0;
    const pgDelta = pgBefore && pgAfter ? {
        xact_commit: pgAfter[0] - pgBefore[0],
        tup_returned: pgAfter[2] - pgBefore[2],
        tup_fetched: pgAfter[3] - pgBefore[3],
        blks_hit: pgAfter[4] - pgBefore[4],
        blks_read: pgAfter[5] - pgBefore[5],
    } : null;
    return {
        samples: samples.length,
        apache_cpu_avg: avg('apache_cpu_pct').toFixed(1),
        apache_cpu_max: max('apache_cpu_pct').toFixed(1),
        apache_rss_avg_mb: (avg('apache_rss_kb') / 1024).toFixed(0),
        apache_workers_avg: avg('apache_workers').toFixed(0),
        apache_workers_max: max('apache_workers'),
        reverb_cpu_avg: avg('reverb_cpu_pct').toFixed(2),
        reverb_rss_avg_mb: (avg('reverb_rss_kb') / 1024).toFixed(1),
        pgDelta,
    };
}

function latencyStats(label) {
    const sent = [
        ...readJsonl(`${DIR}/${label}.sent-loop.jsonl`),
        ...readJsonl(`${DIR}/${label}.sent-direct.jsonl`),
        ...readJsonl(`${DIR}/${label}.sent-entraide.jsonl`),
    ];
    const recv = readJsonl(`${DIR}/${label}.ws.jsonl`).filter((e) => e.type === 'recv');

    // Regroupe par canal, dans l'ordre d'emission/reception (FIFO garanti
    // par TCP/WebSocket) : le k-ieme envoi sur un canal correspond au
    // k-ieme evenement RECU sur ce MEME canal par CHAQUE abonne.
    const sentByChannel = new Map();
    for (const s of sent) {
        const arr = sentByChannel.get(s.channel) ?? [];
        arr.push(s.t);
        sentByChannel.set(s.channel, arr);
    }

    const latencies = [];
    const recvByChannelAndLabel = new Map();
    for (const r of recv) {
        if (r.which !== 'conversation_message' && r.which !== 'loop_message') continue;
        const key = `${r.channel}::${r.label}`;
        const arr = recvByChannelAndLabel.get(key) ?? [];
        arr.push(r.t);
        recvByChannelAndLabel.set(key, arr);
    }

    for (const [key, recvTimes] of recvByChannelAndLabel.entries()) {
        const [channel] = key.split('::');
        const sentTimes = sentByChannel.get(channel);
        if (!sentTimes) continue;
        const n = Math.min(sentTimes.length, recvTimes.length);
        for (let i = 0; i < n; i++) {
            const lat = recvTimes[i] - sentTimes[i];
            if (lat >= 0 && lat < 10000) latencies.push(lat);
        }
    }

    latencies.sort((a, b) => a - b);
    const pct = (p) => latencies.length ? latencies[Math.floor((latencies.length - 1) * p)] : null;
    return { n: latencies.length, p50: pct(0.5), p95: pct(0.95), min: latencies[0] ?? null, max: latencies[latencies.length - 1] ?? null };
}

function wsConnectStats(label) {
    const events = readJsonl(`${DIR}/${label}.ws.jsonl`);
    const connected = events.filter((e) => e.type === 'ws_connected').map((e) => e.ms);
    const loginOk = events.filter((e) => e.type === 'login_ok').map((e) => e.ms);
    const errors = events.filter((e) => e.type === 'login_failed' || e.type === 'ws_connection_error');
    const all = events.find((e) => e.type === 'all_connected');
    const pct = (arr, p) => { const s = [...arr].sort((a, b) => a - b); return s.length ? s[Math.floor((s.length - 1) * p)] : null; };
    return {
        requested: all?.requested,
        connected: all?.connected,
        errors: errors.length,
        login_ms_p50: pct(loginOk, 0.5),
        login_ms_p95: pct(loginOk, 0.95),
        ws_connect_ms_p50: pct(connected, 0.5),
        ws_connect_ms_p95: pct(connected, 0.95),
    };
}

const summaries = ['A-S1', 'A-HTTP20', 'B-S1', 'B-HTTP20'].map((l) => JSON.parse(readFileSync(`${DIR}/${l}.summary.json`, 'utf8')));

console.log('=== Trafic Livewire (requetes "ours", normalise /min) ===');
for (const s of summaries) {
    const perMin = (s.apache_livewire_update_ours / s.duration_s) * 60;
    console.log(`${s.label} (config ${s.config}, n=${s.count}): ${s.apache_livewire_update_ours} requetes livewire/update en ${s.duration_s}s = ${perMin.toFixed(1)} req/min`);
}

console.log('\n=== Ressources (moyenne/pic sur la fenetre) ===');
for (const label of ['A-S1', 'B-S1', 'B-WS50', 'B-WS100']) {
    if (!existsSync(`${DIR}/${label}.ps.jsonl`)) continue;
    console.log(`${label}:`, JSON.stringify(psStats(label)));
}

console.log('\n=== Latence de reception (envoi -> evenement WS recu), B-S1/B-WS50/B-WS100 ===');
for (const label of ['B-S1', 'B-WS50', 'B-WS100']) {
    if (!existsSync(`${DIR}/${label}.ws.jsonl`)) continue;
    console.log(`${label}:`, JSON.stringify(latencyStats(label)));
}

console.log('\n=== Connexions WS (login + handshake) ===');
for (const label of ['B-S1', 'B-WS50', 'B-WS100']) {
    if (!existsSync(`${DIR}/${label}.ws.jsonl`)) continue;
    console.log(`${label}:`, JSON.stringify(wsConnectStats(label)));
}
