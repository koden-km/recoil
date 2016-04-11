<?php

declare (strict_types = 1); // @codeCoverageIgnore

namespace Recoil\Native;

describe('Native Implementation', function () {

    \Recoil\importFunctionalTests(
        function () {
            return new SelectKernel();
        }
    );

});
