<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Defer\Tests\Internal\Functions;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Polidog\Rosanna\Defer\Internal\Functions\deferredProps;
use function Polidog\Rosanna\Defer\Internal\Functions\splitRequestUri;

final class RequestsTest extends TestCase
{
    /** @return iterable<string, array{string, array{string, string}}> */
    public static function uris(): iterable
    {
        yield 'path' => ['/_defer/a', ['/_defer/a', '']];
        yield 'query' => ['/_defer/a?x=1&y=2', ['/_defer/a', 'x=1&y=2']];
        yield 'question mark in the query' => ['/a?x=?', ['/a', 'x=?']];
        yield 'fragment' => ['/a?x=1#top', ['/a', 'x=1']];
        yield 'question mark in the fragment' => ['/a#x?y', ['/a', '']];
        yield 'empty' => ['', ['/', '']];
        yield 'absolute' => ['http://example.com/_defer/a?x=1', ['/_defer/a', 'x=1']];
        yield 'absolute without a path' => ['http://example.com', ['/', '']];
    }

    #[DataProvider('uris')]
    public function testSplitRequestUri(string $uri, array $expected): void
    {
        self::assertSame($expected, splitRequestUri($uri));
    }

    /** RQ-1 `//host/x` はサーバーが見るパスのまま読む */
    public function testADoubleSlashIsAPathNotAnAuthority(): void
    {
        self::assertSame(['/evil.example/_defer/a', 'x=1'], splitRequestUri('//evil.example/_defer/a?x=1'));
    }

    /** DF-4 */
    public function testDeferredPropsDropsKeyAndFallback(): void
    {
        self::assertSame(
            ['topic' => 'a', '0' => 'zero', 'list' => ['x']],
            deferredProps(['key' => 'other', 'topic' => 'a', 'fallback' => 'x', 0 => 'zero', 'list' => ['x']]),
        );
    }
}
