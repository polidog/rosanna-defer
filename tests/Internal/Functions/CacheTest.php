<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Defer\Tests\Internal\Functions;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Polidog\Rosanna\Defer\Internal\Functions\allowsSharedCache;
use function Polidog\Rosanna\Defer\Internal\Functions\pendingAllowsSharedCache;

/** DF-8 */
final class CacheTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function policies(): iterable
    {
        yield 'public' => ['public, max-age=60', true];
        yield 's-maxage' => ['s-maxage=60', true];
        yield 'empty' => ['', true];
        yield 'private' => ['private, max-age=0', false];
        yield 'no-store' => ['no-store', false];
        yield 'upper case and spaces' => ['  PRIVATE ,max-age=0', false];
        yield 'private with field names' => ['private="Set-Cookie", max-age=60', true];
        yield 'no-cache' => ['no-cache', true];
    }

    #[DataProvider('policies')]
    public function testAllowsSharedCache(string $policy, bool $expected): void
    {
        self::assertSame($expected, allowsSharedCache($policy));
    }

    public function testPendingHeadersAllowSharedCacheWhenAnyCacheControlDoes(): void
    {
        self::assertFalse(pendingAllowsSharedCache([]));
        self::assertFalse(pendingAllowsSharedCache(['Content-Type: text/html', 'X-Cache-Control: public']));
        self::assertFalse(pendingAllowsSharedCache(['Cache-Control: private, no-store']));
        self::assertTrue(pendingAllowsSharedCache(['Cache-Control: private', 'cache-control: public']));
    }
}
