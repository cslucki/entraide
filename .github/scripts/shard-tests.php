#!/usr/bin/env php
<?php

/**
 * TASK-1334 — Decoupe deterministe de la suite Feature en N shards.
 *
 * ## Pourquoi
 *
 * Avant cette TASK, la CI PostgreSQL lançait `tests/Feature` — 5 716 tests —
 * dans UN seul processus PHPUnit sur un runner GitHub. Mesure du 2026-08-30 :
 * tout ce qui precede les tests Feature coute 51 secondes, les tests Feature
 * coutent ~17 minutes. Le job entier etait donc, a 95 %, une seule commande.
 *
 * Un runner `ubuntu-latest` ne peut pas accelerer ce processus. Quatre runners
 * le peuvent — a condition de leur donner quatre parts disjointes du meme
 * ensemble.
 *
 * ## Ce que le script produit
 *
 * Pour chaque shard, un fichier `phpunit.ci-feature.shard-K.xml` **derive de
 * `phpunit.ci-feature.xml`** : le `<directory>tests/Feature</directory>` est
 * remplace par la liste explicite des fichiers du shard. Tout le reste du
 * fichier de reference — les exclusions de groupes `ci-known-red` et
 * `sqlite-only`, le bloc `<php>` avec `APP_LOCALE=fr`, la cle, la connexion —
 * est **repris tel quel**. Deriver plutot que reecrire est le point important :
 * une regle ajoutee demain a `phpunit.ci-feature.xml` se propage seule aux
 * quatre shards, et il n'existe aucun endroit ou les deux configurations
 * peuvent diverger en silence.
 *
 * ## Repartition
 *
 * Bin-packing glouton sur la **taille des fichiers**, du plus gros au plus
 * petit, chaque fichier allant au shard le moins charge. La taille n'est qu'un
 * proxy de la duree, mais un proxy honnete : un fichier de test long contient
 * beaucoup de cas. Une simple alternance (round-robin) sur les chemins tries
 * regrouperait des repertoires entiers — `tests/Feature/Livewire` a lui seul
 * pese plus qu'un quart de la suite — et produirait un shard lent qui
 * dicterait la duree de toute la CI.
 *
 * Le tri est **totalement deterministe** : taille decroissante, puis chemin en
 * ordre alphabetique pour departager. Deux executions sur le meme arbre de
 * fichiers donnent exactement la meme decoupe, sur n'importe quelle machine.
 *
 * ## Garantie de population
 *
 * `--verify` reconstruit l'union des shards et la compare a l'enumeration
 * complete de `tests/Feature`. Il echoue si un fichier manque, si un fichier
 * apparait deux fois, ou si un shard est vide. C'est ce qui interdit qu'un
 * gain de duree soit en realite une perte de couverture : le seul moyen de
 * rendre la CI plus rapide en trichant serait d'oublier des tests, et cette
 * verification le rend impossible sans que le job rougisse.
 *
 * Usage:
 *   php .github/scripts/shard-tests.php --total=4          # ecrit les 4 XML
 *   php .github/scripts/shard-tests.php --total=4 --verify # + controle strict
 *   php .github/scripts/shard-tests.php --total=4 --dry-run
 */

$options = getopt('', ['total::', 'verify', 'dry-run', 'source::', 'dir::']);

$total = isset($options['total']) ? (int) $options['total'] : 4;
$verify = array_key_exists('verify', $options);
$dryRun = array_key_exists('dry-run', $options);
$sourceConfig = $options['source'] ?? 'phpunit.ci-feature.xml';
$testDir = $options['dir'] ?? 'tests/Feature';

$root = dirname(__DIR__, 2);
chdir($root);

if ($total < 1) {
    fwrite(STDERR, "ERROR: --total must be >= 1 (got {$total}).\n");
    exit(1);
}

if (! is_file($sourceConfig)) {
    fwrite(STDERR, "ERROR: source config not found: {$sourceConfig}\n");
    exit(1);
}

if (! is_dir($testDir)) {
    fwrite(STDERR, "ERROR: test directory not found: {$testDir}\n");
    exit(1);
}

// -------------------------------------------------------------------------
// 1. Enumeration complete — la reference contre laquelle tout est compare.
// -------------------------------------------------------------------------

$files = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($testDir, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if ($file->isFile() && str_ends_with($file->getFilename(), 'Test.php')) {
        // Chemin relatif a la racine, separateurs normalises : le XML genere
        // doit etre identique sous Linux et sous Windows.
        //
        // `$testDir` est deja relatif et on a fait `chdir($root)` : le chemin
        // rendu par l'iterateur est donc deja relatif a la racine. Le tronquer
        // en supposant un chemin absolu produirait des cles tronquees qui se
        // telescopent — 392 fichiers s'etaient ainsi reduits a 77.
        $relative = str_replace('\\', '/', $file->getPathname());
        $relative = preg_replace('#^\\./#', '', $relative);
        $files[$relative] = $file->getSize();
    }
}

if ($files === []) {
    fwrite(STDERR, "ERROR: no *Test.php found under {$testDir}.\n");
    exit(1);
}

$totalFiles = count($files);

if ($total > $totalFiles) {
    fwrite(STDERR, "ERROR: --total={$total} exceeds the number of test files ({$totalFiles}).\n");
    exit(1);
}

// -------------------------------------------------------------------------
// 2. Bin-packing glouton, deterministe.
// -------------------------------------------------------------------------

$ordered = $files;
// Taille decroissante ; a taille egale, chemin croissant. Le second critere
// n'est pas cosmetique : sans lui, l'ordre de deux fichiers de meme taille
// dependrait de l'ordre de parcours du systeme de fichiers, et la decoupe
// cesserait d'etre reproductible.
uksort($ordered, function (string $a, string $b) use ($files): int {
    return [$files[$b], $a] <=> [$files[$a], $b];
});

$shards = array_fill(1, $total, []);
$weights = array_fill(1, $total, 0);

foreach ($ordered as $path => $size) {
    $lightest = array_keys($weights, min($weights))[0];
    $shards[$lightest][] = $path;
    $weights[$lightest] += $size;
}

// Chaque shard est trie par chemin : le XML genere est stable d'une execution
// a l'autre, donc lisible dans un diff.
foreach ($shards as $index => $paths) {
    sort($paths);
    $shards[$index] = $paths;
}

// -------------------------------------------------------------------------
// 3. Verification de population — avant d'ecrire quoi que ce soit.
// -------------------------------------------------------------------------

$union = [];
$duplicates = [];

foreach ($shards as $index => $paths) {
    foreach ($paths as $path) {
        if (isset($union[$path])) {
            $duplicates[] = "{$path} (shards {$union[$path]} and {$index})";
        }
        $union[$path] = $index;
    }
}

$missing = array_diff(array_keys($files), array_keys($union));
$extra = array_diff(array_keys($union), array_keys($files));
$empty = [];

foreach ($shards as $index => $paths) {
    if ($paths === []) {
        $empty[] = $index;
    }
}

$problems = [];
if ($missing !== []) {
    $problems[] = 'MISSING ' . count($missing) . ': ' . implode(', ', array_slice($missing, 0, 10));
}
if ($extra !== []) {
    $problems[] = 'UNKNOWN ' . count($extra) . ': ' . implode(', ', array_slice($extra, 0, 10));
}
if ($duplicates !== []) {
    $problems[] = 'DUPLICATE ' . count($duplicates) . ': ' . implode(', ', array_slice($duplicates, 0, 10));
}
if ($empty !== []) {
    $problems[] = 'EMPTY SHARDS: ' . implode(', ', $empty);
}

// -------------------------------------------------------------------------
// 4. Generation des configurations.
// -------------------------------------------------------------------------

$source = file_get_contents($sourceConfig);

// On remplace la seule ligne `<directory>tests/Feature</directory>` de la
// testsuite. Tout le reste du fichier est conserve octet pour octet.
$needle = '<directory>' . $testDir . '</directory>';

if (! str_contains($source, $needle)) {
    fwrite(STDERR, "ERROR: could not find [{$needle}] in {$sourceConfig}.\n");
    fwrite(STDERR, "The shard generator derives from that file; it must keep declaring the directory.\n");
    exit(1);
}

if (substr_count($source, $needle) !== 1) {
    fwrite(STDERR, "ERROR: [{$needle}] appears more than once in {$sourceConfig}.\n");
    exit(1);
}

$written = [];

foreach ($shards as $index => $paths) {
    $entries = array_map(
        fn (string $path): string => '            <file>' . htmlspecialchars($path, ENT_XML1) . '</file>',
        $paths
    );

    $banner = <<<XML
<!--
                GENERE PAR .github/scripts/shard-tests.php — NE PAS EDITER.

                Shard {$index}/{$total}. Derive de {$sourceConfig} : toutes les
                exclusions de groupes et tout le bloc <php> viennent de ce
                fichier et ne sont pas dupliques ici.
            -->
XML;

    $replacement = $banner . "\n" . implode("\n", $entries);
    $xml = str_replace($needle, $replacement, $source);

    $target = "phpunit.ci-feature.shard-{$index}.xml";
    $written[$target] = [
        'files' => count($paths),
        'bytes' => $weights[$index],
    ];

    if (! $dryRun) {
        file_put_contents($target, $xml);
    }
}

// -------------------------------------------------------------------------
// 5. Rapport.
// -------------------------------------------------------------------------

echo "Feature shard plan — {$totalFiles} files across {$total} shards\n";
echo str_repeat('-', 64) . "\n";

foreach ($written as $target => $stats) {
    printf(
        "  %-34s %4d files  %7.1f KB\n",
        $target,
        $stats['files'],
        $stats['bytes'] / 1024
    );
}

echo str_repeat('-', 64) . "\n";
printf(
    "  union: %d files | missing: %d | duplicates: %d | empty shards: %d\n",
    count($union),
    count($missing),
    count($duplicates),
    count($empty)
);

$spread = max($weights) > 0 ? (max($weights) - min($weights)) / max($weights) * 100 : 0.0;
printf("  balance spread (heaviest vs lightest): %.1f%%\n", $spread);

if ($dryRun) {
    echo "  (dry run — no file written)\n";
}

if ($problems !== []) {
    fwrite(STDERR, "\nPOPULATION CHECK FAILED\n");
    foreach ($problems as $problem) {
        fwrite(STDERR, "  - {$problem}\n");
    }
    exit(1);
}

if ($verify) {
    echo "\nPOPULATION CHECK PASSED\n";
    echo "  every file under {$testDir} appears in exactly one shard.\n";
}

exit(0);
