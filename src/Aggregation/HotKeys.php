<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Aggregation;

use Jmonitor\JmonitorBundle\Buffer\BufferDirectory;
use Psr\Clock\ClockInterface;

/**
 * The file holds keys taken from the data, so it lives in the private buffer directory only.
 */
final class HotKeys
{
    private const FILE = 'hot-keys.json';
    private const VERSION = 1;

    /** @var array<string, array{at: int, scores: array<string, float>, kept: array<string, true>}>|null */
    private ?array $families = null;

    public function __construct(
        private readonly BufferDirectory $directory,
        private readonly ClockInterface $clock,
        private readonly int $capacity = 1000,
        private readonly int $halfLifeSeconds = 3600,
    ) {}

    /**
     * @param array<string, float> $weights weight of each key seen since the last call
     *
     * @return array<string, true> the keys of $weights that keep their own row, at most $keep
     */
    public function select(Family $family, array $weights, int $keep): array
    {
        $now = $this->clock->now()->getTimestamp();
        $state = $this->state($family);
        $scores = self::decayed($state['scores'], $now - $state['at'], $this->halfLifeSeconds);
        $kept = $state['kept'];

        foreach ($weights as $key => $weight) {
            if (preg_match('//u', (string) $key) !== 1) {
                continue;
            }

            $scores[$key] = ($scores[$key] ?? 0.0) + (is_finite($weight) ? max(0.0, $weight) : 0.0);
        }

        $ranked = self::ranked($scores);
        foreach (\array_slice($ranked, $this->capacity) as $key) {
            unset($scores[$key], $kept[$key]);
        }
        $ranked = \array_slice($ranked, 0, $this->capacity);

        $keep = max(0, $keep);
        $keptRanked = array_values(array_filter($ranked, static fn(string $key): bool => isset($kept[$key])));
        foreach (\array_slice($keptRanked, $keep) as $key) {
            unset($kept[$key]);
        }
        foreach ($ranked as $key) {
            if (\count($kept) >= $keep) {
                break;
            }
            $kept[$key] = true;
        }

        $outside = array_values(array_filter($ranked, static fn(string $key): bool => !isset($kept[$key])));
        $weakest = array_reverse(array_values(array_filter($ranked, static fn(string $key): bool => isset($kept[$key]))));
        foreach ($outside as $i => $candidate) {
            if (!isset($weakest[$i]) || $scores[$candidate] <= 2 * $scores[$weakest[$i]]) {
                break;
            }
            unset($kept[$weakest[$i]]);
            $kept[$candidate] = true;
        }

        $this->families[$family->value] = ['at' => $now, 'scores' => $scores, 'kept' => $kept];

        $selected = [];
        foreach ($ranked as $key) {
            if (isset($kept[$key]) && \array_key_exists($key, $weights)) {
                $selected[$key] = true;
            }
        }

        return $selected;
    }

    /**
     * @return list<string>
     */
    public function keys(Family $family): array
    {
        return array_map('strval', array_keys($this->state($family)['scores']));
    }

    /**
     * Must be called under the flush lock: the temporary file has a fixed name.
     *
     * @throws \RuntimeException when the register cannot be encoded or written
     */
    public function commit(): void
    {
        if ($this->families === null) {
            return;
        }

        $directory = $this->directory->path();
        if ($directory === null) {
            return;
        }

        $families = [];
        foreach ($this->families as $name => ['at' => $at, 'scores' => $scores, 'kept' => $kept]) {
            $keys = [];
            foreach ($scores as $key => $score) {
                $keys[] = [(string) $key, $score, isset($kept[$key])];
            }
            $families[$name] = ['at' => $at, 'keys' => $keys];
        }

        $json = json_encode(['version' => self::VERSION, 'families' => $families], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION);
        if ($json === false) {
            throw new \RuntimeException('The hot keys register could not be encoded: ' . json_last_error_msg());
        }

        $target = $directory . '/' . self::FILE;
        $temporary = $target . '.tmp';

        $written = BufferDirectory::quietly(static function () use ($temporary, $target, $json): bool {
            $handle = fopen($temporary, 'w');
            if ($handle === false) {
                return false;
            }

            chmod($temporary, 0o600);
            $complete = fwrite($handle, $json) === \strlen($json);
            fclose($handle);

            if ($complete && rename($temporary, $target)) {
                return true;
            }

            unlink($temporary);

            return false;
        });

        if (!$written) {
            throw new \RuntimeException('The hot keys register could not be written to ' . $target);
        }
    }

    public function reset(): void
    {
        $this->families = null;
    }

    /**
     * @return array{at: int, scores: array<string, float>, kept: array<string, true>}
     */
    private function state(Family $family): array
    {
        $this->families ??= $this->load();

        return $this->families[$family->value] ?? ['at' => $this->clock->now()->getTimestamp(), 'scores' => [], 'kept' => []];
    }

    /**
     * @return array<string, array{at: int, scores: array<string, float>, kept: array<string, true>}>
     */
    private function load(): array
    {
        $directory = $this->directory->path();
        if ($directory === null) {
            return [];
        }

        $path = $directory . '/' . self::FILE;
        $content = BufferDirectory::quietly(static fn(): string|false => is_file($path) ? file_get_contents($path) : false);
        if ($content === false) {
            return [];
        }

        $data = json_decode($content, true);
        if (!\is_array($data) || ($data['version'] ?? null) !== self::VERSION || !\is_array($data['families'] ?? null)) {
            return [];
        }

        $families = [];
        foreach ($data['families'] as $name => $family) {
            if (Family::tryFrom((string) $name) === null || !\is_array($family) || !\is_int($family['at'] ?? null) || !\is_array($family['keys'] ?? null)) {
                continue;
            }

            $scores = [];
            $kept = [];
            foreach ($family['keys'] as $entry) {
                if (!\is_array($entry) || !\is_string($entry[0] ?? null) || !\is_float($entry[1] ?? null) || !is_finite($entry[1]) || $entry[1] < 0 || !\is_bool($entry[2] ?? null)) {
                    continue;
                }

                $scores[$entry[0]] = $entry[1];
                if ($entry[2]) {
                    $kept[$entry[0]] = true;
                }
            }

            foreach (\array_slice(self::ranked($scores), $this->capacity) as $key) {
                unset($scores[$key], $kept[$key]);
            }

            $families[(string) $name] = ['at' => $family['at'], 'scores' => $scores, 'kept' => $kept];
        }

        return $families;
    }

    /**
     * @param array<string, float> $scores
     *
     * @return array<string, float>
     */
    private static function decayed(array $scores, int $elapsed, int $halfLife): array
    {
        if ($elapsed <= 0) {
            return $scores;
        }

        $factor = 0.5 ** ($elapsed / max(1, $halfLife));

        return array_map(static fn(float $score): float => $score * $factor, $scores);
    }

    /**
     * @param array<string, float> $scores
     *
     * @return list<string> heaviest first, alphabetical among equal scores
     */
    private static function ranked(array $scores): array
    {
        $keys = array_map('strval', array_keys($scores));
        usort($keys, static fn(string $a, string $b): int => $scores[$b] <=> $scores[$a] ?: strcmp($a, $b));

        return $keys;
    }
}
