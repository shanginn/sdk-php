<?php

declare(strict_types=1);

/**
 * Offline check: a workflow task that throws while being applied produces a failed
 * WorkflowActivationCompletion rather than an uncaught exception out of the poll
 * loop, so the core can retry the task. No server required.
 *
 *   php tests/truasync/workflow_failure.php
 *
 * Exits 0 on pass, 1 on failure.
 */

require __DIR__ . '/../../vendor/autoload.php';

use Coresdk\WorkflowActivation\FireTimer;
use Coresdk\WorkflowActivation\WorkflowActivation;
use Coresdk\WorkflowActivation\WorkflowActivationJob;
use Coresdk\WorkflowCompletion\WorkflowActivationCompletion;
use Temporal\DataConverter\DataConverter;
use Temporal\Worker\TrueAsync\NullRpcConnection;
use Temporal\Worker\TrueAsync\WorkflowWorkerFactory;

$factory = WorkflowWorkerFactory::create(DataConverter::createDefault(), new NullRpcConnection());
$factory->newWorker('q');

$cases = [
    // An unsupported / empty job variant: the codec's decode match() throws.
    'unsupported-job' => (new WorkflowActivation())
        ->setRunId('run-unsupported')
        ->setJobs([new WorkflowActivationJob()]),

    // A resolution for a seq this run never issued: idForSeq throws.
    'unmapped-seq' => (new WorkflowActivation())
        ->setRunId('run-unmapped')
        ->setJobs([(new WorkflowActivationJob())->setFireTimer((new FireTimer())->setSeq(999))]),
];

$ok = true;
foreach ($cases as $name => $activation) {
    $bytes = $factory->processActivation($activation->serializeToString(), 'q');

    $completion = new WorkflowActivationCompletion();
    $completion->mergeFromString($bytes);

    $pass = $completion->hasFailed() && $completion->getRunId() === $activation->getRunId();
    $ok = $ok && $pass;

    \fwrite(\STDOUT, \sprintf(
        "[%s] failed=%s runId=%s -> %s\n",
        $name,
        $completion->hasFailed() ? 'yes' : 'no',
        $completion->getRunId(),
        $pass ? 'PASS' : 'FAIL',
    ));
}

if (!$ok) {
    \fwrite(\STDERR, "FAIL: a throwing activation did not produce a failed completion\n");
    exit(1);
}

\fwrite(\STDOUT, "PASS\n");
exit(0);
