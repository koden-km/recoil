<?php
namespace Recoil\Coroutine;

use Exception;
use Generator;
use Recoil\Kernel\Strand\StrandInterface;

/**
 * A coroutine wrapper for PHP generators.
 */
class GeneratorCoroutine extends AbstractCoroutine
{
    /**
     * @param Generator $generator The PHP generator that implements the coroutine logic.
     */
    public function __construct(Generator $generator)
    {
        $this->generator = $generator;

        parent::__construct();
    }

    /**
     * Invoked when tick() is called for the first time.
     *
     * @param StrandInterface $strand The strand that is executing the coroutine.
     */
    public function call(StrandInterface $strand)
    {
        try {
            $e = null;
            $valid = $this->generator->valid();
        } catch (Exception $e) {
            $valid = false;
        }

        $this->dispatch($strand, $valid, $e);
    }

    /**
     * Invoked when tick() is called after sendOnNextTick().
     *
     * @param StrandInterface $strand The strand that is executing the coroutine.
     * @param mixed           $value  The value passed to sendOnNextTick().
     */
    public function resumeWithValue(StrandInterface $strand, $value)
    {
        try {
            $e = null;
            $this->generator->send($value);
            $valid = $this->generator->valid();
        } catch (Exception $e) {
            $valid = false;
        }

        $this->dispatch($strand, $valid, $e);
    }

    /**
     * Invoked when tick() is called after throwOnNextTick().
     *
     * @param StrandInterface $strand    The strand that is executing the coroutine.
     * @param Exception       $exception The exception passed to throwOnNextTick().
     */
    public function resumeWithException(StrandInterface $strand, Exception $exception)
    {
        try {
            $e = null;
            $this->generator->throw($exception);
            $valid = $this->generator->valid();
        } catch (Exception $e) {
            $valid = false;
        }

        $this->dispatch($strand, $valid, $e);
    }

    /**
     * Invoked when tick() is called after terminateOnNextTick().
     *
     * @param StrandInterface $strand The strand that is executing the coroutine.
     */
    public function terminate(StrandInterface $strand)
    {
        $this->generator = null;

        $strand->pop();
        $strand->terminate();
    }

    /**
     * Dispatch the value or exception produced by the latest tick of the
     * generator.
     *
     * @param StrandInterface $strand    The strand that is executing the coroutine.
     * @param boolean         $valid     Whether or not the generator is valid.
     * @param Exception|null  $exception The exception thrown during the latest tick, if any.
     */
    protected function dispatch(StrandInterface $strand, $valid, Exception $exception = null)
    {
        if ($exception) {
            $strand->throwException($exception);
        } elseif ($valid) {
            $strand->call(
                $this->generator->current()
            );
        } else {
            $strand->returnValue(null);
        }
    }

    private $generator;
}