<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Defer\Tests\Internal\Functions;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Polidog\Rosanna\Defer\Internal\Functions\deferredNameFromPath;
use function Polidog\Rosanna\Defer\Internal\Functions\deferUrl;
use function Polidog\Rosanna\Defer\Internal\Functions\normalizePrefix;

final class UrlsTest extends TestCase
{
    public function testNormalizePrefixDropsTrailingSlashes(): void
    {
        self::assertSame('/_defer', normalizePrefix('/_defer'));
        self::assertSame('/api/_d', normalizePrefix('/api/_d//'));
    }

    /** @return iterable<string, array{string}> */
    public static function badPrefixes(): iterable
    {
        yield 'empty' => [''];
        yield 'root' => ['/'];
        yield 'only slashes' => ['///'];
        yield 'relative' => ['_defer'];
        yield 'protocol relative' => ['//evil.example'];
    }

    #[DataProvider('badPrefixes')]
    public function testNormalizePrefixRefusesAPrefixThatIsNotAPath(string $prefix): void
    {
        $this->expectException(\InvalidArgumentException::class);
        normalizePrefix($prefix);
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function paths(): iterable
    {
        yield 'name' => ['/_defer/echo', 'echo'];
        yield 'encoded' => ['/_defer/a%20b', 'a b'];
        yield 'encoded slash' => ['/_defer/a%2Fb', 'a/b'];
        yield 'deeper' => ['/_defer/a/b', 'a/b'];
        yield 'prefix itself' => ['/_defer', ''];
        yield 'trailing slash' => ['/_defer/', ''];
        yield 'longer segment' => ['/_deferred/echo', null];
        yield 'other' => ['/page', null];
        yield 'root' => ['/', null];
    }

    /** DF-1 */
    #[DataProvider('paths')]
    public function testDeferredNameFromPath(string $path, ?string $expected): void
    {
        self::assertSame($expected, deferredNameFromPath('/_defer', $path));
    }

    public function testDeferUrlWithoutParams(): void
    {
        self::assertSame('/_defer/panel', deferUrl('/_defer', 'panel', []));
        self::assertSame('/api/_d/panel', deferUrl('/api/_d/', 'panel', null));
    }

    public function testDeferUrlEncodesTheNameAndTheParams(): void
    {
        self::assertSame(
            '/_defer/a%20b?topic=a%26b&page=2&ratio=1.5&on=1&off=0',
            deferUrl('/_defer', 'a b', ['topic' => 'a&b', 'page' => 2, 'ratio' => 1.5, 'on' => true, 'off' => false, 'skip' => null]),
        );
    }

    /** @return iterable<string, array{array<mixed>, string}> */
    public static function badParams(): iterable
    {
        yield 'list' => [['x'], "Deferred component 'panel' params must use non-empty string keys."];
        yield 'empty key' => [['' => 'x'], "Deferred component 'panel' params must use non-empty string keys."];
        yield 'array value' => [['a' => []], "Deferred component 'panel' param 'a' must be scalar; got array"];
        yield 'object value' => [['a' => new \stdClass()], "Deferred component 'panel' param 'a' must be scalar; got stdClass"];
    }

    /** @param array<mixed> $params */
    #[DataProvider('badParams')]
    public function testDeferUrlRefusesParamsThatCannotBeAQuery(array $params, string $message): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($message);
        deferUrl('/_defer', 'panel', $params);
    }
}
