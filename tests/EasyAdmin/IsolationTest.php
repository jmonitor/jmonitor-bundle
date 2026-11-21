<?php

declare(strict_types=1);

namespace Jmonitor\JmonitorBundle\Tests\EasyAdmin;

use PHPUnit\Framework\TestCase;

final class IsolationTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    public function testOnlyTheEasyAdminFilesNameEasyAdminClasses(): void
    {
        $root = realpath(self::ROOT);
        static::assertIsString($root);

        $sources = $this->filesWithout($root . '/src', $root . '/src/EasyAdmin/');
        $configs = $this->filesWithout($root . '/config', $root . '/config/easyadmin.php');
        $templates = $this->filesWithout($root . '/templates', '');
        static::assertNotSame([], $sources);
        static::assertNotSame([], $configs);
        static::assertNotSame([], $templates);

        foreach ([...$sources, ...$configs] as $file) {
            static::assertStringNotContainsString('EasyCorp\\', (string) file_get_contents($file), $file);
        }

        foreach ($templates as $file) {
            static::assertStringNotContainsString('@EasyAdmin', (string) file_get_contents($file), $file);
        }
    }

    /**
     * @return list<string>
     */
    private function filesWithout(string $directory, string $excludedPrefix): array
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            \assert($file instanceof \SplFileInfo);
            if ($file->isFile() && ($excludedPrefix === '' || !str_starts_with($file->getPathname(), $excludedPrefix))) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
