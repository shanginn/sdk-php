<?php

declare(strict_types=1);

namespace Temporal\Tests\Acceptance\App\Runtime;

use Temporal\Testing\Environment;

final class WorkerStarter
{
    private Environment $environment;

    public function __construct(
        private State $runtime,
        ?Environment $environment = null,
    ) {
        $this->environment = $environment ?? Environment::create();
        \register_shutdown_function(fn() => $this->stop());
    }

    public function start(): void
    {
        if ($this->environment->isWorkerRunning()) {
            return;
        }

        $allowedTestClasses = $this->runtime->allowedTestClasses;

        $run = $this->runtime->command;

        $workerArgs = [
            PHP_BINARY,
            ...$run->getPhpBinaryArguments(),
            $this->runtime->workerDir . DIRECTORY_SEPARATOR . 'worker.php',
            ...$run->getCommandLineArguments(),
        ];

        foreach ($allowedTestClasses as $class) {
            $workerArgs[] = 'test-class=' . $class;
        }

        $envs = [
            'TEMPORAL_ADDRESS' => $this->runtime->address,
            'TEMPORAL_NAMESPACE' => $this->runtime->namespace,
            'TEMPORAL_TEST_SHARED_STORE' => SharedStore::defaultPath($this->runtime->workDir),
        ];
        $runId = \getenv('TEMPORAL_TRANSCRIPT_RUN_ID');
        if (\is_string($runId) && $runId !== '') {
            $envs['TEMPORAL_TRANSCRIPT_RUN_ID'] = $runId;
        }

        $this->environment->startWorker(
            command: $workerArgs,
            envs: $envs,
        );
    }

    public function stop(): void
    {
        $this->environment->stopWorker();
    }

    public function __destruct()
    {
        $this->stop();
    }
}
