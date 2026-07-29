<?php

declare(strict_types=1);

use Temporal\Testing\Environment;
use Temporal\Testing\SystemInfo;
use Temporal\Tests\SearchAttributeTestInvoker;
use Temporal\Worker\FeatureFlags;

\chdir(__DIR__ . '/../..');
require_once __DIR__ . '/../../vendor/autoload.php';

$systemInfo = SystemInfo::detect();

$environment = Environment::create(systemInfo: $systemInfo);
$environment->startTemporalTestServer();
(new SearchAttributeTestInvoker())();
$environment->startWorker(
    command: [
        PHP_BINARY,
        ...$environment->command->getPhpBinaryArguments(),
        'tests/Functional/worker.php',
    ],
    envs: [
        'TEMPORAL_ADDRESS' => (string) $environment->command->address,
        'TEMPORAL_NAMESPACE' => (string) $environment->command->namespace,
    ],
);

\register_shutdown_function(static fn() => $environment->stop());

// Default feature flags
FeatureFlags::$warnOnWorkflowUnfinishedHandlers = false;
