<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Defer\Tests\Internal\Functions;

use PHPUnit\Framework\TestCase;

use function Polidog\Rosanna\Defer\Internal\Functions\nonceAttribute;

final class ScriptsTest extends TestCase
{
    public function testNonceAttribute(): void
    {
        self::assertSame('', nonceAttribute(null));
        self::assertSame(' nonce=""', nonceAttribute(''));
        self::assertSame(' nonce="a&quot;&gt;b"', nonceAttribute('a">b'));
    }
}
