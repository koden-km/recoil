<?php

declare (strict_types = 1); // @codeCoverageIgnore

namespace Recoil\Native;

use Recoil\Kernel\Awaitable;
use Recoil\Kernel\Strand;
use Recoil\Kernel\StrandTrait;

final class NativeStrand implements Strand, Awaitable
{
    use StrandTrait;
}
