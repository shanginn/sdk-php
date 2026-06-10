<?php

declare(strict_types=1);

/**
 * Generates the coresdk protobuf PHP classes used by the TrueAsync worker.
 *
 * These are the bridge types exchanged with the Temporal Rust core
 * (sdk-core-c-bridge): the activity task we poll and the completion we send
 * back. They are NOT part of temporal.api.* (those ship via the
 * roadrunner-php/roadrunner-api-dto package) — the generated classes here
 * reference the existing Temporal\Api\* classes rather than duplicating them.
 *
 * The .proto sources live in the Rust core checkout (the php-temporal C
 * extension vendors it as a submodule). Point TEMPORAL_SDK_RUST_PROTOS at its
 * `crates/common/protos` directory, or keep php-temporal as a sibling of this
 * repo (the default below).
 *
 * Output namespaces:
 *   Coresdk\*                        -> bridge/Coresdk
 *   GPBMetadata\Temporal\Sdk\Core\*  -> bridge/GPBMetadata/Temporal/Sdk/Core
 */

$root = dirname(__DIR__, 2);

$protosRoot = getenv('TEMPORAL_SDK_RUST_PROTOS')
    ?: $root . '/../php-temporal/third_party/sdk-rust/crates/common/protos';

$local = $protosRoot . '/local';
$apiUpstream = $protosRoot . '/api_upstream';
$vendored = __DIR__ . '/../proto';

if (!is_dir($local) || !is_dir($apiUpstream)) {
    fwrite(STDERR, "Cannot find Rust core protos under: {$protosRoot}\n");
    fwrite(STDERR, "Set TEMPORAL_SDK_RUST_PROTOS to <sdk-rust>/crates/common/protos.\n");
    exit(1);
}

/* The activity-worker surface only. We deliberately do NOT generate
   core_interface.proto (which holds ActivityTaskCompletion / ActivityHeartbeat):
   it eagerly initializes the entire workflow proto tree, which references
   temporal.api.* types newer than the bundled roadrunner-api-dto package. The
   two activity request messages are instead taken from our trimmed, wire-
   identical resources/proto/.../activity_completion.proto. The temporal.api.*
   and google.* imports resolve to the existing Temporal\Api\* (dto) classes and
   protobuf's built-ins. Phase 3 (the workflow worker) will generate the full
   set against a matching api-dto version. */
$files = [
    'temporal/sdk/core/common/common.proto',
    'temporal/sdk/core/activity_task/activity_task.proto',
    'temporal/sdk/core/activity_result/activity_result.proto',
    'temporal/sdk/core/activity_completion.proto',
];

$out = $root . '/bridge';
if (!is_dir($out) && !mkdir($out, 0777, true) && !is_dir($out)) {
    fwrite(STDERR, "Cannot create output dir: {$out}\n");
    exit(1);
}

$cmd = array_merge(
    ['protoc', '-I', $vendored, '-I', $local, '-I', $apiUpstream, '--php_out=' . $out],
    $files
);

$escaped = implode(' ', array_map('escapeshellarg', $cmd));
echo $escaped, "\n";

passthru($escaped, $code);
exit($code);
