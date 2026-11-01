<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Defer;

/**
 * state を持たない defer のコンポーネントを作る。state が要るなら `polidog/rosanna-state` の `fc(defer: ...)` を使う。
 */
function deferred(callable $component, Defer $defer): Deferred
{
    return new Deferred(\Closure::fromCallable($component), $defer);
}
