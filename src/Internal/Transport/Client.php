<?php

/**
 * This file is part of Temporal package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Temporal\Client\Internal\Transport;

use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use Temporal\Client\Exception\CancellationException;
use Temporal\Client\Internal\Queue\QueueInterface;
use Temporal\Client\Internal\Transport\Request\Cancel;
use Temporal\Client\Worker\Command\ErrorResponseInterface;
use Temporal\Client\Worker\Command\RequestInterface;
use Temporal\Client\Worker\Command\ResponseInterface;
use Temporal\Client\Worker\Command\SuccessResponseInterface;
use Temporal\Client\Worker\LoopInterface;

/**
 * @internal Client is an internal library class, please do not use it in your code.
 * @psalm-internal Temporal\Client\Internal\Transport
 */
final class Client implements ClientInterface
{
    /**
     * @var string
     */
    private const ERROR_REQUEST_ID_DUPLICATION =
        'Unable to create a new request because a ' .
        'request with id %d has already been sent';

    /**
     * @var string
     */
    private const ERROR_REQUEST_NOT_FOUND =
        'Unable to receive a request with id %d because ' .
        'a request with that identifier was not sent';

    /**
     * @var QueueInterface
     */
    private QueueInterface $queue;

    /**
     * @var array|Deferred[]
     */
    private array $requests = [];

    /**
     * @var LoopInterface
     */
    private LoopInterface $loop;

    /**
     * @param QueueInterface $queue
     */
    public function __construct(QueueInterface $queue, LoopInterface $loop)
    {
        $this->queue = $queue;
        $this->loop = $loop;
    }

    /**
     * @psalm-param SuccessResponseInterface|ErrorResponseInterface $response
     * @param ResponseInterface $response
     */
    public function dispatch(ResponseInterface $response): void
    {
        $deferred = $this->fetch($response->getId());

        if ($response instanceof ErrorResponseInterface) {
            $deferred->reject($response->toException());
        } else {
            $result = $response->getResult();

            $deferred->resolve(\current($result) ?: false);
        }
    }

    /**
     * @param RequestInterface $request
     * @return PromiseInterface
     */
    private function promise(RequestInterface $request): PromiseInterface
    {
        $id = $request->getId();

        if (isset($this->requests[$id])) {
            throw new \OutOfBoundsException(\sprintf(self::ERROR_REQUEST_ID_DUPLICATION, $id));
        }

        $this->requests[$id] = $deferred = new Deferred(function () use ($id) {
            $request = $this->fetch($id);

            $command = $this->queue->pull($id);

            // In the case that the command is in the queue for sending,
            // then we take it out of the queue and cancel the request.
            if ($command !== null) {
                $request->reject(CancellationException::fromRequestId($id));

                // In the case that after the local promise rejection we have
                // nothing to send, then we independently execute the next
                // tick of the event loop.
                if ($this->queue->count() === 0) {
                    $this->loop->tick();
                }

                return;
            }

            // Otherwise, we send a Cancel request to the server to cancel
            // the previously sent command by its ID.
            $this->request(new Cancel([$id]))
                ->then(function () use ($id, $request): void {
                    $request->reject(CancellationException::fromRequestId($id));
                }, function (\Throwable $e) use ($request): void {
                    // In case of an error from the server when canceling the
                    // request, we forward it to the promise and the exception
                    // should occur inside the process generator/coroutine.
                    $request->reject($e);
                });
        });

        return $deferred->promise();
    }

    /**
     * @param int $id
     * @return Deferred
     */
    private function fetch(int $id): Deferred
    {
        if (! isset($this->requests[$id])) {
            throw new \UnderflowException(\sprintf(self::ERROR_REQUEST_NOT_FOUND, $id));
        }

        try {
            return $this->requests[$id];
        } finally {
            unset($this->requests[$id]);
        }
    }

    /**
     * @param RequestInterface $request
     * @return PromiseInterface
     */
    public function request(RequestInterface $request): PromiseInterface
    {
        $this->queue->push($request);

        return $this->promise($request);
    }
}