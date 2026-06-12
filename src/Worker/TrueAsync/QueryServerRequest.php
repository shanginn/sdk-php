<?php

/**
 * This file is part of Temporal package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Temporal\Worker\TrueAsync;

use Temporal\DataConverter\ValuesInterface;
use Temporal\Worker\Transport\Command\Server\ServerRequest;
use Temporal\Worker\Transport\Command\Server\TickInfo;

/**
 * An InvokeQuery server request carrying its coresdk query id. A typed marker:
 * the factory must route queries outside the Server (their request id is the
 * run id, so their outcome cannot be told apart from other acks in the generic
 * response queue), and matching on a class is sturdier than sniffing options.
 */
final class QueryServerRequest extends ServerRequest
{
    public function __construct(
        public readonly string $queryId,
        string $name,
        TickInfo $info,
        array $options = [],
        ?ValuesInterface $payloads = null,
        ?string $id = null,
    ) {
        parent::__construct($name, $info, $options, $payloads, $id);
    }
}
