<?php

declare(strict_types=1);

use Jmonitor\JmonitorBundle\Buffer\BufferDirectory;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

echo (new BufferDirectory((string) getenv('JMONITOR_BUFFER_BASE'), 'app'))->path() ?? 'refused';

exit(0);
