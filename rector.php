<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\ValueObject\PhpVersion;

// Configuration Rector — Review Cluster (DRY-RUN ONLY)
// AUCUN CHANGEMENT AUTOMATIQUE NE SERA APPLIQUÉ

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/app',
    ])
    ->withPhpVersion(PhpVersion::PHP_84)
    ->withSkip([]);