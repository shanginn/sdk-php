<?php

/**
 * This file is part of Temporal package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Temporal\Internal\Support;

use Temporal\Exception\OutOfContextException;

abstract class Facade
{
    /**
     * @var string
     */
    private const ERROR_NO_CONTEXT =
        'Calling facade methods can only be made ' .
        'from the currently running process';

    /** Key of the per-coroutine context slot under TrueAsync. */
    private const CTX_KEY = 'temporal.facade.context';

    private static ?object $ctx = null;

    /**
     * Facade constructor.
     */
    private function __construct()
    {
        // Unable to create new facade instance
    }

    /**
     * @internal
     */
    public static function setCurrentContext(?object $ctx): void
    {
        $storage = self::coroutineStorage();

        if ($storage !== null) {
            $ctx === null
                ? $storage->unset(self::CTX_KEY)
                : $storage->set(self::CTX_KEY, $ctx, true);
            return;
        }

        self::$ctx = $ctx;
    }

    public static function getCurrentContext(): ?object
    {
        $storage = self::coroutineStorage();

        if ($storage !== null) {
            $found = $storage->findLocal(self::CTX_KEY);
            return \is_object($found) ? $found : null;
        }

        return self::$ctx;
    }

    /**
     * @throws OutOfContextException
     */
    public static function getContextId(): int
    {
        $context = static::getCurrentContext();
        if ($context === null) {
            throw new \RuntimeException(self::ERROR_NO_CONTEXT);
        }

        return \spl_object_id($context);
    }

    /**
     * @return mixed
     */
    public static function __callStatic(string $name, array $arguments)
    {
        $context = self::getCurrentContext();

        return $context->$name(...$arguments);
    }

    /**
     * The current coroutine's local context under TrueAsync, or null when no
     * coroutine context is active.
     *
     * Why a static cannot be used under TrueAsync: workflow and activity tasks
     * run in concurrent coroutines of one process, so a process-global "current
     * context" would be clobbered whenever another task is dispatched while
     * this one is parked — e.g. an activity suspended in delay() would lose its
     * context to a workflow activation, breaking Activity::heartbeat() on
     * resume.
     */
    private static function coroutineStorage(): ?\Async\Context
    {
        return \function_exists('Async\coroutine_context')
            ? \Async\coroutine_context()
            : null;
    }
}
