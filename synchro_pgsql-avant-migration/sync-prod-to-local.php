#!/usr/bin/env php
<?php
// =========================================================
// SYNC PROD TO LOCAL - PHP ETL Script
// =========================================================
// Transfers data from temporary PROD database to local DB
// without modifying the local schema.
//
// Workflow:
// 1. Connect to both databases
// 2. Identify common tables
// 3. For each common table:
//    - Identify common columns
//    - Import data (upsert if PK exists)
//    - Respect FK ordering (parents before children)
//    - Inject main_id for organization_id/community_id if missing
// 4. Backfill organization_id/community_id for legacy data
// 5. Reset PostgreSQL sequences
// 6. Generate detailed report
// =========================================================

set_time_limit(0);
ini_set('memory_limit', '1G');

// Parse command line arguments
$options = getopt('', [
    'local-host:',
    'local-port:',
    'local-db:',
    'local-user:',
    'temp-db:',
]);

$localPassword = getenv('LOCAL_DB_PASSWORD');
if ($localPassword === false || $localPassword === '') {
    fwrite(STDERR, "ERROR: LOCAL_DB_PASSWORD environment variable is not set\n");
    exit(1);
}

$localConn = [
    'host' => $options['local-host'] ?? '127.0.0.1',
    'port' => $options['local-port'] ?? '5432',
    'dbname' => $options['local-db'] ?? 'bouclepro',
    'user' => $options['local-user'] ?? 'bouclepro',
    'password' => $localPassword,
    'temp_dbname' => $options['temp-db'] ?? 'bouclepro_prod_import_tmp',
];

$report = [
    'timestamp' => date('Y-m-d H:i:s'),
    'dump_file' => basename($_SERVER['argv'][0] ?? 'unknown'),
    'temp_db' => $localConn['temp_dbname'],
    'local_db' => $localConn['dbname'],
    'tables_imported' => [],
    'tables_ignored' => [],
    'tables_blacklisted' => [],
    'tables_local_preserved' => [],
    'columns_ignored' => [],
    'backfills_performed' => [],
    'counters_before' => [],
    'counters_after' => [],
    'anomalies' => [],
    'sequences_reset' => [],
];

$blacklistPattern = [
    'migrations',
    'sessions',
    'cache',
    'cache_locks',
    'jobs',
    'job_batches',
    'failed_jobs',
    'password_reset_tokens',
    'personal_access_tokens',
    'loops',
    'loop_members',
    'loop_messages',
];

try {
    $pdoLocal = connectPdo($localConn['host'], $localConn['port'], $localConn['dbname'], $localConn['user'], $localConn['password']);
    $pdoTemp = connectPdo($localConn['host'], $localConn['port'], $localConn['temp_dbname'], $localConn['user'], $localConn['password']);

    echo "✓ Connected to local DB: {$localConn['dbname']}\n";
    echo "✓ Connected to temp DB: {$localConn['temp_dbname']}\n\n";

    $mainOrgId = getMainOrganizationId($pdoLocal);
    if (!$mainOrgId) {
        echo "⚠ Organization 'main' not found. Creating it...\n";
        $pdoLocal->exec("INSERT INTO organizations (id, name, slug, is_active, is_default, created_at, updated_at)
                          VALUES (gen_random_uuid(), 'Main', 'main', true, true, NOW(), NOW())");
        $mainOrgId = getMainOrganizationId($pdoLocal);
        if (!$mainOrgId) {
            throw new RuntimeException('Failed to create organization "main"');
        }
        echo "✓ Created organization 'main' (ID: $mainOrgId)\n\n";
    } else {
        echo "✓ Main organization ID: $mainOrgId\n\n";
    }

    $localTables = getTables($pdoLocal);
    $tempTables = getTables($pdoTemp);

    echo "Local tables: " . count($localTables) . "\n";
    echo "Temp tables: " . count($tempTables) . "\n\n";

    // Blacklist operational tables and local-only tables
    $filteredLocal = array_filter($localTables, fn($t) => !in_array($t, $blacklistPattern, true));

    // Find common tables (excluding blacklist)
    $commonTables = array_intersect($filteredLocal, $tempTables);

    // Identify blacklisted tables present in temp
    $blacklistedFound = array_intersect($blacklistPattern, $tempTables);
    foreach ($blacklistedFound as $table) {
        $report['tables_blacklisted'][] = $table;
    }

    // Identify local-only preserved tables
    $localOnlyTables = array_diff($filteredLocal, $tempTables);
    foreach ($localOnlyTables as $table) {
        $report['tables_local_preserved'][] = $table;
    }
    // Also add blacklisted local tables as preserved
    foreach (array_intersect($blacklistPattern, $localTables) as $table) {
        $report['tables_local_preserved'][] = $table;
    }

    echo "Common tables (for import): " . count($commonTables) . "\n";
    echo "Blacklisted tables skipped: " . count($blacklistedFound) . "\n";
    echo "Local-only tables preserved: " . count($localOnlyTables) . "\n\n";

    $tableOrder = getTableDependencyOrder($pdoTemp, $commonTables);

    $report['counters_before'] = getTableCounters($pdoLocal, $commonTables);

    foreach ($tableOrder as $tableName) {
        echo "Processing: $tableName\n";

        try {
            $result = importTableData($pdoTemp, $pdoLocal, $tableName, $mainOrgId);
        } catch (Exception $e) {
            $report['anomalies'][] = "{$tableName}: {$e->getMessage()}";
            echo "  ❌ Error: {$e->getMessage()}\n";
            continue;
        }

        if ($result['imported']) {
            $report['tables_imported'][] = [
                'table' => $tableName,
                'rows' => $result['rows'],
                'strategy' => $result['strategy'],
            ];
            echo "  ✓ Imported {$result['rows']} rows ({$result['strategy']})\n";
        } else {
            $report['tables_ignored'][] = [
                'table' => $tableName,
                'reason' => $result['reason'],
            ];
            echo "  ⚠ Skipped: {$result['reason']}\n";
        }

        if (!empty($result['ignored_columns'])) {
            $report['columns_ignored'][$tableName] = $result['ignored_columns'];
            echo "  ⚠ Ignored columns: " . implode(', ', $result['ignored_columns']) . "\n";
        }
    }

    echo "\n";

    // Backfill legacy data
    echo "Backfilling legacy data to main organization...\n";
    $backfillResult = backfillLegacyData($pdoLocal, $mainOrgId);
    $report['backfills_performed'] = $backfillResult;
    foreach ($backfillResult as $col => $count) {
        echo "  ✓ $col: $count rows updated\n";
    }

    echo "\n";

    // Reset sequences
    echo "Resetting PostgreSQL sequences...\n";
    $sequenceResult = resetSequences($pdoLocal, $commonTables);
    $report['sequences_reset'] = $sequenceResult;
    foreach ($sequenceResult as $table => $value) {
        echo "  ✓ $table: sequence set to $value\n";
    }

    echo "\n";

    $report['counters_after'] = getTableCounters($pdoLocal, $commonTables);

    generateReport($report);

    echo "\n✓ ETL completed successfully\n";

} catch (Exception $e) {
    echo "\n❌ ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

// =========================================================
// Database Functions
// =========================================================
function connectPdo(string $host, string $port, string $dbname, string $user, string $password): PDO {
    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname";
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
}

function getTables(PDO $pdo): array {
    $stmt = $pdo->query("
        SELECT table_name
        FROM information_schema.tables
        WHERE table_schema = 'public'
        AND table_type = 'BASE TABLE'
        ORDER BY table_name
    ");
    return array_column($stmt->fetchAll(), 'table_name');
}

function getTableColumns(PDO $pdo, string $tableName): array {
    $stmt = $pdo->prepare("
        SELECT column_name, data_type, is_nullable, column_default
        FROM information_schema.columns
        WHERE table_name = ?
        AND table_schema = 'public'
        ORDER BY ordinal_position
    ");
    $stmt->execute([$tableName]);
    return $stmt->fetchAll();
}

function getTablePrimaryKey(PDO $pdo, string $tableName): ?array {
    $stmt = $pdo->prepare("
        SELECT a.attname
        FROM pg_index i
        JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey)
        WHERE i.indrelid = ?::regclass
        AND i.indisprimary
        ORDER BY a.attnum
    ");
    $stmt->execute([$tableName]);
    $cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
    return $cols ?: null;
}

function getTableForeignKeys(PDO $pdo, string $tableName): array {
    $stmt = $pdo->prepare("
        SELECT
            conname AS constraint_name,
            confrelid::regclass::text AS foreign_table_name,
            a.attname AS column_name
        FROM pg_constraint c
        JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = c.conkey[1]
        WHERE contype = 'f'
        AND conrelid::regclass::text = ?
        ORDER BY conname
    ");
    $stmt->execute([$tableName]);
    $fks = $stmt->fetchAll();

    // Filter out cycles: self-referencing FKs and known mutual cycles.
    // 1. Self-referencing FK (e.g., blog_comments.parent_id → blog_comments)
    // 2. organizations.admin_id → users.id (part of the organizations↔users cycle)
    return array_values(array_filter($fks, function ($fk) use ($tableName) {
        if ($fk['foreign_table_name'] === $tableName) {
            return false; // self-reference
        }
        if ($tableName === 'organizations' && $fk['column_name'] === 'admin_id') {
            return false; // cycle with users
        }
        return true;
    }));
}

function getTableDependencyOrder(PDO $pdo, array $tables): array {
    $graph = [];
    $inDegree = [];

    foreach ($tables as $table) {
        $graph[$table] = [];
        $inDegree[$table] = 0;
    }

    foreach ($tables as $table) {
        $fks = getTableForeignKeys($pdo, $table);
        foreach ($fks as $fk) {
            $refTable = $fk['foreign_table_name'];
            if (in_array($refTable, $tables, true)) {
                $graph[$refTable][] = $table;
                $inDegree[$table]++;
            }
        }
    }

    $result = [];
    $queue = [];

    foreach ($inDegree as $table => $degree) {
        if ($degree === 0) {
            $queue[] = $table;
        }
    }

    while (!empty($queue)) {
        $table = array_shift($queue);
        $result[] = $table;

        foreach ($graph[$table] as $dependent) {
            $inDegree[$dependent]--;
            if ($inDegree[$dependent] === 0) {
                $queue[] = $dependent;
            }
        }
    }

    if (count($result) !== count($tables)) {
        $remaining = array_diff($tables, $result);
        $result = array_merge($result, array_values($remaining));
    }

    return $result;
}

function getMainOrganizationId(PDO $pdo): ?string {
    $stmt = $pdo->prepare("SELECT id FROM organizations WHERE slug = 'main' LIMIT 1");
    $stmt->execute();
    return $stmt->fetchColumn() ?: null;
}

function getTableCounters(PDO $pdo, array $tables): array {
    $counters = [];
    foreach ($tables as $table) {
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM \"$table\"");
            $counters[$table] = (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            $counters[$table] = 'error';
        }
    }
    return $counters;
}

function columnExists(PDO $pdo, string $tableName, string $columnName): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM information_schema.columns
        WHERE table_name = ? AND column_name = ? AND table_schema = 'public'
    ");
    $stmt->execute([$tableName, $columnName]);
    return (int) $stmt->fetchColumn() > 0;
}

// =========================================================
// Import Functions
// =========================================================
function importTableData(PDO $sourcePdo, PDO $targetPdo, string $tableName, string $mainOrgId): array {
    $sourceColumns = getTableColumns($sourcePdo, $tableName);
    $targetColumns = getTableColumns($targetPdo, $tableName);

    $sourceColNames = array_column($sourceColumns, 'column_name');
    $targetColNames = array_column($targetColumns, 'column_name');

    $commonColumns = array_intersect($sourceColNames, $targetColNames);
    $ignoredColumns = array_diff($sourceColNames, $commonColumns);

    if (empty($commonColumns)) {
        return [
            'imported' => false,
            'reason' => 'no common columns',
            'ignored_columns' => [],
        ];
    }

    // Build target column type map for type-safe casting
    $targetTypeMap = [];
    foreach ($targetColumns as $col) {
        $targetTypeMap[$col['column_name']] = $col['data_type'];
    }

    // Determine if target has organization_id or community_id but source doesn't
    $injectOrg = in_array('organization_id', $targetColNames, true)
        && !in_array('organization_id', $sourceColNames, true);
    $injectComm = in_array('community_id', $targetColNames, true)
        && !in_array('community_id', $sourceColNames, true);

    // Build column list including injected tenant columns
    $allTargetCols = array_values($commonColumns);
    if ($injectOrg) {
        $allTargetCols[] = 'organization_id';
    }
    if ($injectComm) {
        $allTargetCols[] = 'community_id';
    }

    $pkColumns = getTablePrimaryKey($targetPdo, $tableName);

    $columnList = implode(', ', array_map(fn($c) => "\"$c\"", $allTargetCols));
    $placeholderList = implode(', ', array_fill(0, count($allTargetCols), '?'));

    // Select from source (only common columns)
    $sourceColList = implode(', ', array_map(fn($c) => "\"$c\"", $commonColumns));
    $sourceSql = "SELECT $sourceColList FROM \"$tableName\"";
    $stmt = $sourcePdo->query($sourceSql);

    $rows = 0;

    if ($pkColumns) {
        $allPkInCommon = empty(array_diff($pkColumns, $commonColumns));
    } else {
        $allPkInCommon = false;
    }

    if ($pkColumns && $allPkInCommon) {
        $pkColList = implode(', ', array_map(fn($c) => "\"$c\"", $pkColumns));
        $updateCols = array_filter($allTargetCols, fn($c) => !in_array($c, $pkColumns));

        if (empty($updateCols)) {
            $targetSql = "INSERT INTO \"$tableName\" ($columnList) VALUES ($placeholderList)
                          ON CONFLICT ($pkColList) DO NOTHING";
        } else {
            $updateList = implode(', ', array_map(fn($c) => "\"$c\" = EXCLUDED.\"$c\"", $updateCols));
            $targetSql = "INSERT INTO \"$tableName\" ($columnList) VALUES ($placeholderList)
                          ON CONFLICT ($pkColList) DO UPDATE SET $updateList";
        }

        $insertStmt = $targetPdo->prepare($targetSql);

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $filteredRow = array_intersect_key($row, array_flip($commonColumns));
            $values = array_values($filteredRow);
            $values = castRowValues($values, $allTargetCols, $targetTypeMap, $injectOrg ? $mainOrgId : null, $injectComm ? $mainOrgId : null);
            $insertStmt->execute($values);
            $rows++;
        }

        $strategy = "upsert on " . implode(',', $pkColumns);
    } else {
        $targetSql = "INSERT INTO \"$tableName\" ($columnList) VALUES ($placeholderList)
                      ON CONFLICT DO NOTHING";

        $insertStmt = $targetPdo->prepare($targetSql);

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $filteredRow = array_intersect_key($row, array_flip($commonColumns));
            $values = array_values($filteredRow);
            $values = castRowValues($values, $allTargetCols, $targetTypeMap, $injectOrg ? $mainOrgId : null, $injectComm ? $mainOrgId : null);
            $insertStmt->execute($values);
            $rows++;
        }

        $strategy = $pkColumns ? "insert on {$pkColumns[0]}..." : "insert (no clear PK)";
    }

    return [
        'imported' => true,
        'rows' => $rows,
        'strategy' => $strategy,
        'ignored_columns' => $ignoredColumns,
    ];
}

function castRowValues(array $values, array $allTargetCols, array $typeMap, ?string $orgId, ?string $commId): array {
    $result = [];
    // Cast common column values based on target type
    foreach ($values as $i => $value) {
        $col = $allTargetCols[$i] ?? null;
        $type = $col !== null ? ($typeMap[$col] ?? 'text') : 'text';
        if ($type === 'boolean' && is_bool($value)) {
            $result[] = $value ? 'true' : 'false';
        } else {
            $result[] = $value;
        }
    }
    // Append tenant columns at end
    if ($orgId !== null) {
        $result[] = $orgId;
    }
    if ($commId !== null) {
        $result[] = $commId;
    }
    return $result;
}

// =========================================================
// Backfill Functions
// =========================================================
function backfillLegacyData(PDO $pdo, string $mainOrgId): array {
    $backfills = [];

    $orgTables = [
        'users', 'services', 'service_requests', 'transactions',
        'blog_posts', 'referrals', 'referral_rewards', 'loops', 'messages',
    ];

    foreach ($orgTables as $table) {
        try {
            if (!columnExists($pdo, $table, 'organization_id')) {
                continue;
            }
            $updateStmt = $pdo->prepare("
                UPDATE \"$table\"
                SET organization_id = ?
                WHERE organization_id IS NULL
            ");
            $updateStmt->execute([$mainOrgId]);
            $count = $updateStmt->rowCount();
            if ($count > 0) {
                $backfills[$table . '.organization_id'] = $count;
            }
        } catch (PDOException $e) {
            // skip
        }
    }

    $communityTables = ['users', 'services', 'service_requests', 'transactions', 'blog_posts'];

    foreach ($communityTables as $table) {
        try {
            if (!columnExists($pdo, $table, 'community_id')) {
                continue;
            }
            $updateStmt = $pdo->prepare("
                UPDATE \"$table\"
                SET community_id = ?
                WHERE community_id IS NULL
            ");
            $updateStmt->execute([$mainOrgId]);
            $count = $updateStmt->rowCount();
            if ($count > 0) {
                $backfills[$table . '.community_id'] = $count;
            }
        } catch (PDOException $e) {
            // skip
        }
    }

    return $backfills;
}

// =========================================================
// Sequence Reset
// =========================================================
function resetSequences(PDO $pdo, array $tables): array {
    $results = [];

    foreach ($tables as $table) {
        try {
            $stmt = $pdo->prepare("
                SELECT pg_get_serial_sequence(?, 'id') AS seq
            ");
            $stmt->execute([$table]);
            $sequence = $stmt->fetchColumn();

            if (!$sequence) {
                continue;
            }

            $setStmt = $pdo->prepare("
                SELECT setval(?, COALESCE((SELECT MAX(id) FROM \"$table\"), 0) + 1, false)
            ");
            $setStmt->execute([$sequence]);
            $newVal = $setStmt->fetchColumn();
            $results[$table] = $newVal;
        } catch (PDOException $e) {
            // no serial sequence for this table
        }
    }

    return $results;
}

// =========================================================
// Report Generation
// =========================================================
function generateReport(array $report): void {
    $strategyNote = "> **Stratégie : upsert non destructif.** Ce script ne produit pas un miroir exact de la PROD ; il enrichit la DB locale existante avec les données PROD compatibles.";

    $output = "# PROD → LOCAL SYNC REPORT\n\n";
    $output .= "**Generated:** {$report['timestamp']}\n\n";
    $output .= "$strategyNote\n\n";
    $output .= "---\n\n";

    $output .= "## Summary\n\n";
    $output .= "- **Temp DB:** `{$report['temp_db']}`\n";
    $output .= "- **Local DB:** `{$report['local_db']}`\n";
    $output .= "- **Tables imported:** " . count($report['tables_imported']) . "\n";
    $output .= "- **Tables ignored:** " . count($report['tables_ignored']) . "\n";
    $output .= "- **Tables blacklisted:** " . count($report['tables_blacklisted']) . "\n";
    $output .= "- **Local tables preserved:** " . count($report['tables_local_preserved']) . "\n\n";

    if (!empty($report['tables_imported'])) {
        $output .= "## Imported Tables\n\n";
        $output .= "| Table | Rows | Strategy |\n";
        $output .= "|-------|------|----------|\n";
        foreach ($report['tables_imported'] as $item) {
            $output .= "| {$item['table']} | {$item['rows']} | {$item['strategy']} |\n";
        }
        $output .= "\n";
    }

    if (!empty($report['tables_blacklisted'])) {
        $output .= "## Blacklisted Tables (Not Imported)\n\n";
        $output .= implode("\n", array_map(fn($t) => "- `$t`", $report['tables_blacklisted'])) . "\n\n";
    }

    if (!empty($report['tables_local_preserved'])) {
        $output .= "## Local Tables Preserved\n\n";
        $output .= implode("\n", array_map(fn($t) => "- `$t`", $report['tables_local_preserved'])) . "\n\n";
    }

    if (!empty($report['tables_ignored'])) {
        $output .= "## Ignored Tables\n\n";
        $output .= "| Table | Reason |\n";
        $output .= "|-------|--------|\n";
        foreach ($report['tables_ignored'] as $item) {
            $output .= "| {$item['table']} | {$item['reason']} |\n";
        }
        $output .= "\n";
    }

    if (!empty($report['columns_ignored'])) {
        $output .= "## Ignored Columns\n\n";
        foreach ($report['columns_ignored'] as $table => $columns) {
            $output .= "**$table:** " . implode(', ', $columns) . "\n";
        }
        $output .= "\n";
    }

    if (!empty($report['backfills_performed'])) {
        $output .= "## Backfills Performed\n\n";
        $output .= "All NULL organization_id/community_id values set to 'main' organization:\n\n";
        $output .= "| Table.Column | Rows Updated |\n";
        $output .= "|--------------|-------------|\n";
        foreach ($report['backfills_performed'] as $col => $count) {
            $output .= "| $col | $count |\n";
        }
        $output .= "\n";
    }

    if (!empty($report['sequences_reset'])) {
        $output .= "## Sequences Reset\n\n";
        $output .= "| Table | New Sequence Value |\n";
        $output .= "|-------|-------------------|\n";
        foreach ($report['sequences_reset'] as $table => $value) {
            $output .= "| $table | $value |\n";
        }
        $output .= "\n";
    }

    $output .= "## Counters Comparison\n\n";
    $output .= "| Table | Before | After | Change |\n";
    $output .= "|-------|--------|-------|--------|\n";
    foreach ($report['counters_before'] as $table => $before) {
        $after = $report['counters_after'][$table] ?? 'N/A';
        if (is_numeric($before) && is_numeric($after)) {
            $change = $after - $before;
            $changeStr = $change > 0 ? "+$change" : $change;
        } else {
            $changeStr = 'N/A';
        }
        $output .= "| $table | $before | $after | $changeStr |\n";
    }
    $output .= "\n";

    if (!empty($report['anomalies'])) {
        $output .= "## Anomalies\n\n";
        foreach ($report['anomalies'] as $anomaly) {
            $output .= "- $anomaly\n";
        }
        $output .= "\n";
    }

    $output .= "---\n\n";
    $output .= "## Validation Recommendations\n\n";
    $output .= "Run these commands to verify the sync:\n\n";
    $output .= "```bash\n";
    $output .= "# Check users without organization\n";
    $output .= "php artisan tinker --execute=\"dump(DB::table('users')->whereNull('organization_id')->whereNotNull('email')->count());\"\n\n";
    $output .= "# Check key tables\n";
    $output .= "php artisan tinker --execute=\"dump([\n";
    $output .= "  'users' => DB::table('users')->count(),\n";
    $output .= "  'services' => DB::table('services')->count(),\n";
    $output .= "  'loops' => DB::table('loops')->count(),\n";
    $output .= "]);\"\n\n";
    $output .= "# Verify QA accounts exist\n";
    $output .= "php artisan tinker --execute=\"dump(DB::table('users')->where('email', 'like', 'test_%')->count());\"\n";
    $output .= "```\n\n";

    echo $output;

    // Save to log file
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }

    $logFile = $logDir . '/etl-report-' . date('Ymd-His') . '.md';
    file_put_contents($logFile, $output);
    echo "\n📄 Report saved: $logFile\n";
}