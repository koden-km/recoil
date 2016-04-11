<?php

declare (strict_types = 1); // @codeCoverageIgnore

namespace Recoil\Native;

use SplPriorityQueue;

/**
 * Please note that this code is not part of the public API. It may be
 * changed or removed at any time without notice.
 *
 * @access private
 *
 * The context object is the mutable state manipulated by the kernel API.
 */
final class KernelContext
{
    const EVENT_SHIFT = 8 * (PHP_INT_SIZE - 1);
    const EVENT_MASK  = 0xff << self::EVENT_SHIFT;

    const EVENT_TYPE_WAKE       = 1 << self::EVENT_SHIFT;
    const EVENT_TYPE_IO_TIMEOUT = 2 << self::EVENT_SHIFT;

    /**
     * @var SplPriorityQueue A priority queue of timed events. The data value
     *                       is an integer, where the most significant byte is
     *                       one of the self::EVENT_TYPE_* constants, and the
     *                       remaining data is the affected strand ID.
     */
    public $eventQueue;

    /**
     * @var float|null The time at which the next event is scheduled.
     */
    public $nextEventAt;

    /**
     * @var int The next strand ID.
     */
    public $nextStrandId = 1;

    /**
     * @var array<Strand> Strands to be started on the next tick of the kernel.
     */
    public $startingStrands = [];

    /**
     * @var array<Strand> Strands that are to be resumed on the next kernel tick.
     */
    public $resumingStrands = [];

    /**
     * @var array<int, Strand> Strands that are sleeping. Maps strand ID to
     *                 strand object.
     */
    public $sleepingStrands = [];

    /**
     * @var array<int, resource> The set of streams that have pending read
     *                 operations across all strands.
     */
    public $readStreams = [];

    /**
     * @var array<int, resource> The set of streams that have pending write
     *                 operations across all strands.
     */
    public $writeStreams = [];

    /**
     * @var array<int, array<int, IoContext>> Pending read operations. Maps
     *                 stream descriptor to a queue of pending IO context objects.
     */
    public $readIoContexts = [];

    /**
     * @var array<int, array<int, IoContext>> Pending write operations. Maps
     *                 stream descriptor to a queue of pending IO context objects.
     */
    public $writeIoContexts = [];

    /**
     * @var array<IoContext> IO operations that have activity on at least one
     *                       stream.
     */
    public $readyIoContexts = [];

    /**
     * @var array<IoContext> IO operations that have timed out.
     */
    public $expiredIoContexts = [];

    public function __construct()
    {
        $this->eventQueue = new SplPriorityQueue();
        $this->eventQueue->setExtractFlags(SplPriorityQueue::EXTR_BOTH);
    }
}
