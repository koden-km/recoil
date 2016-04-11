<?php

declare (strict_types = 1); // @codeCoverageIgnore

namespace Recoil\Native;

use Recoil\Exception\TimeoutException;
use Recoil\Exception\TerminatedException;
use Recoil\Kernel\Api;
use Recoil\Kernel\ApiTrait;
use Recoil\Kernel\Strand;
use Recoil\Kernel\StrandObserver;

final class NativeApi implements Api
{
    public function __construct(KernelContext $context)
    {
        $this->context = $context;
    }

    /**
     * Allow other strands to execute before resuming the calling strand.
     *
     * @param Strand $strand The strand executing the API call.
     */
    public function cooperate(Strand $strand)
    {
        $this->context->resumingStrands[] = $strand;
    }

    /**
     * Suspend the calling strand for a fixed interval.
     *
     * @param Strand $strand  The strand executing the API call.
     * @param float  $seconds The interval to wait.
     */
    public function sleep(Strand $strand, float $seconds)
    {
        if ($seconds <= 0) {
            $this->context->resumingStrands[] = $strand;

            return;
        }

        $strandId = $strand->id();
        $this->context->sleepingStrands[$strandId] = $strand;

        $eventAt = \microtime(true) + $seconds;
        $this->context->eventQueue->insert(
            KernelContext::EVENT_TYPE_WAKE | $strandId,
            -$eventAt
        );

        if (
            $this->context->nextEventAt === null ||
            $eventAt < $this->context->nextEventAt
        ) {
            $this->context->nextEventAt = $eventAt;
        }

        $strand->setTerminator(function () use ($strandId) {
            unset($this->context->sleepingStrands[$strandId]);
        });
    }

    /**
     * Execute a coroutine on a new strand that is terminated after a timeout.
     *
     * If the strand does not exit within the specified time it is terminated
     * and the calling strand is resumed with a {@see TimeoutException}.
     * Otherwise, it is resumed with the value or exception produced by the
     * coroutine.
     *
     * @param Strand $strand    The strand executing the API call.
     * @param float  $seconds   The interval to allow for execution.
     * @param mixed  $coroutine The coroutine to execute.
     *
     * @return Generator
     */
    public function timeout(Strand $strand, float $seconds, $coroutine)
    {
        if ($seconds <= 0) {
            throw new TimeoutException($seconds);
        }

        $kernel = $strand->kernel();
        $worker = $kernel->($coroutine);

        $observer = new class implements StrandObserver
        {
            public $parent;

            public function success(Strand $strand, $value)
            {
                $this->parent->resume($value);
            }

            public function failure(Strand $strand, Throwable $exception)
            {
                $this->parent->throw($exception);
            }

            public function terminated(Strand $strand)
            {
                $this->parent->throw(new TerminatedException($strand));
            }
        };

        $observer->parent = $strand;
        $worker->setObserver($observer);

        $timer = yield Recoil::execute(function () use ($strand, $worker, $seconds) {
            yield $seconds;
            $worker->setObserver(null);
            $worker->terminate();
            $strand->resume(new TimeoutException($seconds));
        });

        $strand->setTerminator()

        try {
            return yield Recoil::suspend();
        } finally {
            $timer->terminate();
        }
    }

    /**
     * Read data from a stream resource, blocking until a specified amount of
     * data is available.
     *
     * Data is buffered until it's length falls between $minLength and
     * $maxLength, or the stream reaches EOF. The calling strand is resumed with
     * a string containing the buffered data.
     *
     * $minLength and $maxLength may be equal to fill a fixed-size buffer.
     *
     * If the stream is already being read by another strand, no data is
     * read until the other strand's operation is complete.
     *
     * Similarly, for the duration of the read, calls to {@see Api::select()}
     * will not indicate that the stream is ready for reading.
     *
     * It is assumed that the stream is already configured as non-blocking.
     *
     * @param Strand   $strand    The strand executing the API call.
     * @param resource $stream    A readable stream resource.
     * @param int      $minLength The minimum number of bytes to read.
     * @param int      $maxLength The maximum number of bytes to read.
     *
     * @return Generator
     */
    public function read(
        Strand $strand,
        $stream,
        int $minLength = PHP_INT_MAX,
        int $maxLength = PHP_INT_MAX
    ) {
        assert(false, 'not implemented');

        assert($minLength >= 1, 'minimum length must be at least one');
        assert($minLength <= $maxLength, 'minimum length must not exceed maximum length');
        $buffer = '';

        $ioContext = new IoContext();
        $ioContext->strand = $strand;
        $ioContext->isPersistent = true;
        $ioContext->waitingReadStreams[(int) $stream] = $stream;
        $ioContext->callback = function ($readable, $writale) use (
            $ioContext,
            $strand,
            $stream,
            &$minLength,
            &$maxLength,
            &$buffer
        ) {
            $chunk = @\fread(
                $stream,
                $maxLength < self::MAX_READ_LENGTH
                    ? $maxLength
                    : self::MAX_READ_LENGTH
            );

            if ($chunk === false) {
                // @codeCoverageIgnoreStart
                $ioContext->detach($this->context);
                $error = \error_get_last();
                $strand->throw(new ErrorException(
                    $error['message'],
                    $error['type'],
                    1, // severity
                    $error['file'],
                    $error['line']
                ));
                // @codeCoverageIgnoreEnd
            } elseif ($chunk === '') {
                $ioContext->detach($this->context);
                $strand->resume($buffer);
            } else {
                $buffer .= $chunk;
                $length = \strlen($chunk);

                if ($length >= $minLength || $length === $maxLength) {
                    $ioContext->detach($this->context);
                    $strand->resume($buffer);
                } else {
                    $minLength -= $length;
                    $maxLength -= $length;
                }
            }
        };

        $strand->setTerminator(function () use ($ioContext) {
            $ioContext->detach($this->context);
        });

        $ioContext->attach($this->context);
    }

    /**
     * Write data to a stream resource, blocking the strand until the entire
     * buffer has been written.
     *
     * Data is written until $length bytes have been written, or the entire
     * buffer has been sent, at which point the calling strand is resumed.
     *
     * If the stream is already being written to by another strand, no data is
     * written until the other strand's operation is complete.
     *
     * Similarly, for the duration of the write, calls to {@see Api::select()}
     * will not indicate that the stream is ready for writing.
     *
     * It is assumed that the stream is already configured as non-blocking.
     *
     * @param Strand   $strand The strand executing the API call.
     * @param resource $stream A writable stream resource.
     * @param string   $buffer The data to write to the stream.
     * @param int      $length The maximum number of bytes to write.
     *
     * @return null
     */
    public function write(
        Strand $strand,
        $stream,
        string $buffer,
        int $length = PHP_INT_MAX
    ) {
        assert(false, 'not implemented');

        // $fd = (int) $stream;
        // $id = $strand->id();
        //
        // $this->context->writeStreams[$fd] = $stream;
        // $this->context->writes[$fd][$id] = [$strand, $buffer, $length]; // TODO use named class
    }

    /**
     * Monitor multiple streams, waiting until one or more becomes "ready" for
     * reading or writing.
     *
     * This operation is directly analogous to {@see stream_select()}, except
     * that it allows other strands to execute while waiting for the streams.
     *
     * A stream is considered ready for reading when a call to {@see fread()}
     * will not block, and likewise ready for writing when {@see fwrite()} will
     * not block.
     *
     * The calling strand is resumed with a 2-tuple containing arrays of the
     * ready streams. This allows the result to be unpacked with {@see list()}.
     *
     * A given stream may be monitored by multiple strands simultaneously, but
     * only one of the strands is resumed when the stream becomes ready. There
     * is no guarantee which strand will be resumed.
     *
     * Any stream that has an in-progress call to {@see Api::read()} or
     * {@see Api::write()} will not be included in the resulting tuple until
     * those operations are complete.
     *
     * If no streams become ready within the specified time, the calling strand
     * is resumed with a {@see TimeoutException}.
     *
     * If no streams are provided, the calling strand is resumed immediately.
     *
     * @param Strand             $strand  The strand executing the API call.
     * @param array<stream>|null $read    Streams monitored until they become "readable" (null = none).
     * @param array<stream>|null $write   Streams monitored until they become "writable" (null = none).
     * @param float|null         $timeout The maximum amount of time to wait, in seconds (null = forever).
     *
     * @return null
     */
    public function select(
        Strand $strand,
        array $read = null,
        array $write = null,
        float $timeout = null
    ) {
        if (empty($read) && empty($write)) {
            $strand->resume([[], []]);

            return;
        }

        $ioContext = new IoContext($strand, $timeout);

        if ($read !== null) {
            foreach ($read as $stream) {
                $ioContext->readStreams[(int) $stream] = $stream;
            }
        }

        if ($write !== null) {
            foreach ($write as $stream) {
                $ioContext->writeStreams[(int) $stream] = $stream;
            }
        }

        $strand->setTerminator(function () use ($ioContext) {
            $ioContext->detach($this->context);
        });

        $ioContext->attach($this->context);

        if ($timeout !== null) {
            $eventAt = \microtime(true) + $timeout;

            $this->context->eventQueue->insert(
                KernelContext::EVENT_TYPE_IO_TIMEOUT | $ioContext->strandId,
                -$eventAt
            );

            if (
                $this->context->nextEventAt === null ||
                $eventAt < $this->context->nextEventAt
            ) {
                $this->context->nextEventAt = $eventAt;
            }
        }
    }

    use ApiTrait;

    const MAX_READ_LENGTH = 32768;
}
