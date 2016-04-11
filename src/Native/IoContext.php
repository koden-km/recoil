<?php

declare (strict_types = 1); // @codeCoverageIgnore

namespace Recoil\Native;
use Recoil\Kernel\Strand;

/**
 * Please note that this code is not part of the public API. It may be
 * changed or removed at any time without notice.
 *
 * @access private
 */
final class IoContext
{
    /**
     * @var int A 'cache' of the strand's ID.
     */
    public $strandId;

    /**
     * @var Strand The strand that is waiting on the IO operations described
     *             by this object.
     */
    public $strand;

    /**
     * @var array<int, resource> The set of streams that are being read in this
     *                 context. Maps descriptor to resource.
     */
    public $readStreams = [];

    /**
     * @var array<int, resource> The set of streams that are being written in
     *                 this context. Maps descriptor to resource.
     */
    public $writeStreams = [];

    /**
     * @var array<resource> The set of streams that are ready to be read.
     */
    public $readyReadStreams = [];

    /**
     * @var array<resource> The set of streams that are write to be read.
     */
    public $readyWriteStreams = [];

    public $isReady = false;

    public $isPersistent = false;

    public $timeout;

    public function __construct(Strand $strand, float $timeout = null)
    {
        $this->strandId = $strand->id();
        $this->strand = $strand;
        $this->timeout = $timeout;
    }

    public function attach(KernelContext $context)
    {
        foreach ($this->readStreams as $descriptor => $stream) {
            $context->readStreams[$descriptor] = $stream;
            $this->readIoContexts[$descriptor][$this->strandId] = $this;
        }

        foreach ($this->writeStreams as $descriptor => $stream) {
            $context->writeStreams[$descriptor] = $stream;
            $this->writeIoContexts[$descriptor][$this->strandId] = $this;
        }
    }

    public function detach(KernelContext $context)
    {
        foreach ($this->readStreams as $descriptor => $stream) {
            $ioContexts = &$context->readIoContexts[$descriptor];
            unset($ioContexts[$this->strandId]);

            if (empty($ioContexts)) {
                unset($context->readStreams[$descriptor]);
            }
        }

        foreach ($this->writeStreams as $descriptor => $stream) {
            $ioContexts = &$context->writeIoContexts[$descriptor];
            unset($ioContexts[$this->strandId]);

            if (empty($ioContexts)) {
                unset($context->writeStreams[$descriptor]);
            }
        }
    }
}
