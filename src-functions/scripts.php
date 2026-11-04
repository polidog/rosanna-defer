<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Defer\Internal\Functions;

/*
 * ここの関数は @internal で、公開 API ではない。rosanna シリーズ (polidog/rosanna-*) からは使ってよいが、
 * それ以外からは使わない。互換性はシリーズの中でだけ揃える。
 */

/** @internal nonce がなければ空 */
function nonceAttribute(?string $nonce): string
{
    return $nonce === null ? '' : ' nonce="' . escape($nonce) . '"';
}
