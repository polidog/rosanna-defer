<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Defer\Tests;

use PHPUnit\Framework\TestCase;
use Polidog\Rosanna\Defer\DeferRequest;
use Polidog\Rosanna\Defer\RosannaDefer;
use Polidog\Rosanna\Runtime\PsxArgumentResolver;
use Polidog\Rosanna\Runtime\RenderContext;

/**
 * `.psx` を `rosanna-defer compile` にかけて、状態層なしで動かす。Relayer の使い方に合わせる。
 */
final class PsxIntegrationTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rosanna-defer-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/components', 0o755, true);
        file_put_contents($this->dir . '/components/UserHeader.psx', <<<'PSX'
            <?php
            namespace App;
            use Polidog\Rosanna\Defer\Defer;
            use function Polidog\Rosanna\Defer\deferred;
            return deferred(
                fn (array $props, \ArrayObject $service) => <p>{$props['name']} {$service['label']}</p>,
                new Defer(name: 'user-header', cacheControl: 'private, no-store', localCache: true, localCacheTtl: 60),
            );
            PSX);
        file_put_contents($this->dir . '/components/Page.psx', <<<'PSX'
            <?php
            namespace App;
            return fn (array $props) => <main><UserHeader name="polidog" /><defer name="user-header" /></main>;
            PSX);
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

    /**
     * @param list<string> $arguments
     * @return array{int, string}
     */
    private function bin(string $bin, array $arguments = []): array
    {
        $process = proc_open([PHP_BINARY, $bin, 'compile', ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->dir);
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);

        return [proc_close($process), $output];
    }

    private function deferBin(): string
    {
        return dirname(__DIR__) . '/bin/rosanna-defer';
    }

    private function manifest(): string
    {
        return $this->dir . '/var/cache/psx/manifest.php';
    }

    private function app(): RosannaDefer
    {
        return new RosannaDefer()->loadComponentManifest($this->manifest());
    }

    public function testCompiledComponentsRenderPlaceholdersAndTheEndpointAnswers(): void
    {
        [$code, $output] = $this->bin($this->deferBin());
        self::assertSame(0, $code, $output);

        $app = $this->app()->setArgumentResolver(new class implements PsxArgumentResolver {
            public function resolve(string $fqcn, array $metadata, array $props): array
            {
                return $fqcn === 'App\\UserHeader' ? [new \ArrayObject(['label' => 'from-di'])] : [];
            }
        });

        $page = RenderContext::run($app->scope(), static fn(): string => $app->renderElement($app->scope()->psx->render('App\\Page')));
        self::assertStringContainsString('<div data-rosanna-defer-url="/_defer/user-header?name=polidog" data-rosanna-defer-cache data-rosanna-defer-cache-ttl="60"></div>', $page);
        self::assertStringContainsString('<div data-rosanna-defer-url="/_defer/user-header"></div>', $page);

        $response = $app->handleDeferred(new DeferRequest('GET', '/_defer/user-header', ['name' => 'polidog'], 'name=polidog'));

        self::assertSame(200, $response?->status);
        self::assertSame('<p>polidog from-di</p>', $response->body);
        self::assertSame('private, no-store', $response->headers['Cache-Control']);
    }

    public function testArtifactsFromPlainRosannaCompileAreRefused(): void
    {
        [$code, $output] = $this->bin(dirname(__DIR__) . '/vendor/polidog/rosanna/bin/rosanna');
        self::assertSame(0, $code, $output);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('rosanna-defer compile');
        $this->app();
    }

    public function testCheckReportsAManifestLeftByPlainRosannaCompile(): void
    {
        self::assertSame(0, $this->bin(dirname(__DIR__) . '/vendor/polidog/rosanna/bin/rosanna')[0]);

        self::assertSame(1, $this->bin($this->deferBin(), ['--check'])[0]);
        self::assertSame(0, $this->bin($this->deferBin())[0]);
        self::assertSame(0, $this->bin($this->deferBin(), ['--check'])[0]);
    }

    public function testDuplicateNamesFailTheCompile(): void
    {
        file_put_contents($this->dir . '/components/Other.psx', <<<'PSX'
            <?php
            namespace App;
            use Polidog\Rosanna\Defer\Defer;
            use function Polidog\Rosanna\Defer\deferred;
            return deferred(fn (array $props) => <p />, new Defer('user-header'));
            PSX);

        [$code, $output] = $this->bin($this->deferBin());

        self::assertSame(1, $code);
        self::assertStringContainsString("duplicate deferred component name 'user-header'", $output);
    }
}
