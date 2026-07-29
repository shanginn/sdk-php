<?php

declare(strict_types=1);

namespace Temporal\Tests\Acceptance\App\Runtime;

use Symfony\Component\Filesystem\Path;
use Temporal\Testing\Environment;
use Temporal\Testing\SystemInfo;
use Temporal\Testing\Transcript\TranscriptStore;

final class RRStarter
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
        if ($this->environment->isRoadRunnerRunning()) {
            return;
        }

        $allowedTestClasses = $this->runtime->allowedTestClasses;

        $systemInfo = SystemInfo::detect();
        $run = $this->runtime->command;
        $rrExecutable = $systemInfo->rrExecutable;
        if (!Path::isAbsolute($rrExecutable)) {
            $rrExecutable = $this->runtime->workDir . DIRECTORY_SEPARATOR . $rrExecutable;
        }

        $workerArgs = [
            PHP_BINARY,
            ...$run->getPhpBinaryArguments(),
            $this->runtime->rrConfigDir . DIRECTORY_SEPARATOR . 'worker.php',
            ...$run->getCommandLineArguments(),
        ];

        foreach ($allowedTestClasses as $class) {
            $workerArgs[] = 'test-class=' . $class;
        }

        $rrCommand = [
            $rrExecutable,
            'serve',
            '-w',
            $this->runtime->rrConfigDir,
            '-o',
            "temporal.namespace={$this->runtime->namespace}",
            '-o',
            "temporal.address={$this->runtime->address}",
            '-o',
            'server.command=' . \implode(',', $workerArgs),
        ];
        if ($run->tlsKey !== null) {
            $rrCommand[] = '-o';
            $rrCommand[] = "tls.key={$run->tlsKey}";
        }
        if ($run->tlsCert !== null) {
            $rrCommand[] = '-o';
            $rrCommand[] = "tls.cert={$run->tlsCert}";
        }

        $envs = [];
        $runId = \getenv('TEMPORAL_TRANSCRIPT_RUN_ID');
        if (\is_string($runId) && $runId !== '') {
            $envs['TEMPORAL_TRANSCRIPT_RUN_ID'] = $runId;
        }

        $this->environment->startRoadRunner(
            rrCommand: $rrCommand,
            envs: $envs,
            configFile: $this->runtime->rrConfigDir . DIRECTORY_SEPARATOR . '.rr.yaml',
        );
    }

    public function stop(): void
    {
        $this->environment->stopRoadRunner();
    }

    public function __destruct()
    {
        $this->stop();
    }
}
