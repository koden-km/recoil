<?php

declare (strict_types = 1); // @codeCoverageIgnore

namespace Recoil\Kernel;

use Throwable;

/**
 * An object that is notified of strand events.
 */
interface StrandObserver
{
    /**
     * A strand completed successfully.
     *
     * @param Strand $strand The strand.
     * @param mixed  $value  The result of the strand's entry point coroutine.
     *
     * @return null
     */
    public function success(Strand $strand, $value);

    /**
     * A strand failed due to an uncaught exception.
     *
     * @param Strand    $strand    The strand.
     * @param Throwable $exception The exception.
     *
     * @return null
     */
    public function failure(Strand $strand, Throwable $exception);

    /**
     * A strand was terminated.
     *
     * @param Strand $strand The strand.
     *
     * @return null
     */
    public function terminated(Strand $strand);
}