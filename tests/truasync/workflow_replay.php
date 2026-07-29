<?php

declare(strict_types=1);

/**
 * Offline replay integration: a matching workflow must replay cleanly and a
 * command-type mismatch must surface as NonDeterministicWorkflowException.
 */

require __DIR__ . '/../../vendor/autoload.php';

use Temporal\Testing\Replay\Exception\NonDeterministicWorkflowException;
use Temporal\Testing\Replay\WorkflowReplayer;
use Temporal\Workflow;
use Temporal\Workflow\WorkflowMethod;

#[Workflow\WorkflowInterface]
final class MatchingReplayWorkflow
{
    #[WorkflowMethod(name: 'Extra_Versioning_Classic')]
    public function run(): iterable
    {
        $version = yield Workflow::getVersion('test', Workflow::DEFAULT_VERSION, 2);
        if ($version === 1) {
            yield Workflow::sideEffect(static fn(): string => 'test');
        }

        return 'v1';
    }
}

#[Workflow\WorkflowInterface]
final class MismatchingReplayWorkflow
{
    #[WorkflowMethod(name: 'Extra_Versioning_Classic')]
    public function run(): iterable
    {
        yield Workflow::timer(1);
    }
}

if (!\extension_loaded('temporal')) {
    \fwrite(\STDERR, "FAIL: temporal extension not loaded\n");
    exit(1);
}

$history = __DIR__ . '/../Acceptance/Extra/Versioning/Classic/Versioning-v1.json';

(new WorkflowReplayer(workflowTypes: [MatchingReplayWorkflow::class]))
    ->replayFromJSON('Extra_Versioning_Classic', $history);

try {
    (new WorkflowReplayer(workflowTypes: [MismatchingReplayWorkflow::class]))
        ->replayFromJSON('Extra_Versioning_Classic', $history);
    \fwrite(\STDERR, "FAIL: mismatching workflow replay succeeded\n");
    exit(1);
} catch (NonDeterministicWorkflowException $expected) {
    \fwrite(\STDOUT, "PASS: deterministic replay succeeded and mismatch was rejected\n");
}
