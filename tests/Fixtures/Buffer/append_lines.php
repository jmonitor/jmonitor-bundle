<?php

declare(strict_types=1);

use Jmonitor\JmonitorBundle\Buffer\BufferDirectory;
use Jmonitor\JmonitorBundle\Buffer\FileBuffer;
use Jmonitor\JmonitorBundle\Recording\Entry;
use Symfony\Component\Clock\NativeClock;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

[, $base, $slotSeconds, $prefix, $lines] = $argv;

$buffer = new FileBuffer(new BufferDirectory($base, 'app'), (int) $slotSeconds, new NativeClock());

for ($i = 0; $i < (int) $lines; ++$i) {
    $buffer->append(new Entry(Entry::TYPE_REQUEST, $prefix . '-' . $i, 1000.0, 1.0));
}

exit(0);
