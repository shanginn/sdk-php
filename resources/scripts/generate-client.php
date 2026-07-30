<?php

declare(strict_types=1);

/**
 * This file is part of Temporal package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Laminas\Code\Generator;
use Laminas\Code\Generator\MethodGenerator;
use Temporal\Api\Workflowservice;
use Temporal\Client\Common\ServerCapabilities;
use Temporal\Client\GRPC\Connection\ConnectionInterface;
use Temporal\Client\GRPC\ContextInterface;

require __DIR__ . '/../../vendor/autoload.php';

echo "Compiling client...\n";

echo "reading client schema: ";

$root = \dirname(__DIR__, 2);
$schemaPath = \getenv('TEMPORAL_WORKFLOW_SERVICE_PROTO')
    ?: $root . '/../php-temporal/third_party/sdk-rust/crates/protos/protos/api_upstream'
        . '/temporal/api/workflowservice/v1/service.proto';

if (!\is_file($schemaPath)) {
    \fwrite(STDERR, "Cannot find WorkflowService schema under: {$schemaPath}\n");
    \fwrite(STDERR, "Set TEMPORAL_WORKFLOW_SERVICE_PROTO to workflowservice/v1/service.proto.\n");
    exit(1);
}

$schema = \file_get_contents($schemaPath);
if ($schema === false) {
    \fwrite(STDERR, "Cannot read WorkflowService schema: {$schemaPath}\n");
    exit(1);
}

\preg_match_all(
    '/\brpc\s+([A-Za-z0-9_]+)\s*\(\s*([A-Za-z0-9_]+)\s*\)\s*'
        . 'returns\s*\(\s*([A-Za-z0-9_]+)\s*\)/',
    $schema,
    $rpcMatches,
    PREG_SET_ORDER,
);

$availableMethods = [];
foreach ($rpcMatches as [, $name, $request, $response]) {
    $availableMethods[$name] = [
        'request' => Workflowservice\V1::class . '\\' . $request,
        'response' => Workflowservice\V1::class . '\\' . $response,
    ];
}

if ($availableMethods === []) {
    \fwrite(STDERR, "No WorkflowService RPC methods found in: {$schemaPath}\n");
    exit(1);
}

$existingInterface = new ReflectionClass(\Temporal\Client\GRPC\ServiceClientInterface::class);
$generateAll = \in_array('--all', $argv, true);
$checkOnly = \in_array('--check', $argv, true);
$requiredMethods = [
    'StartActivityExecution',
    'DescribeActivityExecution',
    'PollActivityExecution',
    'ListActivityExecutions',
    'CountActivityExecutions',
    'RequestCancelActivityExecution',
    'TerminateActivityExecution',
    'DeleteActivityExecution',
];

$ctxParam = Generator\ParameterGenerator::fromArray(
    [
        'type' => \Temporal\Client\GRPC\ContextInterface::class,
        'name' => 'ctx',
        'defaultValue' => null,
    ],
);

$methodDocBlock = static function (string $method, string $arg, string $return) use ($existingInterface) {
    $block = ["Temporal WorkflowService RPC {$method}."];
    if ($existingInterface->hasMethod($method)) {
        $doc = $existingInterface->getMethod($method)->getDocComment();
        if ($doc !== false) {
            $description = [];
            foreach (\explode("\n", $doc) as $line) {
                $line = \trim($line, "\n\r* /");
                if ($line === '' || \str_starts_with($line, '@')) {
                    continue;
                }
                $description[] = $line;
            }
            $description === [] or $block = $description;
        }
    }
    $block[] = '';
    $block[] = \sprintf('@param \\%s $arg', $arg);
    $block[] = '@param ContextInterface|null $ctx';
    $block[] = \sprintf('@return \\%s', $return);
    $block[] = '@throws ServiceClientException';

    return \join("\n", $block);
};

$methods = [];

// By default preserve the SDK's explicitly supported raw surface. `--all`
// opts into adding every RPC from the pinned API schema.
foreach ($availableMethods as $name => $method) {
    if (!$generateAll && !$existingInterface->hasMethod($name)) {
        continue;
    }
    if (!\class_exists($method['request']) || !\class_exists($method['response'])) {
        throw new RuntimeException("Missing generated messages for WorkflowService RPC {$name}.");
    }
    $methods[$name] = $method;
}

echo "[OK]\n";

if ($checkOnly) {
    $implementation = new ReflectionClass(\Temporal\Client\GRPC\ServiceClient::class);
    foreach ($requiredMethods as $name) {
        if (
            !isset($availableMethods[$name])
            || !$existingInterface->hasMethod($name)
            || !$implementation->hasMethod($name)
        ) {
            throw new RuntimeException("Required WorkflowService RPC {$name} is missing.");
        }
    }

    foreach ($methods as $name => $method) {
        if (!$existingInterface->hasMethod($name) || !$implementation->hasMethod($name)) {
            throw new RuntimeException("WorkflowService RPC {$name} is missing from the client surface.");
        }

        foreach ([$existingInterface, $implementation] as $class) {
            $reflection = $class->getMethod($name);
            $parameter = $reflection->getParameters()[0] ?? null;
            $parameterType = $parameter?->getType();
            $returnType = $reflection->getReturnType();
            if (
                !$parameterType instanceof ReflectionNamedType
                || $parameterType->getName() !== $method['request']
                || !$returnType instanceof ReflectionNamedType
                || $returnType->getName() !== $method['response']
            ) {
                throw new RuntimeException(
                    "WorkflowService RPC {$name} signature does not match the pinned schema.",
                );
            }
        }
    }

    echo 'checked ' . \count($methods) . " supported RPC methods [OK]\n";
    exit(0);
}

echo "generating interface: ";

$interface = new Generator\InterfaceGenerator('ServiceClientInterface');


// getContext(): ContextInterface
$m = new MethodGenerator(
    'getContext',
    [],
    MethodGenerator::FLAG_PUBLIC,
);
$m->setReturnType(ContextInterface::class);
$interface->addMethodFromGenerator($m);
// withContext(ContextInterface $context): static
$m = new MethodGenerator(
    'withContext',
    [Generator\ParameterGenerator::fromArray(['type' => ContextInterface::class, 'name' => 'context'])],
    MethodGenerator::FLAG_PUBLIC,
);
$m->setReturnType('static');
$interface->addMethodFromGenerator($m);
// withAuthKey(string $key): static
$m = new MethodGenerator(
    'withAuthKey',
    [Generator\ParameterGenerator::fromArray(['type' => '\Stringable|string', 'name' => 'key'])],
    MethodGenerator::FLAG_PUBLIC,
);
$m->setReturnType('static');
$interface->addMethodFromGenerator($m);
// public function getConnection(): ConnectionInterface
$m = new MethodGenerator(
    'getConnection',
    [],
    MethodGenerator::FLAG_PUBLIC,
);
$m->setReturnType(ConnectionInterface::class);
$interface->addMethodFromGenerator($m);
// Add Capability methods
$m = new MethodGenerator(
    'getServerCapabilities',
    [],
    MethodGenerator::FLAG_PUBLIC,
);
$m->setReturnType('?' . ServerCapabilities::class);
$interface->addMethodFromGenerator($m);

foreach ($methods as $method => $options) {
    $m = new MethodGenerator($method);

    $m->setDocBlock(($methodDocBlock)($method, $options['request'], $options['response']));
    $m->setParameters(
        [
            Generator\ParameterGenerator::fromArray(['type' => $options['request'], 'name' => 'arg']),
            $ctxParam,
        ],
    );
    $m->setReturnType($options['response']);

    $interface->addMethodFromGenerator($m);
}

$m = new MethodGenerator(
    'close',
    [],
    MethodGenerator::FLAG_PUBLIC,
    null,
    'Close the communication channel associated with this stub.',
);
$m->setReturnType('void');
$interface->addMethodFromGenerator($m);

echo "[OK]\n";
echo "writing interface: ";

$file = new Generator\FileGenerator();
$file->setNamespace('Temporal\\Client\\GRPC');
$file->setClass($interface);
$file->setUses(
    [
        'Temporal\Api\Workflowservice\V1',
        'Temporal\Exception\Client\ServiceClientException',
    ],
);

// write and shorten names
\file_put_contents(
    __DIR__ . '/../../src/Client/GRPC/ServiceClientInterface.php',
    \str_replace(
        ['\\Temporal\\Api\\Workflowservice\\', '\\Temporal\\Client\\GRPC\\ContextInterface'],
        ['', 'ContextInterface'],
        $file->generate(),
    ),
);
echo "[OK]\n";


echo "generating implementation: ";

$impl = new Generator\ClassGenerator('ServiceClient');
$impl->setExtendedClass('BaseClient');

foreach ($methods as $method => $options) {
    $m = new MethodGenerator($method);

    $m->setDocBlock(($methodDocBlock)($method, $options['request'], $options['response']));
    $m->setParameters(
        [
            Generator\ParameterGenerator::fromArray(['type' => $options['request'], 'name' => 'arg']),
            $ctxParam,
        ],
    );
    $m->setReturnType($options['response']);

    $m->setBody(\sprintf('return $this->invoke("%s", $arg, $ctx);', $m->getName()));

    $impl->addMethodFromGenerator($m);
}

echo "[OK]\n";

echo "writing implementation: ";

$file = new Generator\FileGenerator();
$file->setNamespace('Temporal\\Client\\GRPC');
$file->setClass($impl);
$file->setUses(
    [
        'Temporal\Api\Workflowservice\V1',
        'Temporal\Exception\Client\ServiceClientException',
    ],
);

// write and shorten names
\file_put_contents(
    __DIR__ . '/../../src/Client/GRPC/ServiceClient.php',
    \str_replace(
        ['\\Temporal\\Api\\Workflowservice\\', '\\Temporal\\Client\\GRPC\\ContextInterface'],
        ['', 'ContextInterface'],
        $file->generate(),
    ),
);
echo "[OK]\n";
