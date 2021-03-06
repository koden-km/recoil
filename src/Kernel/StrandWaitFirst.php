<?php

declare(strict_types=1); // @codeCoverageIgnore

namespace Recoil\Kernel;

use Throwable;

/**
 * Implementation of Api::first().
 */
final class StrandWaitFirst implements Awaitable, Listener
{
    public function __construct(Strand ...$substrands)
    {
        $this->substrands = $substrands;
    }

    /**
     * Attach a listener to this object.
     *
     * @param Listener $listener The object to resume when the work is complete.
     * @param Api      $api      The API implementation for the current kernel.
     */
    public function await(Listener $listener, Api $api)
    {
        if ($listener instanceof Strand) {
            $listener->setTerminator([$this, 'cancel']);
        }

        $this->listener = $listener;

        foreach ($this->substrands as $substrand) {
            $substrand->setPrimaryListener($this);
        }
    }

    /**
     * Send the result of a successful operation.
     *
     * @param mixed       $value  The operation result.
     * @param Strand|null $strand The strand that produced this result upon exit, if any.
     */
    public function send($value = null, Strand $strand = null)
    {
        assert($strand instanceof Strand, 'strand cannot be null');
        assert(in_array($strand, $this->substrands, true), 'unknown strand');

        foreach ($this->substrands as $s) {
            if ($s !== $strand) {
                $s->clearPrimaryListener();
                $s->terminate();
            }
        }

        $this->substrands = [];
        $this->listener->send($value, $strand);
    }

    /**
     * Send the result of an unsuccessful operation.
     *
     * @param Throwable   $exception The operation result.
     * @param Strand|null $strand    The strand that produced this exception upon exit, if any.
     */
    public function throw(Throwable $exception, Strand $strand = null)
    {
        assert($strand instanceof Strand, 'strand cannot be null');
        assert(in_array($strand, $this->substrands, true), 'unknown strand');

        foreach ($this->substrands as $s) {
            if ($s !== $strand) {
                $s->clearPrimaryListener();
                $s->terminate();
            }
        }

        $this->substrands = [];
        $this->listener->throw($exception, $strand);
    }

    /**
     * Terminate all remaining strands.
     */
    public function cancel()
    {
        foreach ($this->substrands as $strand) {
            $strand->clearPrimaryListener();
            $strand->terminate();
        }
    }

    /**
     * @var Listener|null The object to notify upon completion.
     */
    private $listener;

    /**
     * @var array<Strand> The strands to wait for.
     */
    private $substrands;
}
