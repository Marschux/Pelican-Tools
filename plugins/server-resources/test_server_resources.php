<?php

/**
 * Self-check for the /proc parsers. Run with: php test_server_resources.php
 */

require __DIR__ . '/src/Host/HostStats.php';
require __DIR__ . '/src/Resources.php';

use Marschux\ServerResources\Host\HostStats;
use Marschux\ServerResources\Resources;

$meminfo = "MemTotal:        4194304 kB\nMemFree:          524288 kB\nMemAvailable:    3145728 kB\n";
assert(HostStats::memory($meminfo) === ['total' => 4194304 * 1024, 'used' => 1048576 * 1024]);
assert(HostStats::memory("MemTotal: 1 kB\n") === null, 'without MemAvailable there is no honest "used"');

$first = HostStats::cpuTimes("cpu  100 0 100 800 0 0 0 0 0 0\ncpu0 50 0 50 400 0 0 0 0 0 0\ncpu1 50 0 50 400 0 0 0 0 0 0\n");
$second = HostStats::cpuTimes("cpu  150 0 150 900 0 0 0 0 0 0\n");
assert($first === ['busy' => 200, 'total' => 1000]);
assert(HostStats::cpuPercent($first, $second) === 50.0, '100 busy of 200 elapsed');
assert(HostStats::cpuPercent($second, $second) === null, 'no time passed, no percentage');
assert(HostStats::cpuTimes('') === null);

assert(HostStats::cpuCount("cpu  1 2 3 4\ncpu0 1 2 3 4\ncpu1 1 2 3 4\nintr 5\n") === 2);
assert(HostStats::uptime("12345.67 23456.78\n") === 12345);
assert(HostStats::load("0.52 0.40 0.31 1/234 5678\n") === [0.52, 0.40, 0.31]);
assert(HostStats::load('') === null);

$order = ['b' => ['9', 'B'], 'a' => ['11', 'A'], 'c' => [null, 'C'], 'd' => ['x1', 'D']];
uasort($order, fn ($a, $b) => Resources::sortKey(...$a) <=> Resources::sortKey(...$b));
assert(array_keys($order) === ['b', 'a', 'd', 'c'], '9 before 11, text after numbers, empty last');

echo "ok\n";
