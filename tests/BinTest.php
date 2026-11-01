<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Defer\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Composer の bin のプロキシと同じ形で呼ぶ。path リポジトリの symlink で入れると `__DIR__` は実体のパスになり、
 * パッケージの位置からプロジェクトを推測できない。
 */
final class BinTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rosanna-defer-bin-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/vendor/bin', 0o755, true);
        $bin = var_export(dirname(__DIR__) . '/bin/rosanna-defer', true);
        $autoload = var_export(dirname(__DIR__) . '/vendor/autoload.php', true);
        file_put_contents($this->dir . '/vendor/autoload.php', "<?php\necho \"project-autoload\\n\";\nreturn require {$autoload};\n");
        file_put_contents($this->dir . '/vendor/bin/rosanna-defer', "<?php\n\$GLOBALS['_composer_autoload_path'] = __DIR__ . '/..' . '/autoload.php';\ninclude {$bin};\n");
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            \assert($file instanceof \SplFileInfo);
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->dir);
    }

    /** @return array{int, string} */
    private function runBin(string $command): array
    {
        $process = proc_open([PHP_BINARY, $this->dir . '/vendor/bin/rosanna-defer', $command], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->dir);
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);

        return [proc_close($process), $output];
    }

    public function testPublishUsesTheProjectThatComposerNamesEvenThroughASymlink(): void
    {
        [$code, $output] = $this->runBin('publish');

        self::assertSame(0, $code, $output);
        self::assertFileEquals(dirname(__DIR__) . '/public/rosanna-defer.js', $this->dir . '/public/rosanna-defer.js');
    }

    public function testTheProjectsAutoloaderWinsOverThePackagesOwnVendor(): void
    {
        [$code, $output] = $this->runBin('help');

        self::assertSame(0, $code, $output);
        self::assertStringContainsString('project-autoload', $output);
    }
}
