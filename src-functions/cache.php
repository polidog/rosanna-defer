<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Defer\Internal\Functions;

/*
 * ここの関数は @internal で、公開 API ではない。rosanna シリーズ (polidog/rosanna-*) からは使ってよいが、
 * それ以外からは使わない。互換性はシリーズの中でだけ揃える。
 */

/**
 * DF-8。`private` か `no-store` を含まないものを共有キャッシュを許すと扱う。フィールド名つきの `private="x"` は含まない
 *
 * @internal
 */
function allowsSharedCache(string $cacheControl): bool
{
    $directives = array_map(trim(...), explode(',', strtolower($cacheControl)));

    return !in_array('private', $directives, true) && !in_array('no-store', $directives, true);
}

/**
 * DF-8。送信予定のヘッダー (headers_list() の形) の Cache-Control のどれかが共有キャッシュを許すか。
 * `session.cache_limiter = public` は `private` のポリシーを持つ応答にも public を送る
 *
 * @internal
 * @param list<string> $headers
 */
function pendingAllowsSharedCache(array $headers): bool
{
    foreach ($headers as $header) {
        if (stripos($header, 'cache-control:') === 0 && allowsSharedCache(substr($header, strlen('cache-control:')))) {
            return true;
        }
    }

    return false;
}
