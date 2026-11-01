<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Defer;

use Polidog\Rosanna\Runtime\Element;

/**
 * defer の宣言を持つコンポーネント。`rosanna-defer compile` がこれを見て deferred manifest を書く。
 */
interface DeclaresDefer
{
    public function deferDeclaration(): ?Defer;

    /** @param array<string, mixed> $props */
    public function __invoke(array $props = []): Element;
}
