<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Defer\Tests\Internal\Functions;

use PHPUnit\Framework\TestCase;

use function Polidog\Rosanna\Defer\Internal\Functions\escape;
use function Polidog\Rosanna\Defer\Internal\Functions\quote;

final class TextTest extends TestCase
{
    public function testEscapeCoversQuotesAndAmpersands(): void
    {
        self::assertSame('&lt;a href=&quot;x&quot;&gt;&#039;&amp;', escape('<a href="x">\'&'));
    }

    public function testEscapeKeepsTheRestOfTheValueWhenTheUtf8IsInvalid(): void
    {
        self::assertSame("a\u{FFFD}b", escape("a\xFFb"));
    }

    public function testQuoteKeepsSlashesAndUnicodeReadable(): void
    {
        self::assertSame('"a/b/日本"', quote('a/b/日本'));
        self::assertSame('null', quote(null));
        self::assertSame('"a' . "\u{FFFD}" . '"', quote("a\xFF"));
    }
}
