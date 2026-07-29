<?php

/**
 * This file is part of Temporal package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Temporal\Tests\Nexus\Fixtures\ServiceHandler;

use Temporal\Nexus\Handler\OperationCancelDetails;
use Temporal\Nexus\Handler\OperationContext;
use Temporal\Nexus\Handler\OperationHandlerInterface;
use Temporal\Nexus\Handler\OperationStartDetails;
use Temporal\Nexus\Handler\OperationStartResult;
use Temporal\Nexus\OperationInfo;
use Temporal\Nexus\OperationState;

final class ExternalJobHandler implements OperationHandlerInterface
{
    public ?string $cancelledToken = null;

    public function start(
        OperationContext $context,
        OperationStartDetails $details,
        mixed $param,
    ): OperationStartResult {
        if (\is_string($param) && \str_starts_with($param, 'sync:')) {
            return OperationStartResult::sync(\substr($param, 5));
        }

        return OperationStartResult::async(new OperationInfo('ext-' . $param, OperationState::Running));
    }

    public function cancel(
        OperationContext $context,
        OperationCancelDetails $details,
    ): void {
        $this->cancelledToken = $details->operationToken;
    }
}
