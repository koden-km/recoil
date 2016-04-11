<?php

declare (strict_types = 1); // @codeCoverageIgnore

namespace Recoil\Native;

use SplPriorityQueue;
use Recoil\Exception\TimeoutException;
use Recoil\Kernel\Exception\KernelStoppedException;
use Recoil\Kernel\Exception\StrandException;
use Recoil\Kernel\Kernel;
use Recoil\Kernel\Strand;
use Recoil\Kernel\StrandObserver;
use Throwable;

final class SelectKernel implements Kernel
{
    public function __construct(Api $api = null)
    {
        $this->context = new KernelContext();
        $this->api = $api ?: new NativeApi($this->context);
    }

    /**
     * Execute a coroutine on a new strand.
     *
     * Execution is deferred until control returns to the kernel. This allows
     * the caller to manipulate the returned {@see Strand} object before
     * execution begins.
     *
     * @param mixed $coroutine The strand's entry-point.
     */
    public function execute($coroutine) : Strand
    {
        $strand = new NativeStrand(
            $this,
            $this->api,
            $this->context->nextStrandId++,
            $coroutine
        );

        $this->context->startingStrands[] = $strand;

        return $strand;
    }

    /**
     * Run the kernel until all strands exit or the kernel is stopped.
     *
     * Calls to wait(), {@see Kernel::waitForStrand()} and {@see Kernel::waitFor()}
     * may be nested. This can be useful within synchronous code to block
     * execution until a particular asynchronous operation is complete. Care
     * must be taken to avoid deadlocks.
     *
     * @return bool            False if the kernel was stopped with {@see Kernel::stop()}; otherwise, true.
     * @throws StrandException A strand or strand observer has failure was not handled by the exception handler.
     */
    public function wait() : bool
    {
        if ($this->fatalException) {
            throw $this->fatalException;
        }

        $this->run();

        return $this->isRunning;
    }

    /**
     * Run the kernel until a specific strand exits or the kernel is stopped.
     *
     * Calls to {@see Kernel::wait()}, waitForStrand() and {@see Kernel::waitFor()}
     * may be nested. This can be useful within synchronous code to block
     * execution until a particular asynchronous operation is complete. Care
     * must be taken to avoid deadlocks.
     *
     * @param Strand $strand The strand to wait for.
     *
     * @return mixed                  The strand result, on success.
     * @throws Throwable              The exception thrown by the strand, if failed.
     * @throws TerminatedException    The strand has been terminated.
     * @throws KernelStoppedException Execution was stopped with {@see Kernel::stop()}.
     * @throws StrandException        A strand or strand observer has failure was not handled by the exception handler.
     */
    public function waitForStrand(Strand $strand)
    {
        if ($this->fatalException) {
            throw $this->fatalException;
        }

        $observer = new class implements StrandObserver
        {
            public $value;
            public $exception;

            public function success(Strand $strand, $value)
            {
                $this->value = $value;
            }

            public function failure(Strand $strand, Throwable $exception)
            {
                $this->exception = $exception;
            }

            public function terminated(Strand $strand)
            {
                $this->exception = new TerminatedException($strand);
            }
        };

        $strand->setObserver($observer);

        $this->run($strand);

        if (!$this->isRunning) {
            throw new KernelStoppedException();
        } elseif ($observer->exception) {
            throw $observer->exception;
        }

        assert($strand->hasExited());

        return $observer->value;
    }

    /**
     * Run the kernel until the given coroutine returns or the kernel is stopped.
     *
     * This is a convenience method equivalent to:
     *
     *      $strand = $kernel->execute($coroutine);
     *      $kernel->waitForStrand($strand);
     *
     * Calls to {@see Kernel::wait()}, {@see Kernel::waitForStrand()} and waitFor()
     * may be nested. This can be useful within synchronous code to block
     * execution until a particular asynchronous operation is complete. Care
     * must be taken to avoid deadlocks.
     *
     * @param mixed $coroutine The coroutine to execute.
     *
     * @return mixed                  The return value of the coroutine.
     * @throws Throwable              The exception produced by the coroutine, if any.
     * @throws TerminatedException    The strand has been terminated.
     * @throws KernelStoppedException Execution was stopped with {@see Kernel::stop()}.
     * @throws StrandException        A strand or strand observer has failure was not handled by the exception handler.
     */
    public function waitFor($coroutine)
    {
        return $this->waitForStrand(
            $this->execute($coroutine)
        );
    }

    /**
     * Stop the kernel.
     *
     * All nested calls to {@see Kernel::wait()}, {@see Kernel::waitForStrand()}
     * or {@see Kernel::waitFor()} are stopped.
     *
     * wait() returns false when the kernel is stopped, the other variants throw
     * a {@see KernelStoppedException}.
     *
     * @return null
     */
    public function stop()
    {
        $this->isRunning = false;
    }

    /**
     * Set the exception handler.
     *
     * The exception handler is invoked whenever an exception propagates to the
     * top of a strand's call-stack, or when a strand observer throws an
     * exception.
     *
     * The exception handler function must accept a single parameter of type
     * {@see StrandException} and return a boolean indicating whether or not the
     * exception was handled.
     *
     * If the exception handler returns false, or is not set (the default), the
     * exception will be thrown by the outer-most call to {@see Kernel::wait()},
     * {@see Kernel::waitForStrand()} or {@see Kernel::waitFor()}, after which
     * the kernel may not be restarted.
     *
     * @param callable|null $fn The exception handler (null = remove).
     *
     * @return null
     */
    public function setExceptionHandler(callable $fn = null)
    {
        $this->exceptionHandler = $fn;
    }

    /**
     * Notify the kernel of a strand or strand observer failure.
     *
     * @access private
     *
     * This method is used by the strand implementation and should not be called
     * by the user.
     *
     * @return null
     */
    public function triggerException(StrandException $exception)
    {
        assert(
            $this->fatalException === null,
            'an exception has already been triggered'
        );

        if (
            $this->exceptionHandler &&
            ($this->exceptionHandler)($exception)
        ) {
            return;
        }

        $this->fatalException = $exception;
    }

    private function run(Strand $waitStrand = null)
    {
        // This method intentionally minimises function calls for performance
        // reasons at the expense of readability. It's nasty. Be gentle.

        $this->isRunning = true;

        tick:

        // Process any pending events ...
        if ($this->context->nextEventAt !== null) {
            $this->processEvents();
        }

        // Start any new strands ...
        foreach ($this->context->startingStrands as $index => $strand) {
            unset($this->context->startingStrands[$index]);
            $strand->start();

            if ($this->fatalException) {
                throw $this->fatalException;
            } elseif (!$this->isRunning) {
                return;
            } elseif ($waitStrand !== null && $waitStrand->hasExited()) {
                return;
            }
        }

        // Resume cooperating / waking strands ...
        foreach ($this->context->resumingStrands as $index => $strand) {
            unset($this->context->resumingStrands[$index]);
            $strand->resume();

            if ($this->fatalException) {
                throw $this->fatalException;
            } elseif (!$this->isRunning) {
                return;
            } elseif ($waitStrand !== null && $waitStrand->hasExited()) {
                return;
            }
        }

        // Resume strands with IO operations that have activity ...
        foreach ($this->context->readyIoContexts as $index => $ioContext) {
            unset($this->context->readyIoContexts[$index]);

            $readStreams = $ioContext->readyReadStreams;
            $writeStreams = $ioContext->readyWriteStreams;

            if ($ioContext->isPersistent) {
                $ioContext->isReady = false;
                $ioContext->readyReadStreams = [];
                $ioContext->readyWriteStreams = [];
            } else {
                $ioContext->detach($this->context);
            }

            $ioContext->strand->resume([$readStreams, $writeStreams]);

            if ($this->fatalException) {
                throw $this->fatalException;
            } elseif (!$this->isRunning) {
                return;
            } elseif ($waitStrand !== null && $waitStrand->hasExited()) {
                return;
            }
        }

        // Resume strands with IO operations that have timed out ...
        foreach ($this->context->expiredIoContexts as $index => $ioContext) {
            unset($this->context->expiredIoContexts[$index]);
            $ioContext->detach($this->context);
            $ioContext->strand->throw(new TimeoutException($ioContext->timeout));

            if ($this->fatalException) {
                throw $this->fatalException;
            } elseif (!$this->isRunning) {
                return;
            } elseif ($waitStrand !== null && $waitStrand->hasExited()) {
                return;
            }
        }

        $hasStreams = !empty($this->context->readStreams) ||
                      !empty($this->context->writeStreams);

        // There are immediate actions to perform, do not block for IO ...
        if (
            !empty($this->context->startingStrands) ||
            !empty($this->context->resumingStrands)
        ) {
            $ioTimeout = 0;

        // There are events in the queue, IO may only block until the next one
        // is scheduled to run ...
        } elseif ($this->context->nextEventAt !== null) {
            $delta = $this->context->nextEventAt - \microtime(true);
            $ioTimeout = $delta < 0
                       ? 0
                       : (int) ($delta * self::MICROSECONDS_PER_SECOND);

        // There is *only* IO to perform, block until there is stream activity ...
        } elseif ($hasStreams) {
            $ioTimeout = null;

        // There is nothing left for the kernel to do at all...
        } else {
            return;
        }

        // Wait for IO, and resume any strands that are waiting on streams that
        // have become ready ...
        if ($hasStreams) {
            $this->processIo($ioTimeout);

        // Otherwise, sleep the process until the next event is schedule to run.
        // We do this even if timeout is zero to play nicely with OS process
        // scheduling ...
        } else {
            \usleep($ioTimeout);
            // TODO - handle signals
        }

        goto tick;
    }

    private function processEvents()
    {
        $currentTime = \microtime(true);

        if ($this->context->nextEventAt > $currentTime) {
            return;
        }

        $queueSize = $this->context->eventQueue->count();

        do {
            --$queueSize;
            $event = $this->context->eventQueue->extract()['data'];
            $strandId = $event & ~KernelContext::EVENT_MASK;

            switch ($event & KernelContext::EVENT_MASK) {
                // The event is a stream wake, check if the stream is still
                // sleeping (i.e., it hasn't been terminated), and move it from
                // the sleeping list to the resuming list ...
                case KernelContext::EVENT_TYPE_WAKE:
                    $strand = $this->context->sleepingStrands[$strandId] ?? null;
                    if ($strand !== null) {
                        $this->context->resumingStrands[] = $strand;
                        unset($this->context->sleepingStrands[$strandId]);
                    }
                    break;

                // The event is an IO timeout, check if the IO context is still
                // present (i.e., the strand hasn't been terminated), and move
                // it from the the pending list to the timeout list ...
                case KernelContext::EVENT_TYPE_IO_TIMEOUT:
                    $ioContext = $this->context->ioContexts[$strandId] ?? null;
                    if ($ioContext !== null) {
                        $this->context->expiredIoContexts[] = $ioContext;
                    }

                // @codeCoverageIgnoreStart
                default:
                    assert(false, 'unknown event type');
                // @codeCoverageIgnoreEnd
            }

            // There are no more events, clear the nextEventAt time and bail ...
            if ($queueSize === 0) {
                $this->context->nextEventAt = null;

                return;
            }

            // Update the next event time from the next item in the queue ...
            $this->context->nextEventAt = -$this->context->eventQueue->extract()['priority'];
        } while ($this->context->nextEventAt <= $currentTime);
    }

    private function processIo(int $timeout = null)
    {
        $readStreams = $this->context->readStreams;
        $writeStreams = $this->context->writeStreams;
        $exceptStreams = null;

        if ($timeout === null) {
            $count = @\stream_select(
                $readStreams,
                $writeStreams,
                $exceptStreams,
                null
            );
        } else {
            $count = @\stream_select(
                $readStreams,
                $writeStreams,
                $exceptStreams,
                0,
                $timeout
            );
        }

        // No streams are ready ...
        if ($count === 0) {
            return;

        // The select "failed", this could be a genuine error, or an
        // interrupt from an OS signal ...
        } elseif ($count === false) {
            // TODO - handle signal
            return;
        }

        // For each of the streams that has become readable ...
        foreach ($readStreams as $stream) {
            $descriptor = (int) $stream;

            // Find the first IO context waiting to read this stream ...
            foreach ($this->context->readIoContexts[$descriptor] as $strandId => $ioContext) {
                $ioContext->readyReadStreams[] = $stream;

                // Add it to the ready IO context set, if not already ...
                if (!$ioContext->isReady) {
                    $ioContext->isReady = true;
                    $this->context->readyIoContexts[] = $ioContext;
                }

                break;
            }
        }

        // For each of the streams that has become writable ...
        foreach ($writeStreams as $stream) {
            $descriptor = (int) $stream;

            // Find the first IO context waiting to write this stream ...
            foreach ($this->context->writeIoContexts[$descriptor] as $strandId => $ioContext) {
                $ioContext->readWriteStreams[] = $stream;

                // Add it to the ready IO context set, if not already ...
                if (!$ioContext->isReady) {
                    $ioContext->isReady = true;
                    $this->context->readyIoContexts[] = $ioContext;
                }

                break;
            }
        }
    }

    const MICROSECONDS_PER_SECOND = 1000000;

    /**
     * @var KernelContext
     */
    private $context;

    /**
     * @var Api The kernel API.
     */
    private $api;

    /**
     * @var bool Set to false when stop() is called.
     */
    private $isRunning = false;

    /**
     * @var callable|null The exception handler.
     */
    private $exceptionHandler;

    /**
     * @var StrandException|null The exception passed to triggerException(), if it has not been handled.
     */
    private $fatalException;
}
