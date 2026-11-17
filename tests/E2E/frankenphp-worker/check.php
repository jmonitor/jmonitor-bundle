<?php

declare(strict_types=1);

$entries = [];
foreach (glob(sys_get_temp_dir() . '/jmonitor-*-u*/*.ndjson') ?: [] as $file) {
    foreach (file($file, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $entries[] = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
    }
}
usort($entries, static fn(array $a, array $b): int => $a['s'] <=> $b['s']);

$by = static fn(string $key): array => array_values(array_filter($entries, static fn(array $e): bool => $e['k'] === $key));
$show = static function (string $label, array $rows): void {
    foreach ($rows as $e) {
        printf("  %s s=%.3f d=%.1f ms memory=%d\n", $label, $e['s'], $e['d'], $e['a']['memory'] ?? -1);
    }
};
$fail = static function (string $message): never {
    fwrite(\STDERR, "FAIL: $message\n");
    exit(1);
};

$idle = $by('GET app_hello');
$slow = $by('GET app_slow');
$teapot = $by('GET app_teapot');
$alloc = $by('GET app_alloc');

$show('hello ', $idle);
$show('slow  ', $slow);
$show('teapot', $teapot);
$show('alloc ', $alloc);

if (count($idle) < 4) {
    $fail('expected 4 recorded GET app_hello, got ' . count($idle));
}
foreach ($idle as $e) {
    if ($e['d'] >= 1000) {
        $fail(sprintf('duration of a fast request grew with idle time: %.1f ms (limit 1000 ms, pauses were 3.5 s)', $e['d']));
    }
}
$growth = end($idle)['d'] - $idle[0]['d'];
if ($growth >= 500) {
    $fail(sprintf('last fast request is %.1f ms slower than the first', $growth));
}

if ($slow === []) {
    $fail('no GET app_slow recorded');
}
foreach ($slow as $e) {
    if ($e['d'] < 20 || $e['d'] > 500) {
        $fail(sprintf('/slow recorded %.1f ms, expected within [20, 500] ms', $e['d']));
    }
}

if (count($teapot) !== 2 || count($alloc) !== 1) {
    $fail(sprintf('expected 2 teapot and 1 alloc entries, got %d and %d', count($teapot), count($alloc)));
}
$before = $teapot[0]['a']['memory'];
$during = $alloc[0]['a']['memory'];
$after = $teapot[1]['a']['memory'];
if ($during < 32 * 1024 * 1024) {
    $fail("/alloc peak memory is only $during bytes, expected at least 32 MiB");
}
if ($after > $during - 16 * 1024 * 1024) {
    $fail("peak memory was not reset: small route recorded $after bytes after /alloc recorded $during (before: $before)");
}

echo "  checks passed\n";
