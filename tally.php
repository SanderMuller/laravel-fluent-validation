<?php declare(strict_types=1);

use Tally\Configuration;

// zend.enable_gc=0: otherwise the cycle collector decides when a collection
// falls into the counted call.
return Configuration::create()
    ->withPaths([__DIR__ . '/benchmarks'])
    ->withIniEntries(['memory_limit=-1', 'zend.enable_gc=0']);
